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

require_once OWA_PEARLOG_DIR . '/Log.php';
require_once OWA_PLUGINS_DIR . 'log/winstatic.php';

/**
 * Error handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_error {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Instance of the current logger
	 *
	 * @var object
	 */
	var $logger;
	
	/**
	 * Error priority
	 *
	 * @var unknown_type
	 */
	var $priority;
	
	/**
	 * Gets instanceof error logger
	 *
	 * @return object $logger
	 */
	function &get_instance() {	
		
		$config = &owa_settings::get_settings();
		
		static $logger;
		
		if (!isset($logger)):
		
			switch ($this->config['error_handler']) {
				
				case "development":
					
					$config['debug_to_screen'] = true;
					$window = owa_error::make_window_logger();
					$file = owa_error::make_file_logger();
					$logger = &Log::singleton('composite');
					$logger->addChild($window);
					$logger->addChild($file);
					
					break;
					
				case "production":
					
					$file = owa_error::make_file_logger();
					$file_mask = PEAR_LOG_ALL ^ Log::MASK(PEAR_LOG_DEBUG);
					$file->setMask($file_mask);
					
					$mail = owa_error::make_mail_logger();
					$mail_mask = Log::MASK(PEAR_LOG_EMERG) | Log::MASK(PEAR_LOG_CRIT) | Log::MASK(PEAR_LOG_ALERT);
					$mail_mask = PEAR_LOG_ALL;
					$mail->setMask($mail_mask);
					
					$logger = &Log::singleton('composite');
					$logger->addChild($mail);
					$logger->addChild($file);
					break;
				
				default:
					
			}
		
		endif;
		
		return $logger;
	}
	
	function &get_msgs() {
		
		static $msgs;
		return $msgs;
	}
	
	function make_logger($type) {
		
		switch ($type) {
			case "display":
				$this->make_display_logger();
				break;
			case "window":
				$this->make_window_logger();
				break;
			case "file":
				$this->make_file_logger();
				break;
			case "syslog":
				$this->make_syslog_logger();
				break;
				case "mail":
				$this->make_mail_logger();
				break;
			default:
		}
		
		return;
	}
	
	function make_window_logger() {
		
		$conf = array('title' => 'Error Log Output');
		$logger = &Log::singleton('winstatic', 'LogWindow', 'error-window-logger', $conf);
		return $logger;
	}
	
	function make_display_logger() {
		
		$conf = array('error_prepend' => '<font color="#ff0000"><tt>', 'error_append'  => '</tt></font>');
		$logger = &Log::singleton('display', '', 'error-display-logger', $conf);
		return $logger;
	}
	
	function make_file_logger() {
		
		$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
		$logger = &Log::singleton('file', $this->config['error_log_file'], 'error-file-logger', $conf);
		return $logger;
	}
	
	function make_mail_logger() {
		
		$conf = array('subject' => 'Important Error Log Events', 'from' => 'OWA-Error-Logger');
		$logger = &Log::singleton('mail', $this->config['notice_email'], 'error-mail-logger', $conf);
		return $logger;
	}
	
	function make_composite_logger($loggers) {
		
		$logger = &Log::singleton('composite');
		
		foreach ($loggers as $key) {
			
			$this->logger->addChild($key);
		}

		return $logger;	
	}
	
	function handlePhpError($errno = null, $errmsg, $filename, $linenum, $vars) {
		
	    /* Map the PHP error to a Log priority. */
	    switch ($code) {
	    case E_WARNING:
	    case E_USER_WARNING:
	        $priority = PEAR_LOG_WARNING;
	        break;
	    case E_NOTICE:
	    case E_USER_NOTICE:
	        $priority = PEAR_LOG_NOTICE;
	        break;
	    case E_ERROR:
	    case E_USER_ERROR:
	        $priority = PEAR_LOG_ERR;
	        break;
	    case "debug":
	        $priority = PEAR_LOG_DEBUG;
	        break;
	    default:
	        $priotity = PEAR_LOG_INFO;
	    }
	    
	    $dt = date("Y-m-d H:i:s (T)");
	    
	    // set of errors for which a var trace will be saved
		$user_errors = array(E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE);
	   
		$err = "<errorentry>\n";
		$err .= "\t<datetime>" . $dt . "</datetime>\n";
		$err .= "\t<errornum>" . $errno . "</errornum>\n";
		$err .= "\t<errortype>" . $errortype[$errno] . "</errortype>\n";
		$err .= "\t<errormsg>" . $errmsg . "</errormsg>\n";
		$err .= "\t<scriptname>" . $filename . "</scriptname>\n";
		$err .= "\t<scriptlinenum>" . $linenum . "</scriptlinenum>\n";
	
		if (in_array($errno, $user_errors)) {
			$err .= "\t<vartrace>" . wddx_serialize_value($vars, "Variables") . "</vartrace>\n";
		}
		
		$err .= "</errorentry>\n\n";
	    
	    $this->logger->log($err, $priority);
		
		return;
	}
	
	function log($errmsg, $priority) {
		
		return $this->logger->log($errmsg, $priority);
	}
}


?>