<?php
namespace OWA\Core;


/**
 * Utility Functions
 * 
 */
class Lib {

    /**
     * Translate a module's runtime name to its on-disk PSR-4 directory segment.
     *
     * The module runtime name ($module->name, the DB persist key, the dotted-id
     * prefix, the ?do= route segment) stays lowercase forever -- e.g. 'base',
     * 'maxmind_geoip', 'sqs'. Its filesystem home is the PascalCase PSR-4
     * namespace segment: 'Base', 'MaxmindGeoip', 'Sqs'. This is the single seam
     * that maps one to the other: upper-case the first letter of each
     * underscore-delimited word, then drop the underscores.
     *
     *   base          -> Base
     *   maxmind_geoip -> MaxmindGeoip
     *   sqs           -> Sqs
     *
     * The transform is idempotent on its own output ('Base' -> 'Base'). Only the
     * PascalCase directory is read.
     *
     * @param string $name lowercase module runtime name
     * @return string PascalCase directory / namespace segment
     */
    public static function moduleDirName($name) {
        static $cache = [];
        if ( isset( $cache[ $name ] ) ) {
            return $cache[ $name ];
        }
        return $cache[ $name ] = str_replace( '_', '', ucwords( $name, '_' ) );
    }

    /**
     * Convert Associative Array to String
     *
     * @param string $inner_glue
     * @param string $outer_glue
     * @param array<scalar|null> $array key => value
     * @return string
     */
    public static function implode_assoc($inner_glue, $outer_glue, $array) {
       $output = [];
       foreach( $array as $key => $item ) {
              $output[] = $key . $inner_glue . $item;
        }

        return implode($outer_glue, $output);
    }


    /**
     * Array of Current Time
     *
     * @param  int|null $timestamp now when null
     * @return array<string, int|string>  each part as date() gives it, a numeric string; timestamp an int
     * @access public
     */
    public static function time_now( $timestamp = null ) {

        $timestamp = $timestamp ?? time();

        return [

                'year'                 => date("Y", $timestamp),
                'month'             => date("n", $timestamp),
                'day'                 => date("d", $timestamp),
                'dayofweek'         => date("w", $timestamp),
                'dayofyear'         => date("z", $timestamp),
                'weekofyear'        => date("W", $timestamp),
                'hour'                => date("G", $timestamp),
                'minute'             => date("i", $timestamp),
                'second'             => date("s", $timestamp),
                'timestamp'            => $timestamp
            ];
    }

    /**
     * The English noun for a count, for operator output (CLI and log
     * messages), which is outside the catalogue: $one for 1, $many otherwise.
     * A user-facing message uses CoreAPI::tn() instead.
     *
     * @param  int|float $n
     * @param  string    $one
     * @param  string    $many
     * @return string
     */
    public static function plural( $n, $one, $many ) {

        return (int) $n === 1 ? (string) $one : (string) $many;
    }



    /**
     * @return true|null true when this hop arrived over TLS
     */
    public static function isHttps() {
        
        // check for https
        
        // Only signals describing THIS hop count.
        //
        // HTTP_ORIGIN and HTTP_REFERER are not read: both are set by the client and
        // describe where the caller came from, never how the request reached this
        // server, so any caller could tell OWA it was serving over TLS when it was
        // not -- and get_current_url() takes its scheme from here.
        //
        // The forwarding headers are read: a terminating proxy sets them, and that is
        // how TLS reaches OWA in a normal deployment.
        if(
            ( isset( $_SERVER['HTTPS'] ) && strtolower( $_SERVER['HTTPS'] ) == 'on' )
            || ( ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) )
            || ( ( isset( $_SERVER['HTTP_X_FORWARDED_PORT'] ) && $_SERVER['HTTP_X_FORWARDED_PORT'] == 443 ) )
            || ( ( isset( $_SERVER['SERVER_PORT'] ) && $_SERVER['SERVER_PORT'] == 443 ) )
        ) {
            return true;
        }

