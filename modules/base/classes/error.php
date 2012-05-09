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

if ( ! class_exists( 'Log' ) ) {
	require_once (OWA_PEARLOG_DIR . '/Log.php');
}
if ( ! class_exists( 'Log_file' ) ) {
	require_once (OWA_PEARLOG_DIR . '/Log/file.php');
}
if ( ! class_exists( 'Log_composite' ) ) {
	require_once (OWA_PEARLOG_DIR . '/Log/composite.php');
}
if ( ! class_exists( 'Log_mail' ) ) {
	require_once (OWA_PEARLOG_DIR . '/Log/mail.php');
}

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
	 * Instance of the current logger
	 *
	 * @var object
	 */
	var $logger;
	
	/**
	 * Buffered Msgs
	 *
	 * @var array
	 */
	var $bmsgs;
	
	var $hasChildren = false;
	
	var $init = false;
	
	var $c;
	
	/**
	 * Constructor
	 *
	 */ 
	function __construct() {
				
		// setup composite logger
		$this->logger = Log::singleton('composite');
		$this->addLogger('null');	 
	}
	
	function __destruct() {
	
		return;
	}
	
	function setConfig($c) {
		$this->c = $c;
	}
	
	function setErrorLevel() {
		
		return;
	}
	
	function addLogger($type, $mask = null, $config = array()) {
		
		// make child logger
		$child = $this->loggerFactory($type, $config);
		
		if (!empty($child)):
			//set error level mask
			if (!empty($mask)):
				$child->setMask($mask);
			endif;
			
			// add child to main composite logger
			$ret = $this->logger->addChild($child);
		else:
			$ret = false;
		endif;
				
		//set hasChildren flag
		if ($ret == true):
			$this->hasChildren = true;
		else:
			return false;
		endif;
	}
	
	function removeLogger($type) {
		return false;
	}
	
	
	function setHandler($type) {
	
		switch ($type) {
			case "development":
				$this->createDevelopmentHandler();
				break;
			case "production":
				$this->createProductionHandler();
				break;
			default:
				$this->createProductionHandler();
		}
	
		$this->init = true;
		$this->logBufferedMsgs();
		
		return;

	}
	
	function createDevelopmentHandler() {
		
		$mask = PEAR_LOG_ALL;
		$this->addLogger('file', $mask);
		
		if (defined('OWA_CLI')) {
			$this->addLogger('console', $mask);	
		}
	}
	
	function createCliDevelopmentHandler() {
		
		$mask = PEAR_LOG_ALL;
		$this->addLogger('file', $mask);
		$this->addLogger('console', $mask);
	}
	
	function createCliProductionHandler() {
		
		$mail_mask = Log::MASK(PEAR_LOG_EMERG) | Log::MASK(PEAR_LOG_CRIT) | Log::MASK(PEAR_LOG_ALERT);
		$this->addLogger('mail', $mail_mask);
		$this->addLogger('console', $file_mask);
	}
	
	function createProductionHandler() {
		
		$file_mask = PEAR_LOG_ALL ^ Log::MASK(PEAR_LOG_DEBUG) ^ Log::MASK(PEAR_LOG_INFO);
		$this->addLogger('file', $file_mask);
		$mail_mask = Log::MASK(PEAR_LOG_EMERG) | Log::MASK(PEAR_LOG_CRIT) | Log::MASK(PEAR_LOG_ALERT);
		$this->addLogger('mail', $mail_mask);
		
		if (defined('OWA_CLI')) {
			$this->addLogger('console', $file_mask);	
		}
	}
	
	
	function debug($message) {
		
		return $this->log($message, PEAR_LOG_DEBUG);
		
	}
	
	function info($message) {
		
		return $this->log($message, PEAR_LOG_INFO);
	}
	
	function notice($message) {
	
		return $this->log($message, PEAR_LOG_NOTICE);
	}
	
	function warning($message) {
	
		return $this->log($message, PEAR_LOG_WARNING);
	}
	
	function err($message) {
	
		return $this->log($message, PEAR_LOG_ERR);

	}
	
	function crit($message) {
		
		return $this->log($message, PEAR_LOG_CRIT);

	}
	
	function alert($message) {
		
		return $this->log($message, PEAR_LOG_ALERT);

	}
	
	function emerg($message) {
		
		return $this->log($message, PEAR_LOG_EMERG);

	}
	
	function log($err, $priority) {
		
		// log to normal logger
		if ($this->init) {
			return $this->logger->log($err, $priority);
		} else {
			return $this->bufferMsg($err, $priority);
		}
	}
	
	function bufferMsg($err, $priority) {
		
		$this->bmsgs[] = array('error' => $err, 'priority' => $priority);
		return true;
	}
	
	function logBufferedMsgs() {
				
		if (!empty($this->bmsgs)):
			foreach($this->bmsgs as $msg) {
			
				$this->log($msg['error'], $msg['priority']);
			}
			
			$this->bmsgs = null;			
		endif;
		
		return;
	
	}
	
	
	function loggerFactory($type, $config = array()) {
	
		switch ($type) {
			case "display":
				return $this->make_display_logger($config);
				break;
			case "window":
				return $this->make_window_logger($config);
				break;
			case "file":
				return $this->make_file_logger($config);
				break;
			case "syslog":
				return $this->make_syslog_logger($config);
				break;
			case "mail":
				return $this->make_mail_logger($config);
				break;
			case "console":
				return $this->make_console_logger($config);
				break;
			case "firebug":
				return $this->makeFirebugLogger($config);
				break;
			case "null":
				return $this->make_null_logger();
				break;
			default:
				return false;
		}
	
	}
	
	function makeFirebugLogger() {
	
		$logger = &Log::singleton('firebug', '', getmypid());
		return $logger;
	}
	
	
	/**
	 * Builds a null logger 
	 * 
	 * @return object
	 */
	function make_null_logger() {
		
		$logger = Log::singleton('null');
		return $logger;
	}
	
	
	/**
	 * Builds a console logger 	
	 *
	 * @return object
	 */
	function make_console_logger() {
		if (!defined('STDOUT')) {
			define('STDOUT', fopen("php://stdout", "r"));
		}
		$conf = array('stream' => STDOUT, 'buffering' => false);
		$logger = &Log::singleton('console', '', getmypid(), $conf);
		return $logger;
	}
	
	/**
	 * Builds a logger that writes to a file.
	 *
	 * @return unknown
	 */
	function make_file_logger() {
		
		// test to see if file is writable
		$handle = @fopen(owa_coreAPI::getSetting('base', 'error_log_file'), "a");
		
		if ($handle != false):
			fclose($handle);
			$conf = array('mode' => 0600, 'timeFormat' => '%X %x', 'lineFormat' => '%1$s %2$s [%3$s] %4$s');
			$logger = Log::singleton('file', owa_coreAPI::getSetting('base', 'error_log_file'), getmypid(), $conf);
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
		$logger = Log::singleton('mail', owa_coreAPI::getSetting('base', 'notice_email'), getmypid(), $conf);
		
		return $logger;
	}
	
	function logPhpErrors() {
		error_reporting(E_ALL);
		ini_set('display_errors', E_ALL);
		return set_error_handler(array("owa_error", "handlePhpError"));
	
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
		$user_errors = array(E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, E_STRICT);
	   
		$err = "<errorentry>\n";
		$err .= "\t<datetime>" . $dt . "</datetime>\n";
		$err .= "\t<errornum>" . $errno . "</errornum>\n";
		$err .= "\t<errormsg>" . $errmsg . "</errormsg>\n";
		$err .= "\t<scriptname>" . $filename . "</scriptname>\n";
		$err .= "\t<scriptlinenum>" . $linenum . "</scriptlinenum>\n";
	
		if (in_array($errno, $user_errors)) {
		//	$err .= "\t<vartrace>" . wddx_serialize_value($vars, "Variables") . "</vartrace>\n";
		}
		
		$err .= "</errorentry>\n\n";
	   
	    owa_coreAPI::debug($err);
		
		return;
	}
	
	function backtrace() {
		
		$dbgTrace = debug_backtrace();
		$bt = array();
		foreach($dbgTrace as $dbgIndex => $dbgInfo) {
			
			$bt[$dbgIndex] = array('file' => $dbgInfo['file'], 
									'line' => $dbgInfo['line'], 
									'function' => $dbgInfo['function'],
									'args' => $dbgInfo['args']);
		}
		
		return $bt;

	}
	
	function mailException($exception) {
		
		$this->mailErrorMsg( $exception->getTraceAsString(), 'Uncaught Exception' );
	}
	
	function mailErrorMsg( $msg, $subject ) {
		
		 $body = 'Error Message: '. $msg . "\n";
  		 $body .= "POST: ". print_r($_POST, true) . "\n";
  		 $body .= "GET: ". print_r($_GET, true) . "\n";
  		 $body .= "Request: ". print_r($_REQUEST, true) . "\n";
  		 $body .= "Server: ". print_r($_SERVER, true) . "\n";
  		 
  		 $conf = array('subject' => $subject . ' on '. $_SERVER['SERVER_NAME'], 'from' => 'OWA Error-logger');
  		 $logger = Log::singleton('mail', owa_coreAPI::getSetting('base', 'notice_email'), getmypid(), $conf);
		
		 $logger->log($body);
	}

}

?>