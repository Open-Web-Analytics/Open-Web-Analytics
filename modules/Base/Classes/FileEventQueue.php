<?php
namespace OWA\Module\Base\Classes;

/**
 * The file-backed tracking intake, and the default one.
 *
 * MIRRORS THE SQS IMPLEMENTATION: a main queue with a dead-letter queue of its
 * own, a receive limit the queue enforces itself (SQS's redrive policy), and
 * one-time replay from the dead-letter queue. Where a file cannot do what SQS
 * does the same way -- hiding a message in place for a delay -- the
 * difference stays inside this class.
 *
 * One difference is deliberate: NO QUEUE RETENTION LIMIT. A beacon not yet
 * ingested is kept, in either queue, until it is ingested, replayed or
 * removed. SQS deletes one after fourteen days at most; a file need not. What
 * removes one is raw_retention_months: the daily replay deletes a dead letter
 * whose event is older than that window (TrackerIngest::replay()), and the
 * daily prune-tracker-ingest-archive deletes archived batches older than it.
 * With the setting unset, both keep everything.
 *
 * Each queue is a directory (the main one is async_log_dir, owa-data/queue/;
 * its dead-letter queue is dead-letter/ inside it), which only the drain
 * writes. The web server writes the tracking queue's INBOX instead, owa-data/
 * logs/incoming-tracking-events/, by names nobody guesses (PrivateFiles):
 * events_<hash>.txt, and delayed_<due>_<hash>.txt for a beacon retried later.
 * The drain copies them in (intake()): the only files it takes from a
 * directory the web server can write. Every directory gets an index.php.
 *
 *   events.txt     what is appended to, in a queue with no inbox (a dead-letter queue)
 *   unprocessed/   batches rotated out of events.txt, oldest name first
 *   processing/    a batch a consumer holds, and its .state sidecar
 *   delayed/       messages sent or released with a delay, one file per due minute
 *   archive/       finished batches, when archive_old_events is on (pruneArchive())
 *
 * A LINE is {"r": times received before, "e": envelope}, plus "p" once it has
 * been replayed from the dead-letter queue, and in a dead-letter queue "why"
 * and "at"; a line that never decoded is kept there as "raw".
 *
 * WRITERS append under flock(LOCK_EX) and, holding it, check that the file
 * they opened is still the one at that path (SafeFile::append()). A consumer rotates by renaming
 * the file and then taking the same lock, which waits out any writer
 * mid-line; a writer that opened the old file and locks after the rename sees
 * a different inode and writes to the new one. No line is lost to a rotation
 * and none is read half-written.
 *
 * VISIBILITY IS A LOCK. A consumer claims a batch by renaming it into
 * processing/ and holding flock(LOCK_EX | LOCK_NB) on it for as long as this
 * object lives, so the visibility argument to receive() is not needed: a
 * consumer that dies releases its lock with its process, and the next one
 * claims the batch again from the last point everything before was settled.
 * The .state sidecar, written as each line is settled, records that point and
 * how many drains have died with the line at it in hand; that line's receive
 * count goes up, and the lines behind it are not charged.
 *
 * No PID file, and no shelling out to ps to check one.
 */
class FileEventQueue implements \OWA\Core\IntakeQueue {

    /** @var string */
    public $queue_name = 'tracker-ingest';

    /** @var string the queue's directory, with a trailing slash */
    public $queue_dir;

    /**
     * Where the web server writes, when it is not the queue's directory: the
     * intake's inbox (owa-data/logs/incoming-tracking-events/). Null for a
     * queue only the drain writes, a dead-letter queue. See intake().
     *
     * @var string|null with a trailing slash
     */
    public $inbox_dir = null;

    /** @var string what send() appends to: in the inbox, when there is one */
    public $event_file;

    /** @var string */
    public $unprocessed_path;

    /** @var string */
    public $processing_path;

    /** @var string */
    public $delayed_path;

    /** @var string */
    public $archive_path;

    /** @var int|null receives before a message is moved to the dead-letter queue; null in a dead-letter queue */
    private $max_receives;

    /** @var bool this is a dead-letter queue */
    private $is_dead_letter;

    /** @var FileEventQueue|null */
    private $dlq;

    /** @var bool provision() has run for this object */
    private $provisioned = false;

    /** @var resource|null the batch this consumer holds */
    private $handle;

    /** @var string|null its path */
    private $batch;

