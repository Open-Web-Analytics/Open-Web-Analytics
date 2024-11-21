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

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Error Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
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
     * logger instance
     *
     * @var array
     */
    var $logger;
    
    /**
     * Buffered Msgs
     *
     * @var array
     */
    var $bmsgs;

    var $init = false;

    /**
     * Constructor
     *
     */
    function __construct() {
		
		$this->logger = new Logger('errors');
		
/*
		if ( owa_lib::inDebug() ) {
			
			$this->createDevelopmentHandler();
			
		} else {
			
			$this->createProductionHandler();
		}
*/
		
		//$this->init = true;
        //$this->logBufferedMsgs();
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
		
		$this->logPhpErrors();
		
        // make file logger
        $this->make_file_logger();
        
        // if the CLI is in use, also make a console logger
        if ( defined('OWA_CLI') ) {

            $this->make_console_logger();
        }
        
        set_exception_handler( [ $this, 'logException' ] );

    }

    function createProductionHandler() {

        // make file logger
        $this->make_file_logger();
        
        // if the CLI is in use, also make a console logger
        if ( defined('OWA_CLI') ) {

            $this->make_console_logger();
        }
    }

    function debug($message) {

        return $this->log($message, 'debug');
    }

    function info($message) {

        return $this->log($message, 'info');
    }

    function notice($message) {

        return $this->log($message, 'notice');
    }

    function warning($message) {

        return $this->log($message, 'warning');
    }

    function err($message) {

        return $this->log($message, 'error');
    }

    function crit($message) {

        return $this->log($message, 'critical');
    }

    function alert($message) {

        return $this->log($message, 'alert');
    }

    function emerg($message) {

        return $this->log($message, 'emergency');
    }

    function log( $err, $priority = 'notice' ) {


        if ( $this->init) {
            // log to normal loggers
            return $this->logMsg($err, $priority);

        } else {
            // buffer msgs untill the global config object has been loaded
            // and a proper logger can be setup
            return $this->bufferMsg($err, $priority);
        }
    }
    
    function logMsg( $msg, $priority ) {

        if ( is_object( $msg ) || is_array( $msg ) ) {

            $msg = print_r( $msg, true );
        }
        
        switch ( $priority ) {
	        
	        case 'debug':
	        	
	        	$this->logger->debug( $msg );
	        	
	        	break;
	        	
	        case 'info':
	        	
	        	$this->logger->info( $msg );
	        	break;
	        	
	        case 'notice':
	        
	        	$this->logger->notice( $msg );
	        	break;
	        	
	        case 'warning':
	        	
	        	$this->logger->warning( $msg );
	        	break;
	        	
	        case 'error':
	        	
	        	$this->logger->error( $msg );
	        	break;
	        	
	        case 'critical':
	        
	        	$this->logger->critical( $msg );
	        	break;
	        	
	        case 'alert':
	        	
	        	$this->logger->alert( $msg );
	        	break;
	        	
	        case 'emergency':
	        	
	        	$this->logger->emergency( $msg );
	        	break;
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
		
		// define standard out
		if ( ! defined( 'STDOUT' ) ) {
	       
	       define('STDOUT', fopen("php://stdout", "w") );
    	}
       
       // determine log level
       $level = $this->getLogLevel();
              
       // create a stream
       $stream = new StreamHandler(STDOUT, $level);
       
       // create a formatter
       $dt = $this->getDateTimestamp();
       
       $template = $this->getLineFormat();
	  
	   $formatter = new LineFormatter($template, $dt, true, true);
        
	   $stream->setFormatter( $formatter );
	   
	   // add the stream hadnler to the logger
       $this->logger->pushHandler( $stream );
    }
    
    function getLogLevel() {
	    
	   $level = Logger::NOTICE;
       
       if ( owa_lib::inDebug() ) {
	       
	       $level = Logger::DEBUG;
       }
       
       return $level;
    }
    
    function getDateTimestamp() {
	    
	    return "H:i:s Y-m-d";
    }
    
    function getLineFormat() {
	    
	    $pid = getmypid();
	    return "[%datetime%] [$pid] [%level_name%] %message% %context% %extra%\n";
    }

    /**
     * Builds a logger that writes to a file.
     *
     */
    function make_file_logger() {

		// create a formatter
		$dt = $this->getDateTimestamp();
        
		$template = $this->getLineFormat();
		
		$formatter = new LineFormatter($template, $dt, true, true);
        
        // determine log level
        $level = $this->getLogLevel();
        
        // create stream handler
        $path = owa_coreAPI::getSetting('base', 'error_log_file');
        
        $stream = new StreamHandler($path, $level);
        
		$stream->setFormatter($formatter);
		
		// add stream handler to logger
		$this->logger->pushHandler($stream);
    }

    function logPhpErrors() {

        self::phpErrorSettings();
        set_error_handler( [ $this, "handlePhpError" ] );
    }
    
    static function phpErrorSettings() {
	    
	    error_reporting( -1 );
        ini_set('display_errors', 'On');
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
    function handlePhpError($errno, $errmsg, $filename = '', $linenum = '') {

        $dt = date("Y-m-d H:i:s (T)");
        
        $err = "<errorentry>\n";
        $err .= "\t<datetime>" . $dt . "</datetime>\n";
        $err .= "\t<errornum>" . $errno . "</errornum>\n";
        $err .= "\t<errormsg>" . $errmsg . "</errormsg>\n";
        $err .= "\t<scriptname>" . $filename . "</scriptname>\n";
        $err .= "\t<scriptlinenum>" . $linenum . "</scriptlinenum>\n";

        $err .= "</errorentry>\n\n";

        $this->debug( $err );
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
