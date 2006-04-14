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
	 * Constructor
	 *
	 * @return asyncEventProcessor
	 * @access public
	 */
	function asyncEventProcessor() {
	
		$this->config = &owa_settings::get_settings();
		$this->debug = &owa_lib::get_debugmsgs();
		
		// Turns off async setting so that the proper event queue is created
		$this->config['async_db'] = false;
		$this->eq = &eventQueue::get_instance();
		$conf = array('mode' => 640, 'timeFormat' => '%X %x');
		
		// Create Error Logger
		$this->error_logger = &Log::singleton('file', $this->config['async_error_log_file'], 'ident', $conf);
		$this->error_logger->_lineFormat = '[%3$s]';
		$this->error_logger->_filename = $this->config['async_error_log_file'];
		
		return;
	}
	
	/**
	 * Restore DAO using config from the event itself.
	 *
	 * @todo need this anymore?
	 */
	function restore_db_conn() {

		return;	
	}
	
	/**
	 * Process Events from log file
	 * 
	 * @access public
	 *
	 */
	function process_events() {
		
		// check to see if file exisits
		if (file_exists($this->config['async_log_dir'].$this->config['async_log_file'])):
			
			// Create a new log file name		
			$new_file = $this->config['async_log_dir'].posix_getpid().".".time().".txt";
			// Rename current log file 
			rename ($this->config['async_log_dir'].$this->config['async_log_file'], $new_file ) or die ("Could not rename file");
			// open file for reading
			$handle = @fopen($new_file, "r");
			if ($handle):
				while (!feof($handle)) {
					// Read row
					$buffer = fgets($handle, 14096); // big enough?
					
					// Parse the row
					$event = $this->parse_log_row($buffer);
					
					// Restore db connection from request event
					if ($this->config['restore_db_conn'] == true):
						$this->config['db_name'] = $event['event_obj']->config['db_name'];
						$this->config['db_user'] = $event['event_obj']->config['db_user'];
						$this->config['db_password'] = $event['event_obj']->config['db_password'];
						$this->config['db_host'] = $event['event_obj']->config['db_host'];
						$this->config['restore_db_conn'] = false;					
					endif;
					
					// Log event to the event queue
					$this->eq->log($event['event_obj'], $event['event_type']);
					// print status
					print "Logging: ". $event['event_type'] . "...\n";
					//$result = $this->eq->log($event['event_obj'], $event['event_type']);
					
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
				
			else:
				//print error
				print "Could not open log file.";
			endif;
			
			// rename file to mark it as processed
			rename ($new_file, $new_file.".processed" ) or die ("Could not rename file");	
			
		endif;
			
		return;
	
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