    /**
     * @var array{settled: int, in_hand: int|null, stuck: int, clean: bool, pid: int|false, host: string, held_since: int, remove_failures?: int, remove_error?: string, failed_at?: int}|null its .state, null while no batch is held:
     *   settled     byte offset of the first line not yet settled
     *   in_hand     settled's offset once that line has been handed out, else null
     *   stuck       how many drains died with that line in hand
     *   clean       the last holder stopped in an orderly way
     *   pid, host, held_since   who holds it now, for schedule-status
     */
    private $state;

    /** @var int where this claim resumed: the line a drain that died was on */
    private $resumed_at = 0;

    /** @var array<int,int> offset => end of each message received and not yet settled */
    private $outstanding = [];

    /** @var bool the batch has been read to its end */
    private $at_end = false;

    /**
     * @param array<string, mixed> $map  the queue's registration: queue_name,
     *   path (async_log_dir when empty), inbox, is_dead_letter, max_receives; values as configured, so cast
     */
    public function __construct( $map = [] ) {

        if ( isset( $map['queue_name'] ) ) {

            $this->queue_name = (string) $map['queue_name'];
        }

        $dir = isset( $map['path'] ) && $map['path'] !== ''
            ? $map['path'] : \OWA\Core\CoreAPI::getSetting( 'base', 'async_log_dir' );

        $this->is_dead_letter = ! empty( $map['is_dead_letter'] );
        $this->max_receives   = $this->is_dead_letter ? null
            : max( 1, (int) ( $map['max_receives'] ?? TrackerIngest::MAX_RECEIVES ) );

        $this->queue_dir        = rtrim( (string) $dir, '/' ) . '/';
        $this->inbox_dir        = isset( $map['inbox'] ) && (string) $map['inbox'] !== '' ? rtrim( (string) $map['inbox'], '/' ) . '/' : null;
        $this->event_file       = $this->inbox_dir !== null
            ? $this->inbox_dir . \OWA\Core\PrivateFiles::name( 'events', '.txt' ) : $this->queue_dir . 'events.txt';
        $this->unprocessed_path = $this->queue_dir . 'unprocessed/';
        $this->processing_path  = $this->queue_dir . 'processing/';
        $this->delayed_path     = $this->queue_dir . 'delayed/';
        $this->archive_path     = $this->queue_dir . 'archive/';
    }

    public function __destruct() {

        $this->letGo();
    }

    // ---------------------------------------------------------------------
    // The contract
    // ---------------------------------------------------------------------

    /**
     * The queue's directories: its own, which only the drain writes (0755),
     * and the inbox, which the web server writes too (provisionInbox()). A
     * link at any of them is refused: the drain renames and writes inside
     * them, and through a link would do it wherever the link points.
     */
    public function provision() {

        if ( $this->inbox_dir !== null && ! $this->provisionInbox() ) {

            return false;
        }

        foreach ( [ $this->queue_dir, $this->unprocessed_path, $this->processing_path,
                         $this->delayed_path, $this->archive_path ] as $d ) {

            $made = $this->inbox_dir !== null || $this->is_dead_letter
                ? \OWA\Core\SafeFile::makeDirs( $d, 0755 )
                : \OWA\Core\Lib::makeSharedDir( $d );

            if ( is_link( rtrim( $d, '/' ) ) || ! $made ) {

                \OWA\Core\CoreAPI::notice( "Tracker ingest: cannot make queue directory $d, or it is a link." );

                return false;
            }

            \OWA\Core\PrivateFiles::guard( $d );
        }

        $this->provisioned = true;

        $dlq = $this->deadLetterQueue();

        return $dlq ? $dlq->provision() : true;
    }

    /**
     * The inbox alone: all the web server makes. owa-data/logs/ is the one
     * directory it writes; the queue's own directory is the drain's.
     *
     * @return bool
     */
    private function provisionInbox() {

        if ( is_link( rtrim( (string) $this->inbox_dir, '/' ) ) || ! \OWA\Core\Lib::makeSharedDir( (string) $this->inbox_dir ) ) {

            \OWA\Core\CoreAPI::notice( "Tracker ingest: cannot make the inbox {$this->inbox_dir}, or it is a link." );

            return false;
        }

        \OWA\Core\PrivateFiles::guard( (string) $this->inbox_dir );

        return true;
    }

    public function send( array $envelope, $delay = 0, $replayed = false ) {

        $row = [ 'r' => 0, 'e' => $envelope ];

        if ( $replayed ) {

            $row['p'] = 1;
        }

        return $this->appendRow( $this->fileFor( $delay, $this->inbox_dir !== null ), $row );
    }

