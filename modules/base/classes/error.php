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


/**
 * Error Class
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
	
	const OWA_LOG_ALL = 0;
	const OWA_LOG_DEBUG = 2;
	const OWA_LOG_INFO = 4;
	const OWA_LOG_NOTICE = 6;
	const OWA_LOG_WARNING = 8;
	const OWA_LOG_ERR = 10;
	const OWA_LOG_CRIT = 12;
	const OWA_LOG_ALERT = 14;
	const OWA_LOG_EMERG = 16;
	
	/**
	 * logger instances
	 *
	 * @var array
	 */
	
	var $loggers = array();
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
				
	}
	
	function __destruct() {
	
	}
	
	// This is called by a client after the owas global config object has been created.
	public function setHandler($type) {
	
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
	}
	
	function createDevelopmentHandler() {
		
		// set log level to debug
		owa_coreAPI::setSetting('base', 'error_log_level', self::OWA_LOG_DEBUG );
		// make file logger
		$this->make_file_logger();
		// if the CLI is in use, makea console logger.
		if ( defined('OWA_CLI') ) {
		
			$this->make_console_logger();
		}
		
			
		$this->logPhpErrors();
		
		set_exception_handler( array($this, 'logException') );
		
	}
	
	function createProductionHandler() {
		
		// if the level is not changes from the defaul, set log level to notices and above
		if (owa_coreAPI::getSetting( 'base', 'error_log_level') < 1 ) { 
		
			owa_coreAPI::setSetting('base', 'error_log_level', self::OWA_LOG_NOTICE );
		}
		// make file logger
		$this->make_file_logger();
		// if the CLI is in use, makea console logger.
		if ( defined('OWA_CLI') ) {
		
			$this->make_console_logger();
		}
	}
	
	
	function debug($message) {
		
		return $this->log($message, self::OWA_LOG_DEBUG);
	}
	
	function info($message) {
		
		return $this->log($message, self::OWA_LOG_INFO);
	}
	
	function notice($message) {
	
		return $this->log($message, self::OWA_LOG_NOTICE);
	}
	
	function warning($message) {
	
		return $this->log($message, self::OWA_LOG_WARNING);
	}
	
	function err($message) {
	
		return $this->log($message, self::OWA_LOG_ERR);
	}
	
	function crit($message) {
		
		return $this->log($message, self::OWA_LOG_CRIT);
	}
	
	function alert($message) {
		
		return $this->log($message, self::OWA_LOG_ALERT);
	}
	
	function emerg($message) {
		
		return $this->log($message, self::OWA_LOG_EMERG);
	}
	
	function log( $err, $priority = 0 ) {
		
		
		if ( $this->init) {
			// log to normal loggers	
			return $this->logMsg($err, $priority);
			
		} else {
			// buffer msgs untill the global config object has been loaded
			// and a proper logger can be setup
			return $this->bufferMsg($err, $priority);
		}
	}
	
	function bufferMsg($err, $priority) {
		
		$this->bmsgs[] = array('error' => $err, 'priority' => $priority);
		return true;
	}
	
	function logBufferedMsgs() {
				
		if (!empty($this->bmsgs)) {
		
			foreach($this->bmsgs as $msg) {
			
				$this->log($msg['error'], $msg['priority']);
			}
			
			$this->bmsgs = null;			
		}
	}
	
	/**
	 * Builds a console logger 	
	 *
	 */
	function make_console_logger() {
		
		$conf = array('name' => 'console_log');
		$this->loggers['console'] = owa_coreAPI::supportClassFactory( 'base', 'logConsole', $conf );	
	}
	
	/**
	 * Builds a logger that writes to a file.
	 *
	 */
	function make_file_logger() {
		
		$path = owa_coreAPI::getSetting('base', 'error_log_file');
		//instantiate a a log file
		$conf = array('name' => 'debug_log', 'file_path' => $path);
		$this->loggers['file'] = owa_coreAPI::supportClassFactory( 'base', 'logFile', $conf );
	}
	
	function logPhpErrors() {
		
		error_reporting( E_ALL );
		ini_set('display_errors', 'On');
		set_error_handler( array( $this, "handlePhpError" ) );
		ini_set("log_errors", 1);
		ini_set("error_log", owa_coreAPI::getSetting('base', 'error_log_file') );
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
		//$user_errors = array(E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, E_STRICT);
	   
		$err = "<errorentry>\n";
		$err .= "\t<datetime>" . $dt . "</datetime>\n";
		$err .= "\t<errornum>" . $errno . "</errornum>\n";
		$err .= "\t<errormsg>" . $errmsg . "</errormsg>\n";
		$err .= "\t<scriptname>" . $filename . "</scriptname>\n";
		$err .= "\t<scriptlinenum>" . $linenum . "</scriptlinenum>\n";
	
		//if (in_array($errno, $user_errors)) {
		//	$err .= "\t<vartrace>" . wddx_serialize_value($vars, "Variables") . "</vartrace>\n";
		//}
		
		$err .= "</errorentry>\n\n";
	   
	    $this->debug( $err );
	}
	
	function logMsg( $msg, $priority ) {
		
		// check error priority before logging.
		if ( owa_coreAPI::getSetting('base', 'error_log_level') <= $priority ) {
		
			$dt = date("H:i:s Y-m-d"); 
			$pid = getmypid();
			foreach ( $this->loggers as $logger ) {
				
				$message = sprintf("%s %s [%s] %s \n", $dt, $pid, $logger->name, $msg);
				$logger->append( $message );
			}
		}
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
	
	function logException($exception) {
		
		$msg = $exception->getMessage() . ' // '.$exception->getTraceAsString();
		if (defined('OWA_MAIL_EXCEPTIONS')) {
			$this->mailErrorMsg( $msg, 'Uncaught Exception' );
		}
		
		$this->log( $msg );
	}
	
	function mailErrorMsg( $msg, $subject ) {
		
		 $body = 'Error Message: '. $msg . "\n";
  		 $body .= "POST: ". print_r($_POST, true) . "\n";
  		 $body .= "GET: ". print_r($_GET, true) . "\n";
  		 $body .= "Request: ". print_r($_REQUEST, true) . "\n";
  		 $body .= "Server: ". print_r($_SERVER, true) . "\n";
  		 $body .= "PID: ". getmypid() . "\n";
  		 
  		 if ( isset( $_SERVER['SERVER_NAME'] ) ) {
  		 
  		 	$server = $_SERVER['SERVER_NAME'];
  		 } else {
	  		 
	  		 $server = __FILE__;
  		 }
  		 $conf = array('subject' => $subject . ' on '. $server, 'from' => 'OWA Error-logger', 'name' => 'exceptions_log');
  		 $logger = owa_coreAPI::supportClassFactory('base', 'logEmail', $conf);
		 $logger->log($body);
	}
}

?>