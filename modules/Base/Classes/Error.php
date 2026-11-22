<?php
namespace OWA\Module\Base\Classes;

/**
 * OWA's log: one file (base.error_log_file), and STDOUT as well under the CLI.
 *
 * Written directly rather than through a logging library. All OWA ever used
 * one for was a single file stream with a level threshold and a line format,
 * and a library on the boot path meant a checkout without vendor/ could log
 * nothing at all.
 *
 * The threshold is notice, or debug when OWA_DEBUG is true (Lib::inDebug()).
 */
class Error {

    /** Each priority logMsg() writes, by rank; a message below the threshold is not written. */
    const LEVELS = [
        'debug'     => 100,
        'info'      => 200,
        'notice'    => 250,
        'warning'   => 300,
        'error'     => 400,
        'critical'  => 500,
        'alert'     => 550,
        'emergency' => 600,
    ];

    /**
     * Where messages go once the handler is set: file paths and, under the
     * CLI, the STDOUT stream. Opened on the first message that is written.
     *
     * @var list<array{path: string|null, stream: resource|false|null}>|null  null until opened; a stream fopen() failed to open is false
     */
    private $sinks = null;

    /**
     * Messages logged before the configuration was loaded.
     *
     * @var list<array{error: mixed, priority: string}>|null  null until the first
     */
    public $bmsgs;

    /** @var bool whether setHandler() has run */
    public $init = false;

    /**
     * Call once the configuration is loaded: it decides where the log is and
     * at what level. Flushes what was logged before.
     *
     * Under OWA_DEBUG, PHP's own errors are routed to the log too.
     *
     * @param mixed $type ignored; once 'development' or 'production', now OWA_DEBUG decides
     * @return void
     */
    public function setHandler( $type = null ) {

        if ( \OWA\Core\Lib::inDebug() ) {

            $this->logPhpErrors();
        }

        set_exception_handler( [ $this, 'handleUncaughtException' ] );

        $this->init = true;
        $this->logBufferedMsgs();
    }

    /**
     * Open the file (and STDOUT under the CLI), once.
     *
     * @return list<array{path: string|null, stream: resource|false|null}> the open sinks
     */
    private function sinks() {

        if ( $this->sinks !== null ) {

            return $this->sinks;
        }

        $this->sinks = [];

        $path = \OWA\Core\CoreAPI::getSetting( 'base', 'error_log_file' );

        if ( ! self::isSafeLogPath( $path ) ) {

            // refuse to open the file rather than write to an attacker-controlled sink
            error_log( sprintf( 'OWA: refusing unsafe error_log_file value (%s); file logging disabled.', $path ) );

        } else {

            $this->sinks[] = [ 'path' => (string) $path, 'stream' => null ];
        }

        if ( defined( 'OWA_CLI' ) ) {

            $this->sinks[] = [ 'path' => null, 'stream' => defined( 'STDOUT' ) ? STDOUT : fopen( 'php://stdout', 'w' ) ];
        }

        return $this->sinks;
    }

    /**
     * Append one line to a file, creating it group-writable.
     *
     * Both the web server user and the account running the CLI write to this
     * path, so the mode is set rather than left to whichever umask creates the
     * file: a 0644 file from a shell's 022 would shut the web server out. The
     * path embeds the instance hash, so a new file -- and a new race over who
     * creates it -- appears on every credential rotation.
     *
     * @param  string $path
     * @param  string $line
     * @return bool
     */
    private static function appendToFile( $path, $line ) {

        $created = ! file_exists( $path );

        $fh = @fopen( $path, 'a' );

        if ( ! $fh ) {

            return false;
        }

        if ( $created ) {

            @chmod( $path, 0664 );
        }

        // One line at a time from many processes: lock, so lines never interleave.
        @flock( $fh, LOCK_EX );
        $ok = @fwrite( $fh, $line ) !== false;
        @flock( $fh, LOCK_UN );
        fclose( $fh );

        return $ok;
    }

    /**
     * Record an exception nothing else caught, and answer with a server error.
     *
     * Registered by the production handler too, so an uncaught exception is
     * recorded in OWA's own log, where an administrator would look for it,
     * rather than becoming a PHP fatal in the web server's.
     *
     * The status is set deliberately rather than inherited from PHP's fatal, and
     * the response body is left empty: the message and stack trace go to the log,
     * never to the visitor.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function handleUncaughtException( $exception ) {

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

    /**
     * Nothing is formatted unless debug logging is on, which Lib::inDebug()
     * alone decides (see getLogLevel()). $context is appended as one line.
     *
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @param  mixed $context any value
     * @return true|null true when buffered, null otherwise
     */
    public function debug( $message, $context = null ) {

        if ( ! \OWA\Core\Lib::inDebug() ) {

            return null;
        }

        if ( $context !== null ) {

            $message = \OWA\Core\Lib::forLog( $message ) . ' ' . \OWA\Core\Lib::forLog( $context );
        }

        return $this->log( $message, 'debug' );
    }

    /**
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @return true|null true when buffered, null otherwise
     */
    public function info($message) {

        return $this->log($message, 'info');
    }