    public function receive( $max, $visibility = 0 ) {

        if ( ! $this->provisioned && ! $this->provision() ) {

            return [];
        }

        $max      = max( 1, (int) $max );
        $messages = [];

        $this->intake();
        $this->promoteDue();
        $this->rotate();

        while ( count( $messages ) < $max ) {

            if ( ! $this->handle && ! $this->claimNext() ) {

                break;
            }

            $offset = ftell( $this->handle );
            $line   = fgets( $this->handle );

            if ( $line === false ) {

                $this->at_end = true;

                if ( $this->outstanding ) {

                    // The rest of this batch is in someone's hands: settle first.
                    break;
                }

                $this->finishBatch();

                continue;
            }

            $end = ftell( $this->handle );

            if ( trim( $line ) === '' ) {

                continue;
            }

            $row = self::decodeRow( $line );

            // The line a drain died on is received once more for each drain that died on it.
            $count = (int) $row['r'] + 1 + ( $offset === $this->resumed_at ? $this->state['stuck'] : 0 );

            $this->outstanding[ $offset ] = $end;

            $message = new \OWA\Core\IntakeMessage(
                [ 'batch' => $this->batch, 'offset' => $offset, 'end' => $end ],
                $row['e'], $count, isset( $row['raw'] ) ? (string) $row['raw'] : rtrim( $line, "\n" ), ! empty( $row['p'] ),
                $row['why'] ?? null, $row['at'] ?? null );

            // The redrive policy: past the limit it goes to the dead-letter queue, not to a consumer.
            if ( $this->max_receives !== null && $count > $this->max_receives ) {

                $this->deadLetter( $message, sprintf( 'Received %d times without being ingested.', $count - 1 ) );

                continue;
            }

            // Recorded before it leaves, so a drain that dies on it is charged to it.
            if ( $offset === $this->state['settled'] && $this->state['in_hand'] !== $offset ) {

                $this->state['in_hand'] = $offset;
                $this->writeState();
            }

            $messages[] = $message;
        }

        return $messages;
    }

    public function ack( \OWA\Core\IntakeMessage $message ) {

        return $this->settle( $message );
    }

    public function release( \OWA\Core\IntakeMessage $message, $delay ) {

        $row = $this->rowOf( $message );

        // The drain's own write: into the queue's directory, never the inbox.
        if ( $row === null || ! $this->appendRow( $this->fileFor( $delay, false ), $row ) ) {

            // Not written anywhere: leave it outstanding so it is delivered again.
            return false;
        }

        return $this->settle( $message );
    }

    public function deadLetter( \OWA\Core\IntakeMessage $message, $reason ) {

        $dlq = $this->deadLetterQueue();

        if ( ! $dlq ) {

            // A dead-letter queue has none: what is in one stays until replayed or removed.
            return false;
        }

        $row = $message->envelope !== null
            ? [ 'r' => $message->receive_count, 'e' => $message->envelope ]
            : [ 'r' => $message->receive_count, 'raw' => $message->raw ];

        if ( $message->replayed ) {

            $row['p'] = 1;
        }

        $row['why'] = (string) $reason;
        $row['at']  = time();

        if ( ! $dlq->appendRow( $dlq->event_file, $row ) ) {

            return false;
        }

        return $this->settle( $message );
    }

    /** @return self|null */
    public function deadLetterQueue() {

        if ( $this->is_dead_letter ) {

            return null;
        }

        if ( ! $this->dlq ) {

            $this->dlq = new self( [
                'path'           => $this->queue_dir . 'dead-letter/',
                'queue_name'     => $this->queue_name . '-dlq',
                'is_dead_letter' => true,
            ] );
        }

        return $this->dlq;
    }

    public function isProbablyEmpty() {

        clearstatcache();

        if ( is_file( $this->event_file ) && filesize( $this->event_file ) > 0 ) {

            return false;
        }

        if ( self::files( $this->unprocessed_path, '.txt' ) || self::files( $this->processing_path, '.txt' ) ) {

            return false;
        }

        foreach ( self::files( $this->delayed_path, '.txt' ) as $name ) {

            if ( (int) $name <= time() ) {

                return false;
            }
        }

        return true;
    }

    public function stats() {

        clearstatcache();

        $oldest = null;

        foreach ( $this->waitingFiles() as $path ) {

            // A retry waiting out its back-off is not a backlog until it is due.
            if ( strpos( $path, $this->delayed_path ) === 0 && (int) basename( $path ) > time() ) {

                continue;
            }

            $m = @filemtime( $path );

            if ( $m !== false && filesize( $path ) > 0 ) {

                $oldest = $oldest === null ? $m : min( $oldest, $m );
            }
        }

        return [
            'messages'   => $this->depth(),
            // Of what is due. A batch's mtime is its last line's: an underestimate for its first.
            'oldest_age' => $oldest === null ? null : max( 0, time() - $oldest ),
        ];
    }

