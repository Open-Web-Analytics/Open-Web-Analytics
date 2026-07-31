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
// $Id$
//


use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * File based Event Queue Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class FileEventQueue extends \OWA\Core\EventQueue {

    var $queue;
    var $queue_dir;
    var $event_file;
    var $date_format;
    var $unprocessed_path;
    var $archive_path;
    var $rotation_size;
    var $rotation_interval = 3600;
    var $currentProcessingFileHandle;

    function __construct( $map = array() ) {

        parent::__construct( $map );

        // set event file
        if ( ! isset( $map['path'] ) ) {
            $this->queue_dir = \OWA\Core\CoreAPI::getSetting('base', 'async_log_dir');
        } else {
            $this->queue_dir = $map['path'];

        }

        // set directory where unprocessed, rotated files reside
        if ( ! isset( $map['unprocessed_path'] ) ) {

            $this->unprocessed_path = $this->queue_dir . 'unprocessed/';

        } else {
            $this->unprocessed_path = $map['unprocessed_path'];
        }

        // test or make dir
        if ( ! is_dir( $this->unprocessed_path ) && ! mkdir( $this->unprocessed_path, 0755 ) ) {

            throw new \Exception("Cannot make unprocessed directory.");
        }

        // set directory where processed files will be archived.
        if ( ! isset( $map['archive_path'] ) ) {
            $this->archive_path = $this->queue_dir . 'archive/';
        } else {
            $this->archive_path = $map['archive_path'];
        }

        // test or make dir
        if ( ! is_dir( $this->archive_path ) && ! mkdir( $this->archive_path, 0755 ) ) {

            throw new \Exception("Cannot make archive directory.");
        }

        if ( ! isset( $map['date_format'] ) ) {
            $this->date_format = "Y-m-d-H-is";
        }

        if ( ! isset( $map['rotation_interval'] ) ) {
            $this->rotation_interval = $map['rotation_interval'];
        }

        $this->event_file = $this->queue_dir. 'events.txt';
        $this->lock_file = $this->queue_dir.'lock.txt';

        return parent::__construct( $map );
    }

    function makeQueue() {

        //make file queue
        //$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
        
        //$this->queue = Log::singleton('file', $this->event_file, $this->queue_name, $conf);
        //$this->queue->_lineFormat = '%1$s|*|%2$s|*|[%3$s]|*|%4$s';
        // not sure why this is needed but it is.
        //$this->queue->_filename    = $this->event_file;
        
        
        
        //////
        $this->queue = new Logger( $this->queue_name );
        
        $pid = getmypid();
        $dt = "H:i:s Y-m-d";
        $template = "%datetime%|*|$this->queue_name|*|$pid|*|%message%\n";
        
        $formatter = new LineFormatter($template, $dt, true, true);
        
        $stream = new StreamHandler( $this->event_file, Logger::NOTICE );
        
		$stream->setFormatter($formatter);
		
		// add stream handler to logger
		$this->queue->pushHandler($stream);
        
        
        
        
    }

    function openFile( $file ) {

        // check to see if event log file exisits
        if ( file_exists( $file ) && is_readable( $file ) ) {
            //create lock file
            $this->create_lock_file();
            return @fopen($file, "r");
        } else {
            throw new \Exception("Cannot open queue file at ".$file);
        }
    }

    function closeFile( $handle ) {

        fclose( $handle );
    }

    function isLocked() {

        if ( file_exists( $this->lock_file ) ) {
            //read contents of lock file for last PID
            $lock = fopen( $this->lock_file, "r" ) or die ("Could not read lock file");
            if ($lock) {
                while (!feof($lock)) {
                    $former_pid = fgets($lock, 4096);
                }
                fclose($lock);
            }

            //check to see if former process is still running
            $ps_check = $this->isRunning($former_pid);
            //if the process is still running, exit.
            if ($ps_check) {
                \OWA\Core\CoreAPI::notice(sprintf('Previous Process (%d) still active. Terminating Run.', $former_pid));
                return true;
            //if it's not running remove the lock file and proceead.
            } else {
                \OWA\Core\CoreAPI::debug(sprintf('Process %d is no longer running. Deleting old Lock file. \n', $former_pid));
                unlink ($this->lock_file);
                return false;
            }

        } else {
            return false;
        }
    }

    function isRunning($pid) {

        $process_state = '';
      
           exec("ps $pid", $process_state);
           //print $pid;
           //print_r($process_state);
   
        if (count($process_state) >= 2) {
            return true;
        } else {
            return false;
        }
    }

    function sendMessage($event) {

        if ( ! $this->queue ) {
	        
            $this->makeQueue();
        }

        $this->queue->notice( urlencode( serialize( $event ) ) );
    }


    function receiveMessage() {
        \OWA\Core\CoreAPI::notice("receive event.");
        $qfile = $this->getNextUnprocessedQueueFile();

        if ( ! $this->currentProcessingFileHandle ) {

            if ( $qfile ) {
                // set current processing file handle to
                \OWA\Core\CoreAPI::notice("Opening queue file $qfile to process.");

                $this->currentProcessingFileHandle = $this->openFile( $qfile );
            } else {

                \OWA\Core\CoreAPI::notice('No queue file to process.');
                return false;
            }
        }

        if ( $this->currentProcessingFileHandle ) {

            $buffer = fgets( $this->currentProcessingFileHandle );

            if ( ! feof( $this->currentProcessingFileHandle ) ) {

                // Parse the row
                \OWA\Core\CoreAPI::debug('returning buffer: '. print_r( $buffer, true));
               
                $event = $this->parse_log_row( $buffer );
                //owa_coreAPI::debug('returning event: '. print_r( $event, true));
                $event->wasReceived();
                return $event;

            } else {
                // if it is the end of file then, close, archive and move onto the next file.
                \OWA\Core\CoreAPI::notice('EOF reached.');
                $this->closeFile( $this->currentProcessingFileHandle );
                $this->currentProcessingFileHandle = '';

                if ( \OWA\Core\CoreAPI::getSetting( 'base', 'archive_old_events' ) ) {

                    $this->archiveProcessedFile( $qfile );

                } else {

                    $this->deleteFile( $qfile );
                }

                \OWA\Core\CoreAPI::notice('Moving on to next queue file.');

                return $this->receiveMessage();

            }

        } else {
            \OWA\Core\CoreAPI::notice('still no queue to process.');
            return false;
        }
    }

    function getNextUnprocessedQueueFile() {

        // get a list of all unprocesed queue files
        $qfiles = $this->getUnprocessedFileList();
        \OWA\Core\CoreAPI::notice('queue files to process: '.print_r($qfiles, true));
        // get earliest queue file based on creation time so we can process them in order
        if ( $qfiles && is_array( $qfiles ) ) {

            return array_shift( $qfiles );

        } else {

            return \OWA\Core\CoreAPI::notice('No unprocessed queue files to process.');
        }
    }

    function getUnprocessedFileList() {

        $files = array();

        $this->rotateEventFile();

        if ( is_dir( $this->unprocessed_path ) ) {
            foreach ( new \DirectoryIterator( $this->unprocessed_path ) as $item ) {
                if ( $item->isFile() && ! $item->isDot() ) {
                    $files[ $item->getMTime() ] = $item->getPathname();
                }
            }

            // sort by key ascending
            ksort( $files );
        }

        return $files;
    }

    function pruneArchive( $interval ) {

        if ( is_dir( $this->archive_path ) ) {

            foreach ( new \DirectoryIterator( $this->archive_path ) as $item ) {

                if ( $item->isFile() &&
                    ! $item->isDot() &&
                    $item->getMTime() < ( time() - $interval ) )
                {
                        \OWA\Core\CoreAPI::notice('about to unlink' . $item->getRealPath());
                        $this->deleteFile( $item->getRealPath() );
                }
            }
        }
    }

    function deleteFile( $path ) {
	    
		\OWA\Core\CoreAPI::debug('About to deleting file: ' . $path);
        return unlink( $path );
    }

    function rotateEventFile() {

        if ( file_exists( $this->event_file ) ) {

            // Create a new log file name
            $new_file_path = sprintf("%s-eventfile-%s.txt", $this->unprocessed_path . $this->queue_name, date( $this->date_format ) );
            $ret = \OWA\Core\Lib::moveFile( $this->event_file, $new_file_path );

            if ( $ret ) {
                \OWA\Core\CoreAPI::debug('Rotated event file.');
            } else {
                \OWA\Core\CoreAPI::debug('Could not rotate event file.');
            }
        }
    }

    function archiveProcessedFile( $file ) {
		
		\OWA\Core\CoreAPI::debug('Archiving file: ' . $file);
        $new_file_path = $this->archive_path . basename( $file );
        $ret = \OWA\Core\Lib::moveFile( $file, $new_file_path );
    }


    function parse_log_row( $row ) {

        if ($row) {
            $raw_event = explode("|*|", $row);
            $row_array = array( 'timestamp' => $raw_event[0], 'event_obj' => $raw_event[3]);
            // Same allowlist as the db queue -- a log file anyone can append to
            // must not be able to name the class that gets instantiated here.
            $event = unserialize(
                urldecode($row_array['event_obj']),
                array( 'allowed_classes' => self::allowedEventClasses() )
            );
            return $event;
        }
    }

    function create_lock_file() {

        $lock_file = fopen($this->lock_file, "w+") or die ("Could not create lock file at: ".$this->lock_file);

        // Write PID to lock file
           if (fwrite($lock_file, getmypid()) === FALSE) {
               \OWA\Core\CoreAPI::debug('Cannot write to lock file. Terminating Run.');
               exit;
           }
    }
}

?>