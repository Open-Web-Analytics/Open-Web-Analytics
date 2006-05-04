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

require_once 'owa_settings_class.php';
require_once 'owa_lib.php';
require_once 'owa_env.php';
require_once 'eventQueue.php';
require_once (OWA_PEARLOG_DIR . '/Log.php');
require_once 'owa_session_class.php';
require_once 'owa_request_class.php';

/**
 * Asynchronous Event Processsor
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class asyncEventProcessor {

	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Debug
	 *
	 * @var string
	 */
	var $debug;
	
	/**
	 * Processing Errors
	 *
	 * @var array
	 */
	var $errors = array();
	
	/**
	 * Error Logger
	 *
	 * @var object
	 */
	var $error_logger;
	
	/**
	 * Event Queue
	 *
	 * @var object
	 */
	var $eq;
	
	/**
	 * Error Handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Constructor
	 *
	 * @return asyncEventProcessor
	 * @access public
	 */
	function asyncEventProcessor() {
	
		$this->config = &owa_settings::get_settings();
		$this->debug = &owa_lib::get_debugmsgs();
		$this->e = &owa_error::get_instance();
		
		// Turns off async setting so that the proper event queue is created
		$this->config['async_db'] = false;
		
		// Create Error Logger - NEEDED?
		$conf = array('mode' => 640, 'timeFormat' => '%X %x');
		$this->error_logger = &Log::singleton('file', $this->config['async_error_log_file'], 'ident', $conf);
		$this->error_logger->_lineFormat = '[%3$s]';
		$this->error_logger->_filename = $this->config['async_error_log_file'];
		
		return;
	}
	
	/**
	 * Processes a named file
	 *
	 * @param string $event_file
	 */
	function process_specific($event_file) {

		$this->process_events($event_file);
		return;
	}
	
	/**
	 * Processes the file name specified in configuration array
	 * 
	 * @access public
	 */
	function process_standard() {
		
		$this->process_events($this->config['async_log_dir'].$this->config['async_log_file']);
		return;
	}
	/**
	 * Process Events from standard event log file
	 * 
	 * @access public
	 *
	 */
	function process_events($event_file) {
		$this->e->debug(sprintf('Starting Async Event Processing Run for: %s',
									$event_file));
		//check for lock file
		if (file_exists($this->config['async_log_dir'].$this->config['async_lock_file'])):
			//read contents of lock file for last PID
			$lock_file = fopen($this->config['async_log_dir'].$this->config['async_lock_file'], "r") or die ("Could not create lock file");
				if ($lock_file):
		   			while (!feof($lock_file)) {
		       			$former_pid = fgets($lock_file, 4096);
				    }
		   			fclose($lock_file);
				endif;
			//check to see if former PID is still running
			$ps_check = $this->is_running($former_pid);
			//if the rpocess is still running, exit.
			if ($ps_check == true):
				exit;
			//if it's not running remove the lock file and proceead.
			else:
				unlink ($this->config['async_log_dir'].$this->config['async_lock_file']);
				$this->process_event_log($event_file);
			endif;

		else:
			$this->process_event_log($event_file);
			
		endif;
		return;
	}
	
	function create_lock_file() {
		
		$lock_file = fopen($this->config['async_log_dir'].$this->config['async_lock_file'], "w+") or die ("Could not create lock file");
								
		// Write PID to lock file
   		if (fwrite($lock_file, posix_getpid()) === FALSE) {
       		print "Cannot write to lockfile";
       		exit;
   		}
		
		return;
	}
	
	function process_event_log($file) {
		// check to see if event log file exisits
		
		if (file_exists($file)):
			$this->create_lock_file();
				
			// Create a new log file name		
			$new_file = $this->config['async_log_dir'].time().".".posix_getpid().".processing";
			// Rename current log file 
			rename ($file, $new_file ) or die ("Could not rename file");
			// open file for reading
			$handle = @fopen($new_file, "r");
				if ($handle):
					while (!feof($handle)) {
						// Read row
						$buffer = fgets($handle, 14096); // big enough?
						
						// Parse the row
						$event = $this->parse_log_row($buffer);
					
						
						// Restore db connection settings from request event
						if ($this->config['restore_db_conn'] == true):
						/*	$this->config['db_name'] = $event['event_obj']->config['db_name'];
							$this->config['db_user'] = $event['event_obj']->config['db_user'];
							$this->config['db_password'] = $event['event_obj']->config['db_password'];
							$this->config['db_host'] = $event['event_obj']->config['db_host'];
							
							//print $event['event_obj']->config['db_name'];
							//print_r($this->config);
						*/
							// bring up event queue
							$this->eq = &eventQueue::get_instance();
							// set flag so that this loop does not happen again.
							$this->config['restore_db_conn'] = false;
						else:
							// bring up event queue using the settings alread in config
							$this->eq = &eventQueue::get_instance();	
						endif;
						
						//print_r ($this->eq);
						
						// Log event to the event queue
						if (!empty($event['event_obj'])):
							$this->eq->log($event['event_obj'], $event['event_type']);
							// print status
							print "Logging: ". $event['event_type'] . "...\n";
							//$result = $this->eq->log($event['event_obj'], $event['event_type']);
						endif;						
						/*
						if ($result === false):
							$this->error_logger->log($buffer);	
						else: 
							print "Could not open async error log";
						endif;
						*/
					}
				//Close file
				fclose($handle);
				
				// rename file to mark it as processed
				rename ($new_file, $new_file.".processed" ) or die ("Could not rename file");	
				
				//Delete processed file
				//unlink($new_file."processed");
					
				else:
					//print error
					print "Could not open log file.";
				endif;
				
				//Delete Lock file
				unlink($this->config['async_log_dir'].$this->config['async_lock_file']);
			endif;
				
		return;
	}
	
	/**
	 * Check if application is already running
	 * 
	 */
	function is_running($PID){
       exec("ps $PID", $process_state);
       
		if (count($process_state) >= 2):
			$this->e->debug(sprintf('Process %d is still running. Terminating Run. \n',
									$PID));
			//print "Process ".$PID." is still running. Terminating run.";
			return true;
		else:
			$this->e->debug(sprintf('Process %d is not running. Continuing Run... \n',
									$PID));
			//print "Process ".$PID." is not running. Continuing run...";
			return false;
		endif;
  	 }
  
	
	/**
	 * Parse row from event log file
	 *
	 * @param string $row
	 * @return array
	 */
	function parse_log_row($row) {
	
		$raw_event = explode("|*|", $row);
		
		return array( 'timestamp' 		=> $raw_event[0],
						'event_type'	=> $raw_event[3],
						'event_obj'		=> unserialize(urldecode($raw_event[4]))
				); 
	}

}

?>
