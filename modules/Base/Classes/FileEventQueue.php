<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * The file-backed tracking intake (PLAN 2.30.3), and the default one.
 *
 * MIRRORS THE SQS IMPLEMENTATION: a main queue with a dead-letter queue of its
 * own, a receive limit the queue enforces itself (SQS's redrive policy), and
 * one-time replay from the dead-letter queue. Where a file cannot do what SQS
 * does the same way -- hiding a message in place for a delay -- the
 * difference stays inside this class.
 *
 * One difference is deliberate: NO RETENTION LIMIT. A beacon not yet ingested
 * is kept, in either queue, until it is ingested, replayed or removed by
 * hand. SQS deletes one after fourteen days at most; a file need not.
 *
 * Each queue is a directory (the main one is async_log_dir, its dead-letter
 * queue is dead-letter/ inside it):
 *
 *   events.txt     what log.php appends to, one JSON line per beacon
 *   unprocessed/   batches rotated out of events.txt, oldest name first
 *   processing/    a batch a consumer holds, and its .state sidecar
 *   delayed/       released messages, one file per due minute
 *   archive/       finished batches, when archive_old_events is on
 *
 * A LINE is {"r": times received before, "e": envelope}, plus "p" once it has
 * been replayed from the dead-letter queue, and in a dead-letter queue "why"
 * and "at"; a line that never decoded is kept there as "raw".
 *
 * WRITERS append under flock(LOCK_EX) and, holding it, check that the file
 * they opened is still the one at that path. A consumer rotates by renaming
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
    var $queue_name = 'tracker-ingest';

    /** @var string the queue's directory, with a trailing slash */
    var $queue_dir;

    var $event_file;
    var $unprocessed_path;
    var $processing_path;
    var $delayed_path;
    var $archive_path;

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
     * @var array its .state:
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
    private $outstanding = array();

    /** @var bool the batch has been read to its end */
    private $at_end = false;

    function __construct( $map = array() ) {

        if ( isset( $map['queue_name'] ) ) {

            $this->queue_name = (string) $map['queue_name'];
        }

        $dir = isset( $map['path'] ) && $map['path'] !== ''
            ? $map['path'] : \OWA\Core\CoreAPI::getSetting( 'base', 'async_log_dir' );

        $this->is_dead_letter = ! empty( $map['is_dead_letter'] );
        $this->max_receives   = $this->is_dead_letter ? null
            : max( 1, (int) ( $map['max_receives'] ?? TrackerIngest::MAX_RECEIVES ) );

        $this->queue_dir        = rtrim( (string) $dir, '/' ) . '/';
        $this->event_file       = $this->queue_dir . 'events.txt';
        $this->unprocessed_path = $this->queue_dir . 'unprocessed/';
        $this->processing_path  = $this->queue_dir . 'processing/';
        $this->delayed_path     = $this->queue_dir . 'delayed/';
        $this->archive_path     = $this->queue_dir . 'archive/';
    }

    function __destruct() {

        $this->letGo();
    }

    // ---------------------------------------------------------------------
    // The contract
    // ---------------------------------------------------------------------

    public function provision() {

        foreach ( array( $this->queue_dir, $this->unprocessed_path, $this->processing_path,
                         $this->delayed_path, $this->archive_path ) as $d ) {

            if ( ! is_dir( $d ) && ! @mkdir( $d, 0755, true ) && ! is_dir( $d ) ) {

                \OWA\Core\CoreAPI::notice( "Tracker ingest: cannot make queue directory $d." );

                return false;
            }
        }

        $this->provisioned = true;

        $dlq = $this->deadLetterQueue();

        return $dlq ? $dlq->provision() : true;
    }

    public function send( array $envelope, $delay = 0, $replayed = false ) {

        $row = array( 'r' => 0, 'e' => $envelope );

        if ( $replayed ) {

            $row['p'] = 1;
        }

        $delay = (int) $delay;

        return $this->appendRow( $delay > 0 ? $this->delayedFile( time() + $delay ) : $this->event_file, $row );
    }

    public function receive( $max, $visibility = 0 ) {

        if ( ! $this->provisioned && ! $this->provision() ) {

            return array();
        }

        $max      = max( 1, (int) $max );
        $messages = array();

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
                array( 'batch' => $this->batch, 'offset' => $offset, 'end' => $end ),
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

        if ( $row === null || ! $this->appendRow( $this->delayedFile( time() + max( 0, (int) $delay ) ), $row ) ) {

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
            ? array( 'r' => $message->receive_count, 'e' => $message->envelope )
            : array( 'r' => $message->receive_count, 'raw' => $message->raw );

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

    public function deadLetterQueue() {

        if ( $this->is_dead_letter ) {

            return null;
        }

        if ( ! $this->dlq ) {

            $this->dlq = new self( array(
                'path'           => $this->queue_dir . 'dead-letter/',
                'queue_name'     => $this->queue_name . '-dlq',
                'is_dead_letter' => true,
            ) );
        }

        return $this->dlq;
    }

    public function isProbablyEmpty() {

        clearstatcache();

        if ( is_file( $this->event_file ) && filesize( $this->event_file ) > 0 ) {

            return false;
        }

        if ( self::files( $this->unprocessed_path ) || self::files( $this->processing_path, '.txt' ) ) {

            return false;
        }

        foreach ( self::files( $this->delayed_path ) as $name ) {

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

        return array(
            'messages'   => $this->depth(),
            // Of what is due. A batch's mtime is its last line's: an underestimate for its first.
            'oldest_age' => $oldest === null ? null : max( 0, time() - $oldest ),
        );
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
    function pruneArchive( $interval ) {

        $removed = 0;

        foreach ( self::files( $this->archive_path ) as $name ) {

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
    function depth() {

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
     * @return array[] batch, pid, host, held_since, settled
     */
    function heldBatches() {

        $held = array();

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
            $state = is_array( $state ) ? $state : array();

            $held[] = array(
                'batch'      => $name,
                'pid'        => isset( $state['pid'] ) ? (int) $state['pid'] : null,
                'host'       => (string) ( $state['host'] ?? '' ),
                'held_since' => isset( $state['held_since'] ) ? (int) $state['held_since'] : null,
                'settled'    => (int) ( $state['settled'] ?? 0 ),
            );
        }

        return $held;
    }

    // ---------------------------------------------------------------------
    // Lines
    // ---------------------------------------------------------------------

    /** @return array r, e (array|null), and p, why, at, raw when present */
    private static function decodeRow( $line ) {

        $row = json_decode( $line, true );

        if ( ! is_array( $row ) || ! ( ( isset( $row['e'] ) && is_array( $row['e'] ) ) || isset( $row['raw'] ) ) ) {

            // Not a line this queue wrote: half-written, or a 1.x serialized event.
            return array( 'r' => 0, 'e' => null );
        }

        $row['r'] = max( 0, (int) ( $row['r'] ?? 0 ) );
        $row['e'] = isset( $row['e'] ) && is_array( $row['e'] ) ? $row['e'] : null;

        return $row;
    }

    /** What release() re-queues: the message as it was, its count kept. */
    private function rowOf( \OWA\Core\IntakeMessage $message ) {

        if ( $message->envelope !== null ) {

            $row = array( 'r' => $message->receive_count, 'e' => $message->envelope );

        } elseif ( $message->raw !== '' ) {

            // A dead letter that never decoded, kept as it was.
            $row = array( 'r' => $message->receive_count, 'raw' => $message->raw );

        } else {

            return null;
        }

        if ( $message->replayed ) {

            $row['p'] = 1;
        }

        foreach ( array( 'why' => $message->reason, 'at' => $message->dead_at ) as $k => $v ) {

            if ( $v !== null ) {

                $row[ $k ] = $v;
            }
        }

        return $row;
    }

    /** Append one row; the directory is made on first use, as provision() would. */
    private function appendRow( $path, array $row ) {

        $line = json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        if ( $line === false ) {

            return false;
        }

        if ( ! is_dir( dirname( $path ) ) && ! $this->provision() ) {

            return false;
        }

        return $this->append( $path, $line );
    }

    /**
     * Append one line to $path, safe against a rotation renaming it.
     *
     * @return bool
     */
    private function append( $path, $line ) {

        for ( $try = 0; $try < 5; $try++ ) {

            $fh = @fopen( $path, 'a' );

            if ( ! $fh ) {

                \OWA\Core\CoreAPI::notice( "Tracker ingest: cannot open $path for writing." );

                return false;
            }

            flock( $fh, LOCK_EX );

            clearstatcache( true, $path );
            $mine = fstat( $fh );
            $now  = @stat( $path );

            if ( $now && $mine && $now['ino'] === $mine['ino'] && $now['dev'] === $mine['dev'] ) {

                $ok = fwrite( $fh, $line . "\n" ) === strlen( $line ) + 1;
                fflush( $fh );
                flock( $fh, LOCK_UN );
                fclose( $fh );

                return $ok;
            }

            // Renamed between our open and our lock: write to the file now at $path.
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }

        return false;
    }

    /** The delayed file for a due time: one per minute, named for when all of it is due. */
    private function delayedFile( $due ) {

        return $this->delayed_path . (string) ( (int) ceil( $due / 60 ) * 60 ) . '.txt';
    }

    /** @return string[] every file holding waiting lines */
    private function waitingFiles() {

        return array_values( array_filter( array_merge(
            array( $this->event_file ),
            array_map( fn ( $f ) => $this->unprocessed_path . $f, self::files( $this->unprocessed_path ) ),
            array_map( fn ( $f ) => $this->processing_path . $f, self::files( $this->processing_path, '.txt' ) ),
            array_map( fn ( $f ) => $this->delayed_path . $f, self::files( $this->delayed_path ) )
        ), 'is_file' ) );
    }

    // ---------------------------------------------------------------------
    // Batches
    // ---------------------------------------------------------------------

    /** Move events.txt into unprocessed/, when it holds anything. */
    private function rotate() {

        clearstatcache( true, $this->event_file );

        if ( is_file( $this->event_file ) && filesize( $this->event_file ) > 0 ) {

            $this->moveIntoBatches( $this->event_file, 'events' );
        }
    }

    /** Move delayed files that are due into unprocessed/. */
    private function promoteDue() {

        foreach ( self::files( $this->delayed_path ) as $name ) {

            if ( (int) $name <= time() ) {

                $this->moveIntoBatches( $this->delayed_path . $name, 'delayed-' . (int) $name );
            }
        }
    }

    /**
     * Rename a written-to file into unprocessed/, then wait out any writer
     * still holding its lock.
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

            if ( $this->hold( $this->processing_path . $name ) ) {

                return true;
            }
        }

        foreach ( self::files( $this->unprocessed_path ) as $name ) {

            $target = $this->processing_path . $name;

            if ( @rename( $this->unprocessed_path . $name, $target ) && $this->hold( $target ) ) {

                return true;
            }
        }

        return false;
    }

    /** Lock a batch in processing/ and resume it where it was last settled. */
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
        $state = is_array( $state ) ? $state : array();

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
        $this->outstanding = array();
        $this->at_end      = false;
        $this->state       = array(
            'settled'    => $settled,
            'in_hand'    => null,
            'stuck'      => (int) ( $state['stuck'] ?? 0 ) + ( $died ? 1 : 0 ),
            'clean'      => false,
            'pid'        => getmypid(),
            'host'       => (string) gethostname(),
            'held_since' => time(),
        );
        $this->resumed_at  = $this->state['settled'];

        fseek( $fh, $this->state['settled'] );
        $this->writeState();

        return true;
    }

    /** One message is dealt with; a batch with nothing left is finished. */
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

    /** Every line of the batch is settled: archive or delete it. */
    private function finishBatch() {

        $path = $this->batch;

        flock( $this->handle, LOCK_UN );
        fclose( $this->handle );

        $this->handle = null;
        $this->batch  = null;

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'archive_old_events' ) ) {

            @rename( $path, $this->archive_path . basename( $path ) );

        } else {

            @unlink( $path );
        }

        @unlink( $path . '.state' );
    }

    /** Record where the batch is, and release it to the next consumer. */
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

    private function writeState() {

        if ( $this->batch ) {

            @file_put_contents( $this->batch . '.state', json_encode( $this->state ), LOCK_EX );
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

        $names = array();

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

?>