        return null;
    }
    
    /**
     * The current request's URL as the browser addressed it: public_url, then
     * the request's path below this installation.
     *
     * Behind a reverse proxy on a sub-path, the request reaching PHP is not
     * the one the browser made -- https://example.com/owa/index.php arrives as
     * http://localhost:3508/index.php -- so get_current_url() names a page the
     * browser cannot reach. public_url is where the install says it lives;
     * SCRIPT_NAME says where it sits on this server, and the rest of
     * REQUEST_URI is the same on both sides.
     *
     * For sending the browser back to this page (the login's go=). A request
     * signature is checked against get_current_url(), the URL the request was
     * actually made to.
     *
     * @return string
     */
    public static function currentPublicUrl() {

        $public = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );

        if ( $public === '' ) {

            return self::get_current_url();
        }

        $uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $base = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/' ) ) ), '/' ) . '/';
        $rest = strpos( $uri, $base ) === 0 ? substr( $uri, strlen( $base ) ) : ltrim( $uri, '/' );

        return rtrim( $public, '/' ) . '/' . $rest;
    }

    /**
     * Assembles the current URL from request params
     *
     * @return string
     */
    public static function get_current_url() {
        
        if ( self::isHttps() ) {
            
            $url = 'https';
            
        } else {
            
            $url = 'http';
        }
        
        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            // contains port number
            $domain = $_SERVER['HTTP_HOST'];
        } else {
            // does not contain port number.
            $domain = $_SERVER['SERVER_NAME'];
            if( $_SERVER['SERVER_PORT'] != 80 ) {
                $domain .= ':' . $_SERVER['SERVER_PORT'];
            }
        }

        $url .= '://'.$domain;

        $url .= $_SERVER['REQUEST_URI'];

        return $url;
    }

    /**
     * @param  mixed  $str as the request carried it: only a string can be a name
     * @return string
     */
    public static function fileInclusionFilter($str) {

        // ?do[]=x arrives as an array. Not a name, so nothing: the dispatcher
        // answers an empty one as a bad request, where strpos() below threw.
        if ( ! is_string( $str ) ) {

            return '';
        }

        $str = str_replace("http://", "", $str);
        $str = str_replace("/", "", $str);
        $str = str_replace("\\", "", $str);
        $str = str_replace("../", "", $str);
        $str = str_replace("..", "", $str);
        $str = str_replace("?", "", $str);
        $str = str_replace("%00", "", $str);

        if (strpos($str, '%00')) {
            $str = '';
        }

        if ($str == null) {
            $str = '';
        }

        return $str;
    }

    /**
     * Generic Factory method
     *
     * @param string $class_dir
     * @param string $class_prefix
     * @param string $class_name
     * @param array<mixed> $constructorArguments the constructor's one argument
     * @param string $class_suffix
     * @return object
     */
    public static function factory($class_dir, $class_prefix, $class_name, $constructorArguments = [], $class_suffix = '') {

        $class = $class_prefix . $class_name . $class_suffix;

        /*
         * PSR-4 FIRST, from the directory the caller already named.
         *
         * modules/Base/Classes/ + 'event' -> OWA\Module\Base\Classes\Event.
         * The directory says exactly where the class lives, so this needs
         * nothing registered anywhere.
         *
         * Only when the caller passed its parts. A whole built name passed as
         * $class_name with an empty prefix gives no candidate, and falls
         * through to the name as given.
         */
        $nsClass = self::conventionalClass($class_dir, $class_prefix, $class_name, $class_suffix);

        if ($nsClass !== null) {
            return new $nsClass($constructorArguments);
        }

        // THEN the name as given: a fully-qualified class, or one already
        // loaded (a third-party database driver). Nothing is required in
        // by file path.
        if (!class_exists($class)) {
            throw new \Exception(sprintf(
                'No class %s in %s. A class must be PSR-4 autoloadable; the pre-PSR-4 '
              . 'owa_* file convention was removed in v2.0 (UPGRADING.md).', $class, $class_dir));
        }

        return new $class($constructorArguments);
    }

    /**
     * An instance of a class named in full, which must be autoloadable.
     *
     * @param string $class_name
     * @param string $file_path  ignored: a class file path, which callers written before PSR-4 still pass
     * @param mixed  $args       the constructor's argument, whatever that class takes
     * @return object
     */
    public static function simpleFactory( $class_name, $file_path = '', $args = '' ) {

        if ( ! class_exists( $class_name ) ) {

            throw new \Exception( sprintf(
                'No class %s. A class must be PSR-4 autoloadable; the pre-PSR-4 '
              . 'owa_* file convention was removed in v2.0 (UPGRADING.md).', $class_name ) );
        }

        return new $class_name( $args );
    }

    /**
     * The PSR-4 class a factory's parts point at, or null.
     *
     * Derived from the DIRECTORY rather than guessed: modules/Base/Classes/
     * is OWA\Module\Base\Classes, and Core/Db/ is OWA\Core\Db. A caller that
     * passes a directory it does not own, or a name already carrying its
     * prefix, produces a candidate that does not exist -- and gets null, so
     * the caller falls back rather than failing.
     *
     * @param  string $class_dir    filesystem directory the class lives in
     * @param  string $class_prefix e.g. 'owa_', stripped for the class name
     * @param  string $class_name   e.g. 'event'
     * @param  string $class_suffix e.g. 'Controller'
     * @return string|null
     */
    public static function conventionalClass($class_dir, $class_prefix, $class_name, $class_suffix = '') {

        $name = (string) $class_name;

        // A name that still carries the prefix is a caller handing over an
        // already-built class name; there are no parts to work from.
        if ($name === '' || strncmp($name, 'owa_', 4) === 0) {
            return null;
        }

        $dir = str_replace('\\', '/', (string) $class_dir);
        $dir = trim($dir, '/');

        // Everything from 'modules/' or 'Core/' onwards names the namespace.
        if (preg_match('#(?:^|/)modules/(.+)$#', $dir, $m)) {
            $ns = 'OWA\\Module\\' . str_replace('/', '\\', trim($m[1], '/'));
        } elseif (preg_match('#(?:^|/)(Core(?:/.*)?)$#', $dir, $m)) {
            $ns = 'OWA\\' . str_replace('/', '\\', trim($m[1], '/'));
        } else {
            return null;
        }

        // snake_case to PascalCase -- the transform the tree is laid out by, and
        // the one moduleDirName() applies to directories. A name already camel
        // just gains its capital: 'customReports' -> 'CustomReports'.
        $pascal = str_replace('_', '', ucwords($name, '_'));

        $class = $ns . '\\' . $pascal . $class_suffix;

        return class_exists($class) ? $class : null;
    }

    /**
     * 302 HTTP redirect the user to a new url
     *
     * @param string $url
     * @param list<string> $also_hosts see resolveRedirectUrl()
     * @return null
     */
    public static function redirectBrowser($url, array $also_hosts = []) {

        // 302 redirect to URL
        header ('Location: '.\OWA\Core\Lib::resolveRedirectUrl( $url, $also_hosts ));
        header ('HTTP/1.0 302 Found');

        return null;
    }

    /**
     * Resolves a redirect target against this installation.
     *
     * Some callers pass a destination that originated on the request -- the
     * login 'go' parameter is read straight back off the query string -- so
     * where the browser ends up is decided here rather than taken on trust.
     * A target that does not resolve within this installation is replaced with
     * its base URL.
     *
     * Applied here rather than at each call site so every redirect is covered.
     *
     * $also_hosts are other hosts the CALLER vouches for, from data it holds
     * rather than from the request: the overlay launcher opens a page on the
     * tracked site, whose host it has checked against the Profile's domain.
     *
     * @param  string   $url
     * @param  string[] $also_hosts
     * @return string
     */
    public static function resolveRedirectUrl( $url, array $also_hosts = [] ) {

        $base = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );
        $url  = trim( (string) $url );

        if ( $url === '' ) {

            return $base;
        }

        /*
         * No control character anywhere. A browser removes a tab or a newline
         * from inside a URL before it reads it, so "/<tab>/evil.example" passed
         * every check below as a path and arrived as "//evil.example". A space
         * is not one: a browser encodes it rather than dropping it.
         */
        if ( preg_match( '/[\x00-\x1f\x7f]/', $url ) ) {

            return $base;
        }

        // Backslashes are treated as separators by some browsers, so normalise
        // before looking at the leading characters.
        $probe = str_replace( '\\', '/', $url );

        // '//host/path' carries no scheme but still resolves to another host.
        if ( strpos( $probe, '//' ) === 0 ) {

            return $base;
        }

        $parts = parse_url( $probe );

        if ( $parts === false ) {

            return $base;
        }

        // Only the schemes the app is served over are pages. Checked before the
        // host, because a scheme like 'javascript:' parses with no host at all
        // and would otherwise be mistaken for a relative path.
        $scheme = strtolower( $parts['scheme'] ?? '' );

        if ( $scheme !== '' && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {

            return $base;
        }

        // No host means a path relative to this installation.
        if ( empty( $parts['host'] ) ) {

            /*
             * A scheme with no host is not a path. From an https page a
             * browser reads "http:evil.example" as http://evil.example/: the
             * scheme differs from the page's, so what follows is the host.
             */
            return $scheme === '' ? $url : $base;
        }

        foreach ( $also_hosts as $host ) {

            if ( strcasecmp( $parts['host'], (string) $host ) === 0 ) {

                return $url;
            }
        }

        $base_parts = parse_url( $base );

        if ( empty( $base_parts['host'] ) ) {

            return $base;
        }

        $same_host = strcasecmp( $parts['host'], $base_parts['host'] ) === 0;
        $same_port = ( $parts['port'] ?? null ) === ( $base_parts['port'] ?? null );

        if ( $same_host && $same_port ) {

            return $url;
        }

        return $base;
    }

    /**
     * Each key found in $new_keys renamed to its value there.
     *
     * @template V
     * @param  array<V> $array
     * @param  array<array-key> $new_keys old key => new key
     * @return array<V>
     */
    public static function rekeyArray($array, $new_keys) {

        $new_keys = $new_keys;
        $new_array = [];
        foreach ($array as $k => $v) {

            if (array_key_exists($k, $new_keys)) {
                $k = $new_keys[$k];
            }

            $new_array[$k] = $v;
        }

        return $new_array;
    }


    /**
     * The entries whose name starts with $ns, that prefix removed; all of
     * them when $ns is empty.
     *
     * @template V
     * @param  array<V> $params
     * @param  string   $ns
     * @return array<V>
     */
    public static function stripParams($params, $ns = '') {

        $striped_params = [];

        if (!empty($ns)) {

            $len = strlen($ns);

            foreach ($params as $n => $v) {

                // The namespace has to be a PREFIX, not merely present: a host page's
                // 'my_owa_setting' carries 'owa_' and is not OWA's. Anchoring it is also what
                // makes the namespaced and bare param sets exact complements, which
                // RequestContainer relies on.
                if ( strpos( (string) $n, $ns ) !== 0 ) {
                    continue;
                }

                // strip the namespace value
                $striped_n = substr($n, $len);

                // a param named exactly the namespace has no name left
                if ($striped_n === '') {
                    continue;
                }

                //add to striped array
                $striped_params[$striped_n] = $v;
            }

            return $striped_params;

        } else {

            return $params;
        }

    }

    /**
     * redirects borwser to a particular view
     *
     * @param array<mixed> $data the controller's data container
     * @return null
     */
    public static function redirectToView($data) {
        $c = \OWA\Core\CoreAPI::configSingleton();
        $config = $c->fetch('base');

        $get = self::buildRedirectQuery(
            $data,
            '',
            [ 'view_method', 'auth_status' ]
        );

        $new_url = sprintf($config['link_template'], $config['main_url'], $get);

        \OWA\Core\Lib::redirectBrowser($new_url);

        return null;
    }

    /**
     * The query string a redirect-to-view carries, as trailing-& pairs.
     *
     * A query string holds scalars. A value that is not one -- an array or an
     * object still in the controller's data container -- is dropped: it could
     * never survive the trip, and concatenated it would write "Array" into the
     * URL.
     *
     * Names and values are encoded, so a value holding & or = or a space cannot
     * rewrite the rest of the query string.
     *
     * Separated from redirectToView() because that method ends in a Location
     * header, which a test cannot read. This half is the part with the rules in
     * it, and it is pure.
     *
     * @param  array<mixed> $data     the controller's data container
     * @param  string $ns             application namespace to prefix each name with
     * @param  list<string> $control_params names that steer the framework, not the view
     * @return string
     */
    public static function buildRedirectQuery( $data, $ns = '', $control_params = [] ) {

        $get = '';

        foreach ( (array) $data as $n => $v ) {

            if ( in_array( $n, $control_params ) ) {
                continue;
            }

            if ( is_array( $v ) || is_object( $v ) ) {
                continue;
            }

            $get .= rawurlencode( $ns . $n ) . '=' . rawurlencode( (string) $v ) . '&';
        }

        return $get;
    }

    /**
     * A 63-bit id derived from the content, for the wide-hash scheme.
     *
     * WHY NOT THE OBVIOUS hexdec( substr( sha1( $s ), 0, 16 ) )
     * ---------------------------------------------------------
     * Sixteen hex characters is a full 64 bits, and PHP_INT_MAX is 2^63-1. So
     * hexdec() returns a FLOAT for roughly half of all inputs, and casting that
     * float to int wraps it negative. Worse, a float carries 53 bits of
     * mantissa, so the low bits are gone before the cast. And casting an out-of-range float to int is
     * explicitly undefined in PHP: it happens to wrap consistently on x86-64,
     * but nothing guarantees that across versions or platforms, which matters
     * because OWA supports several nodes deriving ids for the same content.
     *
     * Building the value from two 32-bit halves keeps every intermediate inside
     * the integer range, so no float is ever created and no precision is lost.
     * Masking the top bit off the high half leaves 63 bits: always positive,
     * always inside signed BIGINT.
     *
     * Key space is 2^63. A 50/50 chance of a single collision arrives at about
     * 3.6 billion distinct values, against roughly 77,000 for the crc32 scheme.
     *
     * @param string $string
     * @return int
     */
    public static function wideStringGuid( $string ) {

        $hex = substr( sha1( strtolower( $string ) ), 0, 16 );

        // Two 8-character halves: 32 bits each, so hexdec() returns an int.
        $high = hexdec( substr( $hex, 0, 8 ) ) & 0x7FFFFFFF;   // drop the sign bit
        $low  = hexdec( substr( $hex, 8, 8 ) );

        return ( $high << 32 ) | $low;
    }

    /**
     * The schema version at which dimension ids became 63-bit.
     *
     * An installation whose stored version is BELOW this predates the change and
     * still holds crc32 ids, whether or not it has applied the update yet.
     */
    const WIDE_GUID_SCHEMA_VERSION = 16;

    /**
     * Should this installation still derive the old 32-bit crc32 ids?
     *
     * TWO WAYS TO SAY YES, AND BOTH ARE NEEDED.
     *
     * The flag is the durable answer: Update016 sets it on an existing
     * installation, and the migration command removes it once every id has been
     * re-derived, at which point the installation falls through to 63-bit.
     *
     * The schema check covers the gap the flag cannot. New code lands on disk
     * before anyone runs cmd=update, and tracking does not stop for a pending
     * update -- log.php keeps ingesting the moment the files are replaced. In
     * that window an existing installation has no flag yet, so the flag alone
     * would have it derive 63-bit ids against crc32 history. Reading the stored
     * schema version closes the window: it is
     * already in the settings loaded at boot, so this costs no query.
     *
     * A missing version means an installation being created right now, which
     * has no history to be consistent with and starts wide.
     *
     * @return bool
     */
    public static function useNarrowGuid() {

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'use_32bit_hash' ) ) {

            return true;
        }

        $applied = \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

        return $applied && (int) $applied < self::WIDE_GUID_SCHEMA_VERSION;
    }

    /**
     * Create guid from string
     *
     * @param string|null $string
     * @param bool $allow_falsy Hash '0' rather than treating it as no value.
     *        The guard below is a truthiness test, so the legitimate string '0'
     *        reads as absent and this returns null -- which reaches a BIGINT
     *        foreign key as 0, an id no row carries. Nothing in OWA passes
     *        true; the parameter is for modules that derive ids of their own.
     * @return int|string|null a string of digits; crc32's int on a narrow-id
     *        install (useNarrowGuid())
     */
    public static function setStringGuid($string, $allow_falsy = false) {

        if ( $allow_falsy ? ( $string !== '' && $string !== null ) : (bool) $string ) {


            if ( self::useNarrowGuid() ) {
                // make 32 bit ID from crc32
                return crc32( strtolower( $string ) );
            }

            return (string) self::wideStringGuid( $string );
        }

        return null;
    }

    /**
     * The reverse of implode_assoc().
     *
     * @param  string $string_state
     * @param  string $inner
     * @param  string $outer
     * @return array<string>|string key => value; $string_state itself when it holds no $outer
     */
    public static function assocFromString($string_state, $inner = '=>', $outer = '|||') {

        $state = [];

        if (!empty($string_state)):

            if (strpos($string_state, $outer) === false):

                return $string_state;

            else:

                $array = explode($outer, $string_state);

                foreach ($array as $key => $value) {

                    list($realkey, $realvalue) = explode($inner, $value);
                    $state[$realkey] = $realvalue;

                }

            endif;

        endif;

        return $state;


    }

    /**
     * Create a directory, and each missing parent, group-writable (0775)
     * whatever the umask.
     *
     * For directories both the web server user and the account running cli.php
     * write to: one created 0755 by either shuts the other out. The mode is set
     * with chmod() because mkdir()'s is reduced by the umask, as
     * Base\Classes\Error sets its log file's.
     *
     * @param  string $dir
     * @return bool whether the directory exists afterwards
     */
    public static function makeSharedDir( $dir ) {

        $dir = rtrim( (string) $dir, '/' );

        if ( $dir === '' ) {

            return false;
        }

        $missing = [];

        for ( $d = $dir; $d !== '' && $d !== '.' && $d !== '/' && ! is_dir( $d ); $d = dirname( $d ) ) {

            $missing[] = $d;
        }

        foreach ( array_reverse( $missing ) as $d ) {

            if ( @mkdir( $d, 0775 ) ) {

                @chmod( $d, 0775 );

            } elseif ( ! is_dir( $d ) ) {

                // Not made, and not made by a concurrent request either.
                return false;
            }
        }

        return is_dir( $dir );
    }

    /**
     * Lists all files in a Directory
     *
     * @param  string $start_dir
     * @param  bool   $recursive
     * @return list<array{name: string, path: string}>|false false when $start_dir is not a directory
     */
    public static function listDir($start_dir='.', $recursive = true) {

        $files = [];

        if (is_dir($start_dir)):

            $fh = opendir($start_dir);

            while (($file = readdir($fh)) !== false) {

                // loop through the files, skipping . and .., and recursing if necessary
                if (strcmp($file, '.')==0 || strcmp($file, '..')==0) continue;
                $filepath = $start_dir . '/' . $file;


                if (is_dir($filepath)):
                    if ($recursive === true):
                        $files = array_merge($files, \OWA\Core\Lib::listDir($filepath));
                    endif;
                else:
                    array_push($files, ['name' => $file, 'path' => $filepath]);
                endif;
            }

            closedir($fh);

        else:
            // false if the function was called with an invalid non-directory argument
            $files = false;
        endif;

      return $files;

    }

    /**
     * @param  string $string
     * @return string|null null on a PCRE error
     */
    public static function escapeNonAsciiChars($string) {

        return preg_replace('/[^(\x20-\x7F)]*/','', $string);
    }

    /**
     * Truncate string
     *
     * @param string $str
     * @param integer $length
     * @param string $trailing
     * @return string
     */
    public static function truncate ($str, $length=10, $trailing='...')  {

        // take off chars for the trailing
        $length-=strlen($trailing);
        if (strlen($str) > $length):
            // string exceeded length, truncate and add trailing dots
             return substr($str,0,$length).$trailing;
        else:
            // string was already short enough, return the string
            $res = $str;
          endif;
   
      return $res;
    }

    /**
     * Simple Password Encryption
     *
     * @param string $password
     * @return string
     */
    public static function encryptOldPassword($password) {

        return md5(strtolower($password).strlen($password));
    }
    /**
     * @param  string $password
     * @return string
     */
    public static function encryptPassword($password) {

        return password_hash( $password, PASSWORD_DEFAULT );
    }

    /**
     * @param  string $hash_type an algorithm hash_hmac() knows; md5 when empty
     * @param  string $data
     * @param  string $salt
     * @return string
     */
    public static function hash( $hash_type, $data, $salt = '' ) {
        
        if ( ! $hash_type ) {
            
            $hash_type = 'md5';
        }
        
        return hash_hmac( $hash_type, $data, $salt );
    }

    /**
     * Turn command-line arguments into a parameter map.
     *
     * Three spellings, because a switch and a value want different shapes and
     * older invocations must keep working:
     *
     *   --switch        true          nobody writes switch=0; they leave it off
     *   --key=value     'value'
     *   key=value       'value'       the original form
     *
     * A leading -- is stripped from the name.
     *
     * @param list<string> $argv raw arguments, including the script name at index 0
     * @return array<string|true>|string the parameter map, or a message describing
     *                      the first argument that could not be read
     */
    public static function parseCliArgs( $argv ) {

        $params = [];

        for ( $i = 1; $i < count( $argv ); $i++ ) {

            $arg       = $argv[ $i ];
            $is_switch = ( strpos( $arg, '--' ) === 0 );

            if ( $is_switch ) {

                $arg = substr( $arg, 2 );
            }

            $it = explode( '=', $arg, 2 );

            if ( count( $it ) === 2 && $it[0] !== '' ) {

                $params[ $it[0] ] = $it[1];

                continue;
            }

            if ( $is_switch && $it[0] !== '' ) {

                $params[ $it[0] ] = true;

                continue;
            }

            return sprintf(
                "Invalid argument '%s'. Use key=value, or --switch for a flag", $argv[ $i ]
            );
        }

        return $params;
    }

    /**
     * @param  string|null $type html, xml, json or csv; html when empty
     * @return void
     */
    public static function setContentTypeHeader($type = 'html') {

        if (!$type) {
            $type = 'html';
        }

        $content_types = ['html' => 'text/html',
                               'xml' => 'text/xml',
                               'json' => 'application/json',
                               'csv' => 'text/csv'];

        if (array_key_exists($type, $content_types)) {
            $mime = $content_types[$type];
            header('Content-type: '.$mime);
        }
    }

    /**
     * @param  string $string
     * @return string
     */
    public static function utf8Encode($string) {

        $string = (string) $string;

        // ASCII is UTF-8. checkForUtf8() answers no for it (mb_detect_encoding
        // calls it ASCII), so it is not the test here.
        $valid = function_exists( 'mb_check_encoding' )
            ? mb_check_encoding( $string, 'UTF-8' )
            : \OWA\Core\Lib::checkForUtf8( $string );

        if ( $valid ) {

            return $string;
        }

        /*
         * Not UTF-8, so read as Windows-1252: the Latin-1 superset browsers and
         * mail clients send when they are not sending UTF-8, so an "é" stays an
         * "é". mb_detect_encoding() is no use here: it answers false for
         * Latin-1 bytes.
         */
        if ( function_exists( 'mb_convert_encoding' ) ) {

            return mb_convert_encoding( $string, 'UTF-8', 'Windows-1252' );
        }

        if ( function_exists( 'iconv' ) ) {

            $converted = @iconv( 'Windows-1252', 'UTF-8//TRANSLIT', $string );

            if ( $converted !== false ) {

                return $converted;
            }
        }

        return preg_replace( '/[\x80-\xFF]/', '?', $string );
    }

    /**
     * @param  string $str
     * @return bool|null null where mb_detect_encoding() exists and $str is not UTF-8
     */
    public static function checkForUtf8($str) {

        if ( function_exists( 'mb_detect_encoding' ) ) {
            $cur_encoding = mb_detect_encoding( $str ) ;
            if ( $cur_encoding == "UTF-8" && mb_check_encoding( $str,"UTF-8" ) ) {
                return true;
            }
        } else {

            $len = strlen( $str );
            for( $i = 0; $i < $len; $i++ ) {

                $c = ord( $str[$i] );
                if ($c > 128) {

                    if ( ( $c > 247 ) ) {
                        return false;
                    } elseif ( $c > 239 ) {
                        $bytes = 4;
                    } elseif ( $c > 223 ) {
                        $bytes = 3;
                    } elseif ( $c > 191 ) {
                        $bytes = 2;
                    } else {
                        return false;
                    }

                    if ( ( $i + $bytes ) > $len ) {
                        return false;
                    }

                    while ( $bytes > 1 ) {
                        $i++;
                        $b = ord( $str[$i] );
                        if ( $b < 128 || $b > 191 ) {
                            return false;
                        }
                        $bytes--;
                    }
                }
            }
            return true;
        }

        return null;
    }

    /**
     * @param  int|float $value    minor units
     * @param  string    $local    an ICU locale
     * @param  string    $currency ISO 4217 code
     * @return string|false false when NumberFormatter cannot format it
     */
    public static function formatCurrency($value, $local, $currency) {

        // Minor units back to major, by the currency's own decimal places: there
        // is no minor unit of the yen, and a thousand to the Kuwaiti dinar.
        $value = \OWA\Module\Base\Classes\Currency::toMajorUnits( $value, $currency );

        if ( function_exists('numfmt_create') ) {

            $numberFormatter = new \NumberFormatter($local, \NumberFormatter::CURRENCY);
            return $numberFormatter->formatCurrency($value, $currency);

        } else {

            // Fallback for hosts without intl.
            return $currency . ' ' . number_format( $value, \OWA\Module\Base\Classes\Currency::exponent( $currency ) );
        }
    }

    /**
     * @param  string $string
     * @return string
     */
    public static function crc32AsHex($string) {
        $crc = crc32($string);
        if ($crc < 0) {
            $crc = 0xFFFFFFFF + $crc + 1;
        }
        return dechex($crc);
    }

    /**
     * @param  string $domain
     * @return string the domain with a leading period and no port
     */
    public static function sanitizeCookieDomain($domain) {

        // Remove port information.
         $port = strpos( $domain, ':' );
        if ( $port ) {
            $domain = substr( $domain, 0, $port );
        }

        // check for leading period, add if missing
        $period = substr( $domain, 0, 1);
        if ( $period != '.' ) {
            $domain = '.'.$domain;
        }

        return $domain;
    }

    /**
     * Where a call came from: the first frame outside the given files, as a
     * path under the install and a line. For a notice that should name the
     * caller rather than the place that noticed.
     *
     * @param  string[] $skip basenames of the files to look past
     * @return string   'path:line', or '' when every frame is skipped
     */
    public static function callSiteOutside( array $skip ) {

        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {

            $file = $frame['file'] ?? '';

            if ( $file === '' || $file === __FILE__ || in_array( basename( $file ), $skip, true ) ) {
                continue;
            }

            $root = \OWA\Core\Env::root() . '/';

            return ( strpos( $file, $root ) === 0 ? substr( $file, strlen( $root ) ) : $file )
                . ':' . ( $frame['line'] ?? 0 );
        }

        return '';
    }

    /**
     * empty(), except that 0, 0.0 and "0" count as values.
     *
     * @param  mixed $value any type
     * @return true|null true when empty() and not numeric
     */
    public static function isEmpty($value) {

        if ( empty( $value ) && ! is_numeric( $value ) ) {
            
            return true;
        }

        return null;
    }

    /**
     * A new random id: a positive integer, as a string of digits.
     *
     * 63 bits, so it fits a signed BIGINT. $narrow gives 32 bits, the width of
     * the crc32 ids an install still on narrow ids derives (useNarrowGuid()).
     *
     * @param bool $narrow
     * @return string
     */
    public static function randomId( $narrow = false ) {

        return (string) random_int( 1, $narrow ? 0xFFFFFFFF : PHP_INT_MAX );
    }

    /**
     * The URL parse_url() read, less the parts named in $ommit.
     *
     * @param  array<string, int|string>|false $parsed_url parse_url()'s result
     * @param  list<string> $ommit part names: scheme, host, port, user, pass, path, query, fragment
     * @return string
     */
    public static function unparseUrl($parsed_url, $ommit = [] ) {

        $url = '';
        $p = [];

        $p['scheme']   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $p['host']     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $p['port']     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $p['user']     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $p['pass']     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $p['pass']     = ( $p['user'] || $p['pass'] ) ? $p['pass']."@" : '';
        $p['path']     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $p['query']    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $p['fragment'] = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        if ( $ommit ) {
            foreach ( $ommit as $key ) {
                if ( isset( $p[ $key ] ) ) {
                    $p[ $key ] = '';
                }
            }
        }

          return $p['scheme'].$p['user'].$p['pass'].$p['host'].$p['port'].$p['path'].$p['query'].$p['fragment'];
    }
    
    /**
     * Query parameters whose values are credentials: the API key and request
     * signature, the overlay token, a password and the password-reset key (k),
     * each also with the owa_ prefix.
     */
    const LOG_REDACTED_PARAMS = [ 'apiKey', 'signature', 'overlayToken', 'password', 'k' ];

    /**
     * A URL as written to the log: each LOG_REDACTED_PARAMS value replaced
     * by [redacted].
     *
     * @param  string $url
     * @return string
     */
    public static function redactUrlForLog( $url ) {

        $names = implode( '|', array_map( function ( $n ) { return preg_quote( $n, '/' ); }, self::LOG_REDACTED_PARAMS ) );

        return (string) preg_replace( '/([?&])((?:owa_)?(?:' . $names . '))=[^&#]*/i', '$1$2=[redacted]', (string) $url );
    }

    /**
     * @param  string $ip_address
     * @return string|false|null the address with its host part zeroed; null when it
     *         is neither IPv4 nor IPv6
     */
    public static function anonymizeIp( $ip_address ) {
        
        $ipv4NetMask = "255.255.255.0";
        $ipv6NetMask = "ffff:ffff:ffff:ffff:0000:0000:0000:0000";
        
        $packed_address = inet_pton( $ip_address);

        if ( strlen( $packed_address ) == 4 ) {
            
            return inet_ntop( inet_pton( $ip_address ) & inet_pton( $ipv4NetMask ) );
            
        } elseif ( strlen( $packed_address ) == 16 ) {
            
            return inet_ntop( inet_pton( $ip_address ) & inet_pton( $ipv6NetMask ) );
        }

        return null;
    }
    
    /**
     * Check to see if the IP address falls within known private IP ranges.
     *
     * @param  string $ip_address
     * @return string|false the address when public; false when private, reserved or not one
     */
    public static function isNotPrivateIp( $ip_address ) {

        return filter_var( $ip_address, FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ] );
    }
    
    /**
     * @param  array-key    $key
     * @param  array<mixed> $array
     * @return true|null
     */
    public static function keyExistsNotEmpty( $key, $array ) {
        
        if ( array_key_exists($key, $array) && ! empty( $array[ $key ] ) )  {
            
            return true;
        }

        return null;
    }
    
    /**
     * $params over $defaults, a falsy param leaving its default.
     *
     * @param  mixed $defaults only an array is merged
     * @param  mixed $params   only an array is merged
     * @return array<mixed>|null null unless both are arrays
     */
    public static function setDefaultParams( $defaults, $params ) {
        
        if ( is_array( $defaults ) && is_array( $params ) ) {
        
            return array_merge( $defaults, array_filter( $params) );
        }

        return null;
    }
    
    /**
     * A value as ONE log line: JSON, capped at $max characters.
     *
     * An object is named by its class; an event also by its type and property
     * NAMES, never its values, since those are a visitor's data.
     *
     * @param  mixed $value
     * @param  int   $max
     * @return string
     */
    public static function forLog( $value, $max = 1000 ) {

        if ( is_string( $value ) ) {

            $text = $value;

        } elseif ( $value instanceof \OWA\Module\Base\Classes\Event ) {

            $text = sprintf( '%s event, properties: %s', $value->getEventType(),
                implode( ', ', array_keys( (array) $value->getProperties() ) ) );

        } elseif ( is_object( $value ) && ! $value instanceof \JsonSerializable ) {

            $text = get_class( $value );

        } else {

            $text = json_encode( $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );

            if ( $text === false ) {

                $text = gettype( $value );
            }
        }

        if ( strlen( $text ) > $max ) {

            $text = substr( $text, 0, $max ) . sprintf( '... (%d bytes)', strlen( $text ) );
        }

        return $text;
    }

    /**
     * Debug mode: define('OWA_DEBUG', true) in owa-config.php. Writes debug
     * lines to the log, routes PHP's own errors there, and has the tracker
     * log to the browser console.
     *
     * @return bool
     */
    public static function inDebug() {

        return defined( 'OWA_DEBUG' ) && OWA_DEBUG === true;
    }
    

}