    // ---------------------------------------------------------------------
    // Housekeeping
    // ---------------------------------------------------------------------

    /**
     * Delete archived batches, this queue's and its dead-letter queue's,
     * older than $interval seconds.
     *
     * @param  int $interval
     * @return int how many files
     */
    public function pruneArchive( $interval ) {

        $removed = 0;

        foreach ( self::files( $this->archive_path, '.txt' ) as $name ) {

            $path = $this->archive_path . $name;

            if ( filemtime( $path ) < time() - (int) $interval && @unlink( $path ) ) {

                $removed++;
            }
        }

        $dlq = $this->deadLetterQueue();

        return $removed + ( $dlq ? $dlq->pruneArchive( $interval ) : 0 );
    }

    /**
     * Lines waiting: events.txt, the batches and the delayed files. Reads
     * every file, so for diagnostics and tests, not a hot path.
     *
     * @return int
     */
    public function depth() {

        $n = 0;

        foreach ( $this->waitingFiles() as $path ) {

            $n += count( array_filter( (array) @file( $path ), fn ( $l ) => trim( $l ) !== '' ) );
        }

        return $n;
    }

    /**
     * Batches a drain holds now, for schedule-status: what it is, who holds
     * it and since when. A batch whose drain died is not held -- the next
     * drain takes it -- so what this lists is a drain that is running, or
     * one that is hung.
     *
     * Asked of the lock itself, not the .state, so a stale .state is not
     * mistaken for a holder.
     *
     * @return list<array{batch: string, pid: int|null, host: string, held_since: int|null, settled: int}>
     */
    public function heldBatches() {

        $held = [];

        foreach ( self::files( $this->processing_path, '.txt' ) as $name ) {

            $path = $this->processing_path . $name;

            if ( $path === $this->batch ) {

                continue;
            }

            $fh = @fopen( $path, 'r' );

            if ( ! $fh ) {

                continue;
            }

            if ( flock( $fh, LOCK_EX | LOCK_NB ) ) {

                flock( $fh, LOCK_UN );
                fclose( $fh );

                continue;
            }

            fclose( $fh );

            $state = json_decode( (string) @file_get_contents( $path . '.state' ), true );
            $state = is_array( $state ) ? $state : [];

            $held[] = [
                'batch'      => $name,
                'pid'        => isset( $state['pid'] ) ? (int) $state['pid'] : null,
                'host'       => (string) ( $state['host'] ?? '' ),
                'held_since' => isset( $state['held_since'] ) ? (int) $state['held_since'] : null,
                'settled'    => (int) ( $state['settled'] ?? 0 ),
            ];
        }

        return $held;
    }

    // ---------------------------------------------------------------------
    // Lines
    // ---------------------------------------------------------------------

    /**
     * @param  string $line
     * @return array<string, mixed> r (int), e (array|null), and p, why, at, raw when present, as decoded
     */
    private static function decodeRow( $line ) {

        $row = json_decode( $line, true );

        if ( ! is_array( $row ) || ! ( ( isset( $row['e'] ) && is_array( $row['e'] ) ) || isset( $row['raw'] ) ) ) {

            // Not a line this queue wrote: half-written, or a 1.x serialized event.
            return [ 'r' => 0, 'e' => null ];
        }

        $row['r'] = max( 0, (int) ( $row['r'] ?? 0 ) );
        $row['e'] = isset( $row['e'] ) && is_array( $row['e'] ) ? $row['e'] : null;

        return $row;
    }

    /**
     * What release() re-queues: the message as it was, its count kept.
     *
     * @return array<string, mixed>|null r, e or raw, and p, why, at when set; null for a message with neither
     */
    private function rowOf( \OWA\Core\IntakeMessage $message ) {

        if ( $message->envelope !== null ) {

            $row = [ 'r' => $message->receive_count, 'e' => $message->envelope ];

        } elseif ( $message->raw !== '' ) {

            // A dead letter that never decoded, kept as it was.
            $row = [ 'r' => $message->receive_count, 'raw' => $message->raw ];

        } else {

            return null;
        }

        if ( $message->replayed ) {

            $row['p'] = 1;
        }

        foreach ( [ 'why' => $message->reason, 'at' => $message->dead_at ] as $k => $v ) {

            if ( $v !== null ) {

                $row[ $k ] = $v;
            }
        }

        return $row;
    }

