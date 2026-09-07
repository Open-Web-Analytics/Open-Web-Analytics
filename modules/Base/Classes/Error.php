<?php
namespace OWA\Module\Base\Classes;


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
class Error {

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
     * Constructed lazily -- see logger(). Null until something is actually
     * logged, and null forever on an installation whose vendor/ is missing.
     *
     * @var \Monolog\Logger|null
     */
    var $logger;

    /**
     * Whether the log handlers have been attached to the logger yet.
     *
     * @var bool
     */
    private $handlers_attached = false;
    
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

        /*
         * Do not build the log handlers here.
         *
         * Attaching a handler means constructing Monolog objects, and Monolog
         * is a Composer package: on a source checkout with no vendor/ that is a
         * fatal during boot, which is why an unbuilt download used to answer
         * every request with a blank 500 instead of the installer's environment
         * check saying so. Nothing here needs a logger, so nothing here builds
         * one -- logger() does, on the first message that is actually written.
         *
         * The rest of what these handlers set up is not Monolog's and stays
         * eager, because it has to be in place before the next line of code
         * runs, not before the next message is logged.
         */
        if ( $type === 'development' ) {

            $this->logPhpErrors();
        }

        set_exception_handler( [ $this, 'handleUncaughtException' ] );

        $this->init = true;
        $this->logBufferedMsgs();
    }

    /**
     * The logger, built on first use.
     *
     * Answers null when Monolog is not installed, which is the whole point:
     * every caller below treats "no logger" as "do not log" rather than as an
     * error. An installation in that state cannot write a log file, but it can
     * still render the page that explains why -- see
     * modules/Base/Controller/InstallCheckEnv.php.
     *
     * @return \Monolog\Logger|null
     */
    private function logger() {

        if ( ! class_exists( Logger::class ) ) {

            return null;
        }

        if ( ! $this->logger ) {

            $this->logger = new Logger( 'errors' );
        }

        if ( ! $this->handlers_attached ) {

            // Before the handlers, so a handler that logs cannot recurse into
            // this method and attach a second copy of everything.
            $this->handlers_attached = true;

            $this->make_file_logger();

            // if the CLI is in use, also make a console logger
            if ( defined( 'OWA_CLI' ) ) {

                $this->make_console_logger();
            }
        }

        return $this->logger;
    }

    /**
     * Kept for callers that want the handlers attached now rather than on the
     * first message. Both builders route through logger(), so the attachment
     * happens exactly once however it is reached.
     */
    function createDevelopmentHandler() {

        $this->logPhpErrors();

        set_exception_handler( [ $this, 'handleUncaughtException' ] );

        $this->logger();
    }

    function createProductionHandler() {

        set_exception_handler( [ $this, 'handleUncaughtException' ] );

        $this->logger();
    }

    /**
     * Record an exception nothing else caught, and answer with a server error.
     *
     * Only the development handler used to register one, which had the effect
     * backwards: the installation exposed to the internet was the one running
     * without an exception handler. An uncaught exception there became a PHP
     * fatal -- recorded in the web server's log rather than OWA's own, where an
     * administrator would look for it -- and OWA_MAIL_EXCEPTIONS, whose whole
     * purpose is to tell somebody about an error on a live installation, never
     * fired anywhere but on a developer's machine.
     *
     * The status is set deliberately rather than inherited from PHP's fatal, and
     * the response body is left empty: the message and stack trace go to the log,
     * never to the visitor.
     *
     * @param \Throwable $exception
     * @return void
     */
    function handleUncaughtException( $exception ) {

        $this->logException( $exception );

        // The CLI reports through its own console logger and exit status.
        if ( defined( 'OWA_CLI' ) ) {

            return;
        }

        // Output already began, so the status line is long gone; changing it
        // now would emit a warning on top of the error being handled.
        if ( headers_sent() ) {

            return;
        }

        http_response_code( 500 );
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

        /*
         * No Monolog, no log file. An installation missing vendor/ cannot write
         * one, and saying so is the installer's job -- not this method's, which
         * runs long before there is a page to say it on. Dropping the message is
         * what lets the environment check render and name the real problem.
         */
        $logger = $this->logger();

        if ( ! $logger ) {

            return;
        }
        
        switch ( $priority ) {
	        
	        case 'debug':
	        	
	        	$logger->debug( $msg );
	        	
	        	break;
	        	
	        case 'info':
	        	
	        	$logger->info( $msg );
	        	break;
	        	
	        case 'notice':
	        
	        	$logger->notice( $msg );
	        	break;
	        	
	        case 'warning':
	        	
	        	$logger->warning( $msg );
	        	break;
	        	
	        case 'error':
	        	
	        	$logger->error( $msg );
	        	break;
	        	
	        case 'critical':
	        
	        	$logger->critical( $msg );
	        	break;
	        	
	        case 'alert':
	        	
	        	$logger->alert( $msg );
	        	break;
	        	
	        case 'emergency':
	        	
	        	$logger->emergency( $msg );
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
       $logger = $this->logger();

       if ( $logger ) {

           $logger->pushHandler( $stream );
       }
    }
    
    function getLogLevel() {
	    
	   $level = Logger::NOTICE;
       
       if ( \OWA\Core\Lib::inDebug() ) {
	       
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
        $path = \OWA\Core\CoreAPI::getSetting('base', 'error_log_file');

        if ( ! self::isSafeLogPath( $path ) ) {
            // refuse to open the handler rather than write to an attacker-controlled sink
            error_log( sprintf( 'OWA: refusing unsafe error_log_file value (%s); file logger disabled.', $path ) );
            return;
        }

        // Set the mode explicitly. Without it the file inherits the umask of
        // whichever process happens to create it, and both the web server user
        // and the account running the CLI write to this same path. A file
        // created under umask 022 is 0644, so the other one can no longer append
        // -- and a log write that cannot open its file raises, which turns a
        // notice into a fatal. The path changes whenever the instance hash does,
        // so a new file (and a new race over who creates it) appears on every
        // credential rotation.
        $stream = new StreamHandler($path, $level, true, 0664);

		$stream->setFormatter($formatter);

		// add stream handler to logger
		$logger = $this->logger();

		if ( $logger ) {

			$logger->pushHandler( $stream );
		}
    }

    /**
     * Reject PHP stream wrappers (php://, data://, phar://, expect://, etc.)
     * as log destinations. A quoted-printable / base64 filter wrapper can be
     * abused to decode an attacker-controlled log line into an executable
     * PHP file under the docroot.
     *
     * A plain absolute or relative filesystem path is allowed; anything with
     * a `scheme://` prefix is rejected.
     */
    public static function isSafeLogPath( $path ) {

        if ( ! is_string( $path ) || $path === '' ) {
            return false;
        }

        // any URL-style stream wrapper is disallowed
        if ( preg_match( '#^[A-Za-z][A-Za-z0-9+.\-]*://#', $path ) ) {
            return false;
        }

        return true;
    }

    function logPhpErrors() {

        self::phpErrorSettings();
        set_error_handler( [ $this, "handlePhpError" ] );
    }
    
    static function phpErrorSettings() {

	    error_reporting( -1 );
        ini_set('display_errors', 'On');
        ini_set("log_errors", 1);

        $path = \OWA\Core\CoreAPI::getSetting('base', 'error_log_file');

        if ( self::isSafeLogPath( $path ) ) {
            ini_set("error_log", $path );
        }
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
           $logger = \OWA\Core\CoreAPI::supportClassFactory('base', 'logEmail', $conf);
         $logger->log($body);
    }
}

?>