    /**
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @return true|null true when buffered, null otherwise
     */
    public function notice($message) {

        return $this->log($message, 'notice');
    }

    /**
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @return true|null true when buffered, null otherwise
     */
    public function warning($message) {

        return $this->log($message, 'warning');
    }

    /**
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @return true|null true when buffered, null otherwise
     */
    public function err($message) {

        return $this->log($message, 'error');
    }

    /**
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @return true|null true when buffered, null otherwise
     */
    public function crit($message) {

        return $this->log($message, 'critical');
    }

    /**
     * @param  mixed $message a string, or a value Lib::forLog() formats
     * @return true|null true when buffered, null otherwise
     */
    public function alert($message) {

        return $this->log($message, 'alert');
    }

    /**
     * @param  mixed  $err      a string, or a value Lib::forLog() formats
     * @param  string $priority a key of LEVELS
     * @return true|null true when buffered, null once written
     */
    public function log( $err, $priority = 'notice' ) {


        if ( $this->init) {
            // log to normal loggers
            $this->logMsg($err, $priority);

            return null;

        } else {
            // buffer msgs untill the global config object has been loaded
            // and a proper logger can be setup
            return $this->bufferMsg($err, $priority);
        }
    }
    
    /**
     * @param  mixed  $msg      a string, or a value Lib::forLog() formats
     * @param  string $priority a key of LEVELS
     * @return void
     */
    public function logMsg( $msg, $priority ) {

        if ( ! isset( self::LEVELS[ $priority ] ) || self::LEVELS[ $priority ] < self::LEVELS[ $this->getLogLevel() ] ) {

            return;
        }

        if ( is_object( $msg ) || is_array( $msg ) ) {

            $msg = \OWA\Core\Lib::forLog( $msg );
        }

        $line = sprintf( "[%s] [%d] [%s] %s\n",
            date( $this->getDateTimestamp() ), getmypid(), strtoupper( $priority ), $msg );

        /*
         * A write that fails is dropped, never thrown: logging must not be the
         * thing that turns a request into an error. At shutdown STDOUT can be
         * closed before an object whose destructor still logs (the cache
         * persisting itself), which once made a successful `cmd=core-update-schema` exit 255.
         */
        foreach ( $this->sinks() as $sink ) {

            if ( $sink['path'] !== null ) {

                self::appendToFile( $sink['path'], $line );

            } elseif ( is_resource( $sink['stream'] ) ) {

                @fwrite( $sink['stream'], $line );
            }
        }
    }

    /**
     * @param  mixed  $err      a string, or a value Lib::forLog() formats
     * @param  string $priority a key of LEVELS
     * @return true
     */
    public function bufferMsg($err, $priority) {

        $this->bmsgs[] = ['error' => $err, 'priority' => $priority];
        return true;
    }

    /**
     * @return void
     */
    public function logBufferedMsgs() {

        if (!empty($this->bmsgs)) {

            foreach($this->bmsgs as $msg) {

                $this->log($msg['error'], $msg['priority']);
            }

            $this->bmsgs = [];
        }
    }

    /**
     * The lowest priority written: debug under OWA_DEBUG, notice otherwise.
     *
     * @return string
     */
    public function getLogLevel() {

        return \OWA\Core\Lib::inDebug() ? 'debug' : 'notice';
    }

    /**
     * @return string a date() format
     */
    public function getDateTimestamp() {

        return "H:i:s Y-m-d";
    }

    /**
     * Reject PHP stream wrappers (php://, data://, phar://, expect://, etc.)
     * as log destinations. A quoted-printable / base64 filter wrapper can be
     * abused to decode an attacker-controlled log line into an executable
     * PHP file under the docroot.
     *
     * A plain absolute or relative filesystem path is allowed; anything with
     * a `scheme://` prefix is rejected.
     *
     * @param  mixed $path the error_log_file setting, any type
     * @return bool
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

    /**
     * @return void
     */
    public function logPhpErrors() {

        self::phpErrorSettings();
        set_error_handler( [ $this, "handlePhpError" ] );
    }
    
    /**
     * @return void
     */
    public static function phpErrorSettings() {

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
     * @param int    $errno
     * @param string $errmsg
     * @param string $filename
     * @param int    $linenum
     * @return true  handled: PHP's own handler does not run
     */
    public function handlePhpError($errno, $errmsg, $filename = '', $linenum = 0) {

        $dt = date("Y-m-d H:i:s (T)");
        
        $err = "<errorentry>\n";
        $err .= "\t<datetime>" . $dt . "</datetime>\n";
        $err .= "\t<errornum>" . $errno . "</errornum>\n";
        $err .= "\t<errormsg>" . $errmsg . "</errormsg>\n";
        $err .= "\t<scriptname>" . $filename . "</scriptname>\n";
        $err .= "\t<scriptlinenum>" . $linenum . "</scriptlinenum>\n";

        $err .= "</errorentry>\n\n";

        $this->debug( $err );

        return true;
    }

    /**
     * @param  \Throwable $exception
     * @return void
     */
    public function logException($exception) {

        $msg = $exception->getMessage() . ' // '.$exception->getTraceAsString();

        $this->log( $msg );
    }
}