    /**
     * Append one row; the directory is made on first use, as provision() would.
     *
     * @param  string               $path
     * @param  array<string, mixed> $row  r, e or raw, and p, why, at when set
     * @return bool
     */
    private function appendRow( $path, array $row ) {

        $line = json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        if ( $line === false ) {

            return false;
        }

        // A line for the inbox needs the inbox only: the web server makes no more.
        if ( ! is_dir( dirname( $path ) ) ) {

            $ready = $this->inbox_dir !== null && dirname( $path ) . '/' === $this->inbox_dir
                ? $this->provisionInbox() : $this->provision();

            if ( ! $ready ) {

                return false;
            }
        }

        return $this->append( $path, $line );
    }

    /**
     * Append one line to $path: SafeFile::append(), which checks under its
     * lock that the file is still the one at $path, so a rotation renaming
     * it loses no line. Group-writable (0664), as the directories are: the
     * web server and the CLI both append to events.txt.
     *
     * @param  string $path
     * @param  string $line  without its newline
     * @return bool
     */
    private function append( $path, $line ) {

        if ( \OWA\Core\SafeFile::append( $path, $line . "\n", 0664 ) ) {

            return true;
        }

        \OWA\Core\CoreAPI::notice( "Tracker ingest: cannot write $path." );

        return false;
    }

    /**
     * Where a line due in $delay seconds goes: the events file when it is due
     * now, else the delayed file of the minute it falls due in. In the inbox
     * (the web server's) or the queue's own directory (the drain's); the drain
     * has no events file of its own when there is an inbox, so a line due now
     * goes to delayed/, due this minute.
     *
     * @param  int  $delay seconds
     * @param  bool $inbox
     * @return string
     */
    private function fileFor( $delay, $inbox ) {

        $delay = (int) $delay;

        if ( $inbox ) {

            return $delay > 0 ? $this->inboxDelayedFile( time() + $delay ) : $this->event_file;
        }

        if ( $delay > 0 || $this->inbox_dir !== null ) {

            return $this->delayedFile( time() + max( 0, $delay ) );
        }

        return $this->event_file;
    }

    /**
     * The inbox's delayed file for a due time: delayed_<due>_<hash>.txt, a
     * name nobody guesses (PrivateFiles::name()).
     *
     * @param  int $due  a Unix time
     * @return string
     */
    private function inboxDelayedFile( $due ) {

        return $this->inbox_dir . \OWA\Core\PrivateFiles::name( 'delayed_' . (string) ( (int) ceil( $due / 60 ) * 60 ), '.txt' );
    }

    /**
     * The delayed file for a due time: one per minute, named for when all of it is due.
     *
     * @param  int $due  a Unix time
     * @return string
     */
    private function delayedFile( $due ) {

        return $this->delayed_path . (string) ( (int) ceil( $due / 60 ) * 60 ) . '.txt';
    }

    /** @return string[] every file holding waiting lines */
    private function waitingFiles() {

        $inbox = $this->inbox_dir === null ? [] : array_map( fn ( $f ) => $this->inbox_dir . $f,
            array_values( array_filter( self::files( $this->inbox_dir, '.txt' ), fn ( $f ) => strpos( $f, 'delayed_' ) === 0 ) ) );

        return array_values( array_filter( array_merge(
            [ $this->event_file ],
            $inbox,
            array_map( fn ( $f ) => $this->unprocessed_path . $f, self::files( $this->unprocessed_path, '.txt' ) ),
            array_map( fn ( $f ) => $this->processing_path . $f, self::files( $this->processing_path, '.txt' ) ),
            array_map( fn ( $f ) => $this->delayed_path . $f, self::files( $this->delayed_path, '.txt' ) )
        ), 'is_file' ) );
    }

    // ---------------------------------------------------------------------
    // Batches
    // ---------------------------------------------------------------------

    /**
     * Move events.txt into unprocessed/, when it holds anything.
     *
     * @return void
     */
    private function rotate() {

        // The inbox's events file is taken in by intake(), checked and copied; never renamed in.
        if ( $this->inbox_dir !== null ) {

            return;
        }

        clearstatcache( true, $this->event_file );

        if ( is_file( $this->event_file ) && filesize( $this->event_file ) > 0 ) {

            $this->moveIntoBatches( $this->event_file, 'events' );
        }
    }

