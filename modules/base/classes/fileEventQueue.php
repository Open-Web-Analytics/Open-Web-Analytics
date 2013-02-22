<?php

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

if ( ! class_exists( 'owa_eventQueue' ) ) {
	require_once( OWA_BASE_CLASS_DIR.'eventQueue.php' );
}
if ( ! class_exists( 'owa_event' ) ) {
	require_once(OWA_BASE_CLASS_DIR.'event.php');
}
require_once(OWA_PEARLOG_DIR . '/Log.php');
require_once(OWA_PEARLOG_DIR . '/Log/file.php');

/**
 * File based Event Queue Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_fileEventQueue extends owa_eventQueue {
	
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
			$this->queue_dir = owa_coreAPI::getSetting('base', 'async_log_dir');
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
				
			throw new Exception("Cannot make unprocessed directory.");
		}
		
		// set directory where processed files will be archived.
		if ( ! isset( $map['archive_path'] ) ) {
			$this->archive_path = $this->queue_dir . 'archive/';
		} else {
			$this->archive_path = $map['archive_path'];
		}
		
		// test or make dir
		if ( ! is_dir( $this->archive_path ) && ! mkdir( $this->archive_path, 0755 ) ) {
				
			throw new Exception("Cannot make archive directory.");
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
		$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
		//$this->queue = &Log::singleton('async_queue', $this->event_file, 'async_event_queue', $conf);
		$this->queue = Log::singleton('file', $this->event_file, $this->queue_name, $conf);
		$this->queue->_lineFormat = '%1$s|*|%2$s|*|[%3$s]|*|%4$s';
		// not sure why this is needed but it is.
		$this->queue->_filename	= $this->event_file;
	}
		
	function openFile( $file ) {
				
		// check to see if event log file exisits
		if ( file_exists( $file ) && is_readable( $file ) ) {
			//create lock file
			$this->create_lock_file();
			return @fopen($file, "r");
		} else {
			throw new Exception("Cannot open queue file at ".$file);
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
				owa_coreAPI::notice(sprintf('Previous Process (%d) still active. Terminating Run.', $former_pid));
				return true;
			//if it's not running remove the lock file and proceead.
			} else {
				owa_coreAPI::debug(sprintf('Process %d is no longer running. Deleting old Lock file. \n', $former_pid));
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
		
		$this->queue->log( urlencode( serialize( $event ) ) );
	}

	
	function receiveMessage() {
		owa_coreAPI::notice("receive event.");
		$qfile = $this->getNextUnprocessedQueueFile();
		
		if ( ! $this->currentProcessingFileHandle ) {
			
			if ( $qfile ) {
				// set current processing file handle to
				owa_coreAPI::notice("Opening queue file $qfile to process.");

				$this->currentProcessingFileHandle = $this->openFile( $qfile );
			} else {
				
				owa_coreAPI::notice('No queue file to process.');
				return false;
			}
		}
		
		if ( $this->currentProcessingFileHandle ) {
			
			$buffer = fgets( $this->currentProcessingFileHandle );
			
			if ( ! feof( $this->currentProcessingFileHandle ) ) {
					
				// Parse the row
				//owa_coreAPI::debug('returning buffer: '. print_r( $buffer, true));	
				//owa_coreAPI::debug('returning buffer: '. print_r( $buffer, true));
				$event = $this->parse_log_row( $buffer );
				//owa_coreAPI::debug('returning event: '. print_r( $event, true));
				$event->wasReceived();
				return $event;			
				
			} else {
				// if it is the end of file then, close, archive and move onto the next file.
				owa_coreAPI::notice('EOF reached.');
				$this->closeFile( $this->currentProcessingFileHandle );
				$this->currentProcessingFileHandle = '';
				
				if ( owa_coreAPI::getSetting( 'base', 'archive_old_events' ) ) {
					
					$this->archiveProcessedFile( $qfile );
										
				} else {
					
					$this->deleteFile( $qfile );	
				}
				
				owa_coreAPI::notice('Moving on to next queue file.');
				
				return $this->receiveMessage();
				
			}
			
		} else {
			owa_coreAPI::notice('still no queue to process.');
			return false;
		}
	}
		
	function getNextUnprocessedQueueFile() {
		
		// get a list of all unprocesed queue files
		$qfiles = $this->getUnprocessedFileList();
		owa_coreAPI::notice('queue files to process: '.print_r($qfiles, true));
		// get earliest queue file based on creation time so we can process them in order
		if ( $qfiles && is_array( $qfiles ) ) {
			
			return array_shift( $qfiles );
		
		} else {
			
			return owa_coreAPI::notice('No unprocessed queue files to process.');
		}
	}
	
	function getUnprocessedFileList() {
		
		$files = array();
		
		$this->rotateEventFile();
		
		if ( is_dir( $this->unprocessed_path ) ) {
			foreach ( new DirectoryIterator( $this->unprocessed_path ) as $item ) {
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
		
			foreach ( new DirectoryIterator( $this->archive_path ) as $item ) {
			
				if ( $item->isFile() && 
					! $item->isDot() && 
					$item->getMTime() < ( time() - $interval ) ) 
				{
						owa_coreAPI::notice('about to unlink' . $item->getRealPath());
						$this->deleteFile( $item->getRealPath() );
				}
			}
		}
	}
	
	function deleteFile( $path ) {
		
		return unlink( $path );
	}
	
	function rotateEventFile() {
		
		if ( file_exists( $this->event_file ) ) {
		
			// Create a new log file name	
			$new_file_path = sprintf("%s-eventfile-%s.txt", $this->unprocessed_path . $this->queue_name, date( $this->date_format ) );
			$ret = owa_lib::moveFile( $this->event_file, $new_file_path );
			
			if ( $ret ) {
				owa_coreAPI::debug('Rotated event file.');
			} else {
				owa_coreAPI::debug('Could not rotate event file.');
			}
		}
	}
	
	function archiveProcessedFile( $file ) {
		
		$new_file_path = $this->archive_path . basename( $file );
		$ret = owa_lib::moveFile( $file, $new_file_path );
	}
	
	
	function parse_log_row( $row ) {
		
		if ($row) {
			$raw_event = explode("|*|", $row);
			$row_array = array( 'timestamp' => $raw_event[0], 'event_obj' => $raw_event[3]); 			
			$event = unserialize(urldecode($row_array['event_obj']));
			return $event;
		}
	}
	
	function create_lock_file() {
		
		$lock_file = fopen($this->lock_file, "w+") or die ("Could not create lock file at: ".$this->lock_file);
								
		// Write PID to lock file
   		if (fwrite($lock_file, getmypid()) === FALSE) {
       		owa_coreAPI::debug('Cannot write to lock file. Terminating Run.');
       		exit;
   		}
	}
}

?>