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
require_once 'owa_env.php';
require_once (OWA_PEARLOG_DIR . '/Log.php');

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
	 * Gets instance of error logger
	 *
	 * @return object $logger
	 */
	function &get_instance() {	
			
		static $logger;
		
		if (!isset($logger)):
		
				$c = &owa_coreAPI::configSingleton();
				$config = $c->fetch('base');
			
			switch ($config['error_handler']) {
				
				case "development":
					
					//$config['debug_to_screen'] = true;
					//$window = owa_error::make_window_logger();
					$logger = owa_error::make_file_logger();
					
					if (!empty($logger)):	
						$file_mask = PEAR_LOG_ALL;
						$logger->setMask($file_mask);
					endif;
					//$logger = &Log::singleton('composite');
					//$logger->addChild($window);
					//$logger->addChild($file);
					break;
					
				case "async_development":
				
					$logger = &Log::singleton('composite');
				
					$file = owa_error::make_file_logger();
					
					if (!empty($file)):	
						$logger->addChild($file);
					endif;
					
					$console = owa_error::make_console_logger();
					$logger->addChild($console);
					
					break;
					
				case "production":
					
					$logger = &Log::singleton('composite');
					
					$mail = owa_error::make_mail_logger();
					$mail_mask = Log::MASK(PEAR_LOG_EMERG) | Log::MASK(PEAR_LOG_CRIT) | Log::MASK(PEAR_LOG_ALERT);
					//$mail_mask = PEAR_LOG_ALL;
					$mail->setMask($mail_mask);
					$logger->addChild($mail);
					
					$file = owa_error::make_file_logger();
					
					if (!empty($file)):	
						$file_mask = PEAR_LOG_ALL ^ Log::MASK(PEAR_LOG_DEBUG) ^ Log::MASK(PEAR_LOG_INFO);
						$file->setMask($file_mask);
						$logger->addChild($file);
					endif;
					
					break;
					
				default:
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
					
			}
		
		endif;
		
		return $logger;
	}
	
	/**
	 * Returns the buffered error output
	 *
	 * @return unknown
	 */
	function &get_msgs() {
		
		static $msgs;
		return $msgs;
	}
	
	/**
	 * Interface to build various loggers
	 *
	 * @param unknown_type $type
	 */
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
			case "console":
				$this->make_console_logger();
				break;
		}
		
		return;
	}
	
	/**
	 * Builds a logger that writes to a seperate browser window.
	 * This uses a custom log handler that writes output to a temp static variable.
	 *
	 * @return object
	 */
	function make_window_logger() {
		
		$conf = array('title' => 'Error Log Output');
		$logger = &Log::singleton('winstatic', 'LogWindow', posix_getpid(), $conf);
		return $logger;
	}
	/**
	 * Builds a logger that writes to the browser window.
	 * 
	 * @todo build a custom handler that writes output ot temp static varibale
	 * @return object
	 */
	function make_display_logger() {
		
		$conf = array('error_prepend' => '<font color="#ff0000"><tt>', 'error_append'  => '</tt></font>');
		$logger = &Log::singleton('display', '', posix_getpid(), $conf);
		return $logger;
	}
	
	function make_console_logger() {
		define('STDOUT', fopen("php://stdout", "r"));
		$conf = array('stream' => STDOUT, 'buffering' => false);
		$logger = &Log::singleton('console', '', posix_getpid(), $conf);
		return $logger;
	}
	
	/**
	 * Builds a logger that writes to a file.
	 *
	 * @return unknown
	 */
	function make_file_logger() {
		
		$handle = @fopen($this->config['error_log_file'], "a");
		
		if ($handle != false):
			fclose($handle);
			$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
			$logger = &Log::singleton('file', $this->config['error_log_file'], posix_getpid(), $conf);
			return $logger;
		else:
			return;
		endif;
	}
	
	/**
	 * Builds a logger that sends lines via email
	 *
	 * @return unknown
	 */
	function make_mail_logger() {
		
		$conf = array('subject' => 'Important Error Log Events', 'from' => 'OWA-Error-Logger');
		$logger = &Log::singleton('mail', $this->config['notice_email'], posix_getpid(), $conf);
		return $logger;
	}
	
	/**
	 * Builds a composite logger object
	 *
	 * @param array $loggers
	 * @return object
	 */
	function make_composite_logger($loggers) {
		
		$logger = &Log::singleton('composite');
		
		foreach ($loggers as $key) {
			
			$this->logger->addChild($key);
		}

		return $logger;	
	}
	
	/**
	 * Alternative error handler for PHP specific errors.
	 *
	 * @param string $errno
	 * @param string $errmsg
	 * @param string $filename
	 * @param string $linenum
	 * @param string $vars
	 */
	function handlePhpError($errno = null, $errmsg, $filename, $linenum, $vars) {
		
	    $dt = date("Y-m-d H:i:s (T)");
	    
	    // set of errors for which a var trace will be saved
		$user_errors = array(E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE);
	   
		$err = "<errorentry>\n";
		$err .= "\t<datetime>" . $dt . "</datetime>\n";
		$err .= "\t<errornum>" . $errno . "</errornum>\n";
		$err .= "\t<errormsg>" . $errmsg . "</errormsg>\n";
		$err .= "\t<scriptname>" . $filename . "</scriptname>\n";
		$err .= "\t<scriptlinenum>" . $linenum . "</scriptlinenum>\n";
	
		if (in_array($errno, $user_errors)) {
			$err .= "\t<vartrace>" . wddx_serialize_value($vars, "Variables") . "</vartrace>\n";
		}
		
		$err .= "</errorentry>\n\n";
	    $conf = array('mode' => 0600, 'timeFormat' => '%X %x');
	   	$c = &owa_coreAPI::configSingleton();
		$config = $c->fetch('base');
		$logger = &Log::singleton('file', $config['error_log_file'], posix_getpid(), $conf);
		$file_mask = PEAR_LOG_ALL;
		$logger->setMask($file_mask);
	    $logger->log($err, $priority);
		
		return;
	}

}

?>