    /**
     * Take the inbox's files into the queue's own directory: the one point
     * where the drain touches what the web server wrote.
     *
     * Only the names the queue gives (the events file, delayed files), each
     * opened and checked to be a plain file with this one name -- not a
     * link, not a hard link to another file, not a FIFO (SafeFile::isNamed())
     * -- under the lock its writers append under. Its contents are COPIED
     * into a new file of the drain's, never renamed: renamed, the file would
     * still be the web server's to write. The inbox file is then removed,
     * still under the lock, so a writer that had it open writes to a new one.
     *
     * Nothing is taken from an inbox the drain cannot remove files from:
     * copied and left, a file would be copied again on every drain.
     *
     * @return void
     */
    private function intake() {

        if ( $this->inbox_dir === null || ! is_dir( $this->inbox_dir ) ) {

            return;
        }

        if ( ! is_writable( $this->inbox_dir ) ) {

            \OWA\Core\CoreAPI::error( sprintf( 'Tracker ingest: %s cannot be written by this user, so its beacons cannot be taken in. '
                . 'Give it the group of owa-data/logs/ and group write (cmd=install sets it).', $this->inbox_dir ) );

            return;
        }

        $events = basename( $this->event_file );
        $hash   = \OWA\Core\CoreAPI::generateInstanceSpecificHash();

        foreach ( self::files( $this->inbox_dir, '.txt' ) as $name ) {

            if ( $name === $events ) {

                $this->takeIn( $this->inbox_dir . $name, null );

            } elseif ( preg_match( '/^delayed_(\d+)_' . preg_quote( $hash, '/' ) . '\.txt$/', $name, $m ) ) {

                $this->takeIn( $this->inbox_dir . $name, (int) $m[1] );
            }
        }
    }

    /**
     * One inbox file, copied into a new batch (unprocessed/) or, when it is
     * one due at $due, into delayed/; then removed. See intake().
     *
     * @param  string   $path
     * @param  int|null $due
     * @return void
     */
    private function takeIn( $path, $due ) {

        if ( is_link( $path ) || ! is_file( $path ) ) {

            return;
        }

        $h = @fopen( $path, 'rb' );

        if ( ! $h ) {

            return;
        }

        flock( $h, LOCK_EX );

        try {

            if ( ! \OWA\Core\SafeFile::isNamed( $h, $path ) ) {

                \OWA\Core\CoreAPI::error( sprintf( 'Tracker ingest: %s is not a plain file of its own; it is left where it is.', $path ) );

                return;
            }

            $data = (string) stream_get_contents( $h );

            if ( $data !== '' ) {

                if ( $due === null ) {

                    \OWA\Core\SafeFile::replace( sprintf( '%s%s-events-%s-%s.txt', $this->unprocessed_path,
                        date( 'YmdHis' ), getmypid(), bin2hex( random_bytes( 3 ) ) ), $data, 0640 );

                } elseif ( ! \OWA\Core\SafeFile::append( $this->delayedFile( $due ), $data, 0640 ) ) {

                    throw new \RuntimeException( 'Could not write ' . $this->delayedFile( $due ) . '.' );
                }
            }

            @unlink( $path );

        } catch ( \RuntimeException $e ) {

            // Left in the inbox: taken in on the next drain.
            \OWA\Core\CoreAPI::error( 'Tracker ingest: ' . $e->getMessage() );

        } finally {

            flock( $h, LOCK_UN );
            fclose( $h );
        }
    }

    /**
     * Move delayed files that are due into unprocessed/.
     *
     * @return void
     */
    private function promoteDue() {

        foreach ( self::files( $this->delayed_path, '.txt' ) as $name ) {

            if ( (int) $name <= time() ) {

                $this->moveIntoBatches( $this->delayed_path . $name, 'delayed-' . (int) $name );
            }
        }
    }

