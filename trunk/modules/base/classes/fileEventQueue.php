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

require_once(OWA_BASE_CLASS_DIR.'eventQueue.php');
require_once(OWA_BASE_CLASS_DIR.'event.php');
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
	var $error_logger;
	var $queue_dir;
	var $event_file;
	
	function __construct($queue_dir = '') {
		
		// set event file
		if (!$queue_dir) {
			$this->queue_dir = owa_coreAPI::getSetting('base', 'async_log_dir');
		}
		
		$this->event_file = $this->queue_dir.'events.txt';
		$this->lock_file = $this->queue_dir.'lock.txt';
	}
		
	function makeQueue() {
		
		//make file queue
		$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
		//$this->queue = &Log::singleton('async_queue', $this->event_file, 'async_event_queue', $conf);
		$this->queue = Log::singleton('file', $this->event_file, 'async_event_queue', $conf);
		$this->queue->_lineFormat = '%1$s|*|%2$s|*|[%3$s]|*|%4$s';
		// not sure why this is needed but it is.
		$this->queue->_filename	= $this->event_file;
	}
	
	function addToQueue($event) {
		
		if (!$this->queue) {
			$this->makeQueue();
		}
		
		$this->queue->log(urlencode(serialize($event)));
	
	}
	
	function processQueue($event_file = '') {
	
		if ($event_file) {
		
			$this->event_file = $this->queue_dir.$event_file;
		}
		
		if ( file_exists( $this->event_file ) ) {
			
			$event_log_rotate_size = owa_coreAPI::getSetting( 'base', 'async_log_rotate_size' );
			
			if ( filesize( $this->event_file ) > $event_log_rotate_size ) {
				
				owa_coreAPI::notice(sprintf('Starting Async Event Processing Run for: %s', $this->event_file));
				
				//check for lock file
				if (!$this->isLocked()) {
					
					return $this->process_event_log($this->event_file);
					
				} else {
					
					owa_coreAPI::notice(sprintf('Previous Process (%d) still active. Terminating Run.', $former_pid));
				}
							
			} else {
				
				owa_coreAPI::debug("Event file is not large enough to process yet. Size is only: ".filesize($this->event_file));
			}
			
		} else {
			
			owa_coreAPI::debug("No event file found at: ".$this->event_file);
		}
				
	}
	
	function isLocked() {
		
		if (file_exists($this->lock_file)) {
			//read contents of lock file for last PID
			$lock = fopen($this->lock_file, "r") or die ("Could not read lock file");
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
   		print_r($process_state);
   
		if (count($process_state) >= 2) {
			return true;
		} else {
			return false;
		}
	}
	
	function process_event_log($file) {
		
		// check to see if event log file exisits
		if (!file_exists($file)) {
			owa_coreAPI::debug("Event file does not exist at $file");
			return false;
		}
		
		// check for access to db
		$db = owa_coreAPI::dbSingleton();
		$db->connect();
		if ( ! $db->isConnectionEstablished() ) {
			owa_coreAPI::debug("Aborting processing of event log file. Could not connect to database.");
			return false;
		}
			
		//create lock file
		$this->create_lock_file();
		
		// get event dispatcher
		$dispatch = owa_coreAPI::getEventDispatch();
		
		// Create a new log file name	
		$new_file_name = $this->queue_dir.time().".".getmypid();
		$new_file = $new_file_name.".processing";
		
		// Rename current log file 
		rename ($file, $new_file ) or die ("Could not rename file");
		owa_coreAPI::debug('renamed event file.');
		
		// open file for reading
		$handle = @fopen($new_file, "r");
		if ($handle) {
			while (!feof($handle)) {
				
				// Read row
				$buffer = fgets($handle, 14096); // big enough?
					
				// Parse the row
				$event = $this->parse_log_row($buffer);
				
				// Log event to the event queue
				if (!empty($event)) {
					//print_r($event);
					// debug
					owa_coreAPI::debug(sprintf('Processing: %s (%s)', '', $event->guid));
					// send event object to event queue
					$ret = $dispatch->notify($event);
					
					// is the dispatch was not successful then add the event back into the queue.
					if ( $ret != OWA_EHS_EVENT_HANDLED ) {
						$dispatch->asyncNotify($event);
					}
					
				} else {
					owa_coreAPI::debug("No event found in log row. Must be end of file.");
				}						
			}
			//Close file
			fclose($handle);
			
			// rename file to mark it as processed
			$processed_file_name = $new_file_name.".processed";
			rename ($new_file, $processed_file_name) or die ("Could not rename file");	
			owa_coreAPI::debug(sprintf('Processing Complete. Renaming File to %s', $processed_file_name ));
			
			//Delete processed file
			unlink($processed_file_name);
			owa_coreAPI::debug(sprintf('Deleting File %s', $processed_file_name));
			
			//Delete Lock file
			unlink($this->lock_file);
			
			return true;	
		} else {
			//could not open file for processing
			owa_coreAPI::error(sprintf('Could not open file %s. Terminating Run.', $new_file));
		}
	}

	function makeErrorLogFile() {
		
		$conf = array('mode' => 640, 'timeFormat' => '%X %x');
		$this->error_logger = &Log::singleton('file', owa_coreAPI::getSetting('async_error_log_file'), 'ident', $conf);
		$this->error_logger->_lineFormat = '[%3$s]';
		$this->error_logger->_filename = owa_coreAPI::getSetting('async_error_log_file');
	}
	
	function logError($event) {
	
	}
	
	/**
	 * Parse row from event log file
	 *
	 * @param string $row
	 * @return array
	 */
	function parse_log_row($row) {
		if ($row) {
			$raw_event = explode("|*|", $row);
			//print_r($raw_event);
			//$row_array = array( 'timestamp' 		=> $raw_event[0], 'event_type'	=> $raw_event[3], 'event_obj'		=> $raw_event[4]); 
			$row_array = array( 'timestamp' => $raw_event[0], 'event_obj' => $raw_event[3]); 
			//print_r($row_array);			
			$event = unserialize(urldecode($row_array['event_obj']));
			//print_r($event);
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
		
		return;
	}
}

?>