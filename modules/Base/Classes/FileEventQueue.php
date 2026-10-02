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
 * Under the queue's directory (async_log_dir):
 *
 *   events.txt     what log.php appends to, one JSON line per beacon
 *   unprocessed/   batches rotated out of events.txt, oldest name first
 *   processing/    a batch a consumer holds, and its .state sidecar
 *   delayed/       released messages, one file per due minute
 *   dead/          dead letters, one file per day
 *   archive/       finished batches, when archive_old_events is on
 *
 * A LINE is {"r": times received before, "e": envelope}.
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
 * how many drains have died on the line at it; that line's receive count goes
 * up, and the lines behind it are not charged.
 *
 * No Monolog, no PID file, and no shelling out to ps to check one.
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
    var $dead_path;
    var $archive_path;

    /** @var resource|null the batch this consumer holds */
    private $handle;

    /** @var string|null its path */
    private $batch;

    /** @var array its .state: settled (byte offset), stuck, clean */
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

        $this->queue_dir        = rtrim( (string) $dir, '/' ) . '/';
        $this->event_file       = $this->queue_dir . 'events.txt';
        $this->unprocessed_path = $this->queue_dir . 'unprocessed/';
        $this->processing_path  = $this->queue_dir . 'processing/';
        $this->delayed_path     = $this->queue_dir . 'delayed/';
        $this->dead_path        = $this->queue_dir . 'dead/';
        $this->archive_path     = $this->queue_dir . 'archive/';

        foreach ( array( $this->queue_dir, $this->unprocessed_path, $this->processing_path,
                         $this->delayed_path, $this->dead_path, $this->archive_path ) as $d ) {

            if ( ! is_dir( $d ) && ! @mkdir( $d, 0755, true ) && ! is_dir( $d ) ) {

                throw new \Exception( "Cannot make queue directory $d." );
            }
        }
    }

    function __destruct() {

        $this->letGo();
    }

    // ---------------------------------------------------------------------
    // The contract
    // ---------------------------------------------------------------------

    public function send( array $envelope, $delay = 0 ) {

        $line = self::encodeLine( 0, $envelope );

        if ( $line === null ) {

            return false;
        }

        $delay = (int) $delay;

        return $delay > 0
            ? $this->append( $this->delayedFile( time() + $delay ), $line )
            : $this->append( $this->event_file, $line );
    }

    public function receive( $max, $visibility = 0 ) {

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

            [ $before, $envelope ] = self::decodeLine( $line );

            // The line a drain died on is received once more for each drain that died on it.
            $redelivered = $offset === $this->resumed_at ? $this->state['stuck'] : 0;

            $this->outstanding[ $offset ] = $end;

            $messages[] = new \OWA\Core\IntakeMessage(
                array( 'batch' => $this->batch, 'offset' => $offset, 'end' => $end ),
                $envelope, $before + 1 + $redelivered, rtrim( $line, "\n" ) );
        }

        return $messages;
    }

    public function ack( \OWA\Core\IntakeMessage $message ) {

        return $this->settle( $message );
    }

    public function release( \OWA\Core\IntakeMessage $message, $delay ) {

        $ok = $message->envelope === null ? false
            : $this->append( $this->delayedFile( time() + max( 0, (int) $delay ) ),
                             self::encodeLine( $message->receive_count, $message->envelope ) );

        if ( ! $ok ) {

            // Not written anywhere: leave it outstanding so it is delivered again.
            return false;
        }

        return $this->settle( $message );
    }

    public function deadLetter( \OWA\Core\IntakeMessage $message, $reason ) {

        $letter = array(
            'at'     => time(),
            'reason' => (string) $reason,
            'r'      => $message->receive_count,
        );

        if ( $message->envelope !== null ) {

            $letter['e'] = $message->envelope;

        } else {

            $letter['raw'] = $message->raw;
        }

        $line = json_encode( $letter, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        if ( $line === false || ! $this->append( $this->dead_path . date( 'Y-m-d' ) . '.txt', $line ) ) {

            return false;
        }

        return $this->settle( $message );
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

    // ---------------------------------------------------------------------
    // Housekeeping
    // ---------------------------------------------------------------------

    /**
     * Delete archived batches and dead letters older than $interval seconds.
     *
     * @param  int $interval
     * @return int how many files
     */
    function pruneArchive( $interval ) {

        $removed = 0;

        foreach ( array( $this->archive_path, $this->dead_path ) as $dir ) {

            foreach ( self::files( $dir ) as $name ) {

                $path = $dir . $name;

                if ( filemtime( $path ) < time() - (int) $interval && @unlink( $path ) ) {

                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Lines waiting, for diagnostics and tests: events.txt, the batches and
     * the delayed files. Reads every file, so not for a hot path.
     *
     * @return int
     */
    function depth() {

        $n = 0;

        foreach ( array_merge(
            array( $this->event_file ),
            array_map( fn ( $f ) => $this->unprocessed_path . $f, self::files( $this->unprocessed_path ) ),
            array_map( fn ( $f ) => $this->processing_path . $f, self::files( $this->processing_path, '.txt' ) ),
            array_map( fn ( $f ) => $this->delayed_path . $f, self::files( $this->delayed_path ) )
        ) as $path ) {

            if ( is_file( $path ) ) {

                $n += count( array_filter( (array) file( $path ), fn ( $l ) => trim( $l ) !== '' ) );
            }
        }

        return $n;
    }

    // ---------------------------------------------------------------------
    // Lines
    // ---------------------------------------------------------------------

    /** @return string|null one line, without its newline */
    private static function encodeLine( $received, array $envelope ) {

        $json = json_encode( array( 'r' => (int) $received, 'e' => $envelope ),
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );

        return $json === false ? null : $json;
    }

    /** @return array [ times received before, envelope or null ] */
    private static function decodeLine( $line ) {

        $row = json_decode( $line, true );

        if ( ! is_array( $row ) || ! isset( $row['e'] ) || ! is_array( $row['e'] ) ) {

            return array( 0, null );
        }

        return array( max( 0, (int) ( $row['r'] ?? 0 ) ), $row['e'] );
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

        $this->handle           = $fh;
        $this->batch            = $path;
        $this->outstanding      = array();
        $this->at_end           = false;
        /*
         * A .state not marked clean belonged to a drain that died: the line at
         * its settled offset is the one it had in hand -- a drain ingests in
         * order and records each line as it is settled -- so that line, and
         * only that one, has been received once more. One that kills every
         * drain reaches the receive limit and is dead-lettered; the lines
         * behind it are not charged for it.
         */
        $died = $state && empty( $state['clean'] );

        $this->handle      = $fh;
        $this->batch       = $path;
        $this->outstanding = array();
        $this->at_end      = false;
        $this->state       = array(
            'settled' => (int) ( $state['settled'] ?? 0 ),
            'stuck'   => (int) ( $state['stuck'] ?? 0 ) + ( $died ? 1 : 0 ),
            'clean'   => false,
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