    /**
     * Rename a written-to file into unprocessed/, then wait out any writer
     * still holding its lock.
     *
     * @param  string $path
     * @param  string $label  in the batch's name: where it came from
     * @return void
     */
    private function moveIntoBatches( $path, $label ) {

        $target = sprintf( '%s%s-%s-%s-%s.txt', $this->unprocessed_path,
            date( 'YmdHis' ), $label, getmypid(), bin2hex( random_bytes( 3 ) ) );

        if ( ! @rename( $path, $target ) ) {

            return;
        }

        $fh = @fopen( $target, 'r' );

        if ( $fh ) {

            flock( $fh, LOCK_EX );
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    /**
     * Hold the next batch: one a dead consumer left in processing/, else the
     * oldest in unprocessed/.
     *
     * @return bool
     */
    private function claimNext() {

        foreach ( self::files( $this->processing_path, '.txt' ) as $name ) {

            if ( isset( $this->unremovable[ $this->processing_path . $name ] ) ) {

                continue;
            }

            if ( $this->hold( $this->processing_path . $name ) ) {

                return true;
            }
        }

        foreach ( self::files( $this->unprocessed_path, '.txt' ) as $name ) {

            $target = $this->processing_path . $name;

            if ( @rename( $this->unprocessed_path . $name, $target ) && $this->hold( $target ) ) {

                return true;
            }
        }

        return false;
    }

    /**
     * Lock a batch in processing/ and resume it where it was last settled.
     *
     * @param  string $path
     * @return bool whether this consumer now holds it
     */
    private function hold( $path ) {

        $fh = @fopen( $path, 'r' );

        if ( ! $fh ) {

            return false;
        }

        if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {

            fclose( $fh );

            return false;
        }

        $state = is_file( $path . '.state' ) ? json_decode( (string) file_get_contents( $path . '.state' ), true ) : null;
        $state = is_array( $state ) ? $state : [];

        /*
         * A .state not marked clean belonged to a drain that died. It is
         * charged to the line at the settled offset only if that line was in
         * its hands: a drain ingests in order, records each line as it is
         * settled, and records the next one as it hands it out. A drain that
         * died before reaching the line -- just after taking the batch, or
         * between receives -- charges nothing. A line that kills every drain
         * reaches the receive limit and is moved to the dead-letter queue
         * without being run; the lines behind it are not charged for it.
         */
        $settled = (int) ( $state['settled'] ?? 0 );
        $died    = $state && empty( $state['clean'] )
            && isset( $state['in_hand'] ) && (int) $state['in_hand'] === $settled;

        $this->handle      = $fh;
        $this->batch       = $path;
        $this->outstanding = [];
        $this->at_end      = false;
        $this->state       = [
            'settled'    => $settled,
            'in_hand'    => null,
            'stuck'      => (int) ( $state['stuck'] ?? 0 ) + ( $died ? 1 : 0 ),
            'clean'      => false,
            'pid'        => getmypid(),
            'host'       => (string) gethostname(),
            'held_since' => time(),
        ];

        // The failed removals of a batch drained before (finishBatch()), still counting.
        if ( ! empty( $state['remove_failures'] ) ) {

            $this->state['remove_failures'] = (int) $state['remove_failures'];
            $this->state['remove_error']    = (string) ( $state['remove_error'] ?? '' );
        }
        $this->resumed_at  = $this->state['settled'];

        fseek( $fh, $this->state['settled'] );
        $this->writeState();

        return true;
    }

    /**
     * One message is dealt with; a batch with nothing left is finished.
     *
     * @return bool false for a message not outstanding in the batch held
     */
    private function settle( \OWA\Core\IntakeMessage $message ) {

        $r = (array) $message->receipt;

        if ( ( $r['batch'] ?? null ) !== $this->batch || ! isset( $this->outstanding[ $r['offset'] ] ) ) {

            return false;
        }

        unset( $this->outstanding[ $r['offset'] ] );

        $settled = $this->outstanding
            ? min( array_keys( $this->outstanding ) )
            : (int) ftell( $this->handle );

        if ( $settled !== $this->state['settled'] ) {

            $this->state['settled'] = $settled;
            $this->state['stuck']   = 0;
        }

        // The next line is already out when a receive handed out several.
        $this->state['in_hand'] = isset( $this->outstanding[ $settled ] ) ? $settled : null;

        // Every line, so a drain that dies loses at most the one it was on.
        $this->writeState();

        if ( $this->at_end && ! $this->outstanding ) {

            $this->finishBatch();
        }

        return true;
    }

    /** What a batch set aside is renamed with: no longer a .txt, so no drain takes it. */
    const FAILED_SUFFIX = '.failed';

    /**
     * Batches this consumer drained and could neither remove nor set aside:
     * not taken again by it (claimNext()), which would find each at its end
     * again at once. The next drain tries once more.
     *
     * @var array<string, true>
     */
    private $unremovable = [];

    /**
     * Every line of the batch is settled: archive or delete it.
     *
     * Every event in it is already ingested, so a batch that cannot be
     * removed is not dead-lettered: what failed is the housekeeping. It is
     * renamed .failed where it is, at once -- a rename within processing/
     * works whenever archive/ is what refused -- out of every drain's way,
     * with its state beside it saying why, and System Health names it
     * (failedBatches()). When processing/ itself refuses, it cannot be set
     * aside either: its state, read to the end, keeps any drain from reading
     * it again, and each drain tries once more.
     *
     * @return void
     */
    private function finishBatch() {

        $path = $this->batch;

        flock( $this->handle, LOCK_UN );
        fclose( $this->handle );

        $this->handle = null;
        $this->batch  = null;

        $archive = (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'archive_old_events' );
        $verb    = $archive ? 'archived' : 'deleted';

        error_clear_last();

        $gone = $archive ? @rename( $path, $this->archive_path . basename( $path ) ) : @unlink( $path );

        if ( $gone ) {

            @unlink( $path . '.state' );

            return;
        }

        $reason = (string) ( error_get_last()['message'] ?? 'no reason given' );

        $this->state['remove_failures'] = (int) ( $this->state['remove_failures'] ?? 0 ) + 1;
        $this->state['remove_error']    = $reason;
        $this->state['failed_at']       = time();
        $this->state['clean']           = true;

        try {

            \OWA\Core\SafeFile::replace( $path . '.state', (string) json_encode( $this->state ), 0664 );

        } catch ( \RuntimeException $e ) {

            // processing/ refuses writes: the rename below fails too, and the batch is tried again.
        }

        if ( @rename( $path, $path . self::FAILED_SUFFIX ) ) {

            @rename( $path . '.state', $path . self::FAILED_SUFFIX . '.state' );

            \OWA\Core\CoreAPI::error( sprintf( 'Event queue: %1$s could not be %2$s (%3$s), and is set aside as %4$s. '
                . 'Its events are ingested; fix the cause and delete it.', $path, $verb, $reason, basename( $path ) . self::FAILED_SUFFIX ) );

            return;
        }

        $this->unremovable[ $path ] = true;

        \OWA\Core\CoreAPI::error( sprintf( 'Event queue: %1$s was drained but could not be %2$s or set aside (%3$s); '
            . 'it is tried again on the next drain.', $path, $verb, $reason ) );
    }

    /**
     * Drained batches that could not be archived or deleted: set aside as
     * .failed, or, where even that failed, still in processing/. For System
     * Health.
     *
     * @return list<array{batch: string, attempts: int, reason: string, failed_at: int|null, set_aside: bool}>
     */
    public function failedBatches() {

        $out = [];

        foreach ( (array) glob( $this->processing_path . '*.state' ) as $state_path ) {

            $state = json_decode( (string) @file_get_contents( $state_path ), true );

            if ( ! is_array( $state ) || empty( $state['remove_failures'] ) ) {

                continue;
            }

            $batch = substr( basename( $state_path ), 0, -strlen( '.state' ) );

            $out[] = [
                'batch'     => $batch,
                'attempts'  => (int) $state['remove_failures'],
                'reason'    => (string) ( $state['remove_error'] ?? '' ),
                'failed_at' => isset( $state['failed_at'] ) ? (int) $state['failed_at'] : null,
                'set_aside' => substr( $batch, -strlen( self::FAILED_SUFFIX ) ) === self::FAILED_SUFFIX,
            ];
        }

        return $out;
    }

    /**
     * Record where the batch is, and release it to the next consumer.
     *
     * @return void
     */
    private function letGo() {

        if ( ! $this->handle ) {

            return;
        }

        $this->state['clean'] = true;
        $this->writeState();
        flock( $this->handle, LOCK_UN );
        fclose( $this->handle );

        $this->handle = null;
        $this->batch  = null;
    }

    /** @return void */
    private function writeState() {

        if ( $this->batch ) {

            try {

                // Under a fresh name, renamed into place: processing/ is the web server's to write too.
                \OWA\Core\SafeFile::replace( $this->batch . '.state', (string) json_encode( $this->state ), 0664 );

            } catch ( \RuntimeException $e ) {

                \OWA\Core\CoreAPI::notice( 'Tracker ingest: ' . $e->getMessage() );
            }
        }
    }

    /**
     * File names in a directory, sorted.
     *
     * @param  string      $dir
     * @param  string|null $suffix  only names ending in it
     * @return string[]
     */
    private static function files( $dir, $suffix = null ) {

        $names = [];

        foreach ( (array) @scandir( $dir ) as $name ) {

            if ( $name === '.' || $name === '..' || ! is_file( $dir . $name ) ) {

                continue;
            }

            if ( $suffix !== null && substr( $name, -strlen( $suffix ) ) !== $suffix ) {

                continue;
            }

            $names[] = $name;
        }

        sort( $names, SORT_STRING );

        return $names;
    }
}
