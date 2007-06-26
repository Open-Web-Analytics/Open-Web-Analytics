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

require_once 'owa_lib.php';
require_once 'owa_env.php';
require_once 'eventQueue.php';
//require_once (OWA_PEARLOG_DIR . '/Log.php');
require_once 'owa_caller.php';


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
class asyncEventProcessor extends owa_caller {

	
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
	 * Database acces object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Constructor
	 *
	 * @return asyncEventProcessor
	 * @access public
	 */
	function asyncEventProcessor($config = null) {
		$this->owa_caller($config);
				
		if ($this->config['error_handler'] == 'development'):
			$this->config['error_handler'] = 'async_development';
		endif;
		
		$this->e = &owa_error::get_instance();

		// Turns off async setting so that the proper event queue is created
		$this->config['async_db'] = false;
		$this->eq = &eventQueue::get_instance();
		$this->db = &owa_coreAPI::dbSingleton();
		
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
		$this->e->info(sprintf('Starting Async Event Processing Run for: %s',
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
			//if the process is still running, exit.
			if ($ps_check == true):
				$this->e->info(sprintf('Previous Process (%d) still active. Terminating Run.',
									$former_pid));
				exit;
			//if it's not running remove the lock file and proceead.
			else:
				$this->e->info(sprintf('Process %d is not running. Continuing Run... \n',
									$former_pid));
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
   		if (fwrite($lock_file, getmypid()) === FALSE) {
       		$this->e->alert('Cannot write to lock file. Terminating Run.');
       		exit;
   		}
		
		return;
	}
	
	function process_event_log($file) {
		// check to see if event log file exisits
		
		if (file_exists($file)):
			if($this->db->connection_status == true):
				$this->create_lock_file();
					
				// Create a new log file name	
				$new_file_name = $this->config['async_log_dir'].time().".".getmypid();
				$new_file = $new_file_name.".processing";
				// Rename current log file 
				rename ($file, $new_file ) or die ("Could not rename file");
				$this->e->info('renamed event file.');
				// open file for reading
				$handle = @fopen($new_file, "r");
				if ($handle):
					while (!feof($handle)) {
						// Read row
						$buffer = fgets($handle, 14096); // big enough?
							
						// Parse the row
						$event = $this->parse_log_row($buffer);
						
						// Log event to the event queue
						if (!empty($event['event_obj'])):
							
							$this->eq->log($event['event_obj'], $event['event_type']);
							// print status
							$this->e->info(sprintf('Processing: %s', $event['event_type']));
								
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
					$processed_file_name = $new_file_name.".processed";
					rename ($new_file, $processed_file_name) or die ("Could not rename file");	
					$this->e->info(sprintf('Processing Complete. Renaming File to %s',
											$processed_file_name ));
					//Delete processed file
					unlink($processed_file_name);
					$this->e->info(sprintf('Deleting File %s',
											$processed_file_name ));
						
				else:
					//print error
					$this->e->alert(sprintf('Could not open file %s. Terminating Run.',
											$new_file));
					exit;
				endif;
					
				//Delete Lock file
				unlink($this->config['async_log_dir'].$this->config['async_lock_file']);
				
			else:
				$this->e->err('Database Connection is down.');	
			endif;	
		
		endif;
		
		return;
	}
	
	/**
	 * Check if application is already running
	 * 
	 */
	function is_running($PID){
      
      $process_state = '';
      
       exec("ps $PID", $process_state);
       
		if (count($process_state) >= 2):
			
			return true;
		else:
			
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
