<?php
namespace OWA\Core;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html

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
     * 'maxmind_geoip', 'fileCache'. Its filesystem home, however, is now the
     * PascalCase PSR-4 namespace segment: 'Base', 'MaxmindGeoip', 'FileCache'.
     * This is the single seam that maps one to the other. The transform matches
     * exactly how the top-level module namespaces were derived during the PSR-4
     * migration: upper-case the first letter of each underscore-delimited word,
     * then drop the underscores.
     *
     *   base          -> Base
     *   fileCache     -> FileCache      (already camel; ucwords leaves it, _ strip is a no-op)
     *   maxmind_geoip -> MaxmindGeoip
     *   memcachedCache-> MemcachedCache
     *   remoteQueue   -> RemoteQueue
     *
     * BACKWARDS COMPAT (third-party modules): a module shipped in the pre-PSR-4
     * convention still lives in a lowercase directory (modules/mymodule/). If no
     * PascalCase dir exists but a legacy-named one does, resolve to the legacy
     * dir verbatim so the presence check + every path-building factory keep
     * finding it. OWA's own modules always hit the PascalCase branch (the legacy
     * dirs are gone), so this costs them nothing but a cached is_dir() stat.
     * The transform is still idempotent on its own output ('Base' -> 'Base').
     *
     * @param string $name lowercase module runtime name
     * @return string PascalCase directory / namespace segment (or legacy dir)
     */
    public static function moduleDirName($name) {
        static $cache = array();
        if ( isset( $cache[ $name ] ) ) {
            return $cache[ $name ];
        }
        $pascal = str_replace( '_', '', ucwords( $name, '_' ) );

        // Prefer the PSR-4 PascalCase dir; fall back to a legacy lowercase dir
        // that exists as-is (a pre-PSR-4 third-party module).
        if ( defined( 'OWA_MODULES_DIR' )
            && ! is_dir( OWA_MODULES_DIR . $pascal )
            && is_dir( OWA_MODULES_DIR . $name ) ) {
            return $cache[ $name ] = $name;
        }
        return $cache[ $name ] = $pascal;
    }

    /**
     * Convert Associative Array to String
     *
     * @param string $inner_glue
     * @param string $outer_glue
     * @param array $array
     * @return string
     */
    public static function implode_assoc($inner_glue, $outer_glue, $array) {
       $output = array();
       foreach( $array as $key => $item ) {
              $output[] = $key . $inner_glue . $item;
        }

        return implode($outer_glue, $output);
    }

    /**
     * Deconstruct Associative Array
     *
     * For example this takes array([1] => array(a => dog, b => cat), [2] => array(a => sheep, b => goat))
     * and tunrs it into array([a] => array(dog, sheep), [b] => array(cat, goat))
     *
     * @param array $a_array
     * @return array $data_arrays
     * @access public
     */
    public static function deconstruct_assoc($a_array) {
        if (!empty($a_array)):

            $data_arrays = array();

            if(!empty($a_array[1])) :

                foreach ($a_array as $key => $value) {
                    foreach ($value as $k => $v) {
                        $data_arrays[$k][] = $v;

                    }
                }
            else:
                //print_r($a_array[0]);
                foreach ($a_array[0] as $key => $value) {
                    $data_arrays[$key][] = $value;
                }
            endif;

            return $data_arrays;
        else:
            return array();
        endif;
    }


    public static function decon_assoc($a_array) {

        $data_arrays = array();

        foreach ($a_array as $key => $value) {
            //foreach ($value as $k => $v) {
                $data_arrays[$key][] = $value;

            //}
        }

        return $data_arrays;
    }

    /**
     * Array of Current Time
     *
     * @return array
     * @access public
     */
    public static function time_now() {

        $timestamp = time();

        return array(

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
            );
    }

    /**
     * Information array for Months in the year.
     *
     * @return array
     */
    public static function months() {

        return array(

                    1 => array('label' => 'January'),
                    2 => array('label' => 'February'),
                    3 => array('label' => 'March'),
                    4 => array('label' => 'April'),
                    5 => array('label' => 'May'),
                    6 => array('label' => 'June'),
                    7 => array('label' => 'July'),
                    8 => array('label' => 'August'),
                    9 => array('label' => 'September'),
                    10 => array('label' => 'October'),
                    11 => array('label' => 'November'),
                    12 => array('label' => 'December')
        );

    }

    public static function days() {

        return array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14,
                    15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31);
    }

    public static function years() {

        static $years;

        if (empty($years)):

            $start_year = 2005;

            $years = array($start_year);

            $num_years =  date("Y", time()) - $start_year;

            for($i=1; $i<=$num_years; $i++) {

                $years[] = $start_year + $i;
            }

            $years = array_reverse($years);

        endif;

        return $years;
    }


    /**
     * Returns a label from an array of months
     *
     * @param int $month
     * @return string
     */
    public static function get_month_label($month) {

        static $months;

        if (empty($months)):

            $months = \OWA\Core\Lib::months();

        endif;

        return $months[$month]['label'];

    }


    /**
     * Sets the suffix for Days used in Date labels
     * @depricated
     * @param string $day
     * @return string
     */
    public static function setDaySuffix($day) {

        switch ($day) {

            case "1":
                $day_suffix = 'st';
                break;
            case "2":
                $day_suffix = 'nd';
                break;
            case "3":
                $day_suffix = 'rd';
                break;
            default:
                $day_suffix = 'th';
        }

        return $day_suffix;

    }

    /**
     * Generates the label for a date
     * @depricated
     * @param array $params
     * @return string|false
     */
    public static function getDatelabel($params) {

        switch ($params['period']) {

            case "day":
                return sprintf("%s, %d%s %s",
                            \OWA\Core\Lib::get_month_label($params['month']),
                            $params['day'],
                            \OWA\Core\Lib::setDaySuffix($params['day']),
                            $params['year']
                        );
                break;

            case "month":
                return sprintf("%s %s",
                            \OWA\Core\Lib::get_month_label($params['month']),
                            $params['year']
                        );
                break;

            case "year":
                return sprintf("%s",
                            $params['year']
                        );
                break;
            case "date_range":
                return sprintf("%s, %d%s %s - %s, %d%s %s",
                            \OWA\Core\Lib::get_month_label($params['month']),
                            $params['day'],
                            \OWA\Core\Lib::setDaySuffix($params['day']),
                            $params['year'],
                            \OWA\Core\Lib::get_month_label($params['month2']),
                            $params['day2'],
                            \OWA\Core\Lib::setDaySuffix($params['day2']),
                            $params['year2']
                        );
                break;
        }

        return false;

    }

    /**
     * Array of Reporting Periods
     * @depricated
     * @return array
     */
    public static function reporting_periods() {

        return array(

                    'today' => array('label' => 'Today'),
                    'yesterday' => array('label' => 'Yesterday'),
                    'this_week' => array('label' => 'This Week'),
                    'this_month' => array('label' => 'This Month'),
                    'this_year' => array('label' => 'This Year'),
                    'last_week'  => array('label' => 'Last Week'),
                    'last_month' => array('label' => 'Last Month'),
                    'last_year' => array('label' => 'Last Year'),
                    'last_half_hour' => array('label' => 'The Last 30 Minutes'),
                    'last_hour' => array('label' => 'Last Hour'),
                    'last_24_hours' => array('label' => 'The Last 24 Hours'),
                    'last_seven_days' => array('label' => 'The Last Seven Days'),
                    'last_thirty_days' => array('label' => 'The Last Thirty Days'),
                    'same_day_last_week' => array('label' => 'Same Day last Week'),
                    'same_week_last_year' => array('label' => 'Same Week Last Year'),
                    'same_month_last_year' => array('label' => 'Same Month Last Year'),
                    'date_range' => array('label' => 'Date Range')
        );

    }

    /**
     * Array of Date specific Reporting Periods
     * @depricated
     * @return array
     */
    public static function date_reporting_periods() {

        return array(

                    'day' => array('label' => 'Day'),
                    'month' => array('label' => 'Month'),
                    'year' => array('label' => 'Year'),
                    'date_range' => array('label' => 'Date Range')
        );

    }

    /**
     * Gets label for a particular reporting period
     *
     * @param mixed $period
     * @return unknown
     */
    public static function get_period_label($period) {

        $periods = \OWA\Core\Lib::reporting_periods();

        return $periods[$period]['label'];
    }
	
	public static function isHttps() {
		
		// check for https
		
        if(
        	( isset( $_SERVER['HTTPS'] ) && strtolower( $_SERVER['HTTPS'] ) == 'on' )
        	|| ( ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) )
        	|| ( ( isset( $_SERVER['HTTP_X_FORWARDED_PORT'] ) && $_SERVER['HTTP_X_FORWARDED_PORT'] == 443 ) )
        	|| ( ( isset( $_SERVER['SERVER_PORT'] ) && $_SERVER['SERVER_PORT'] == 443 ) )
        	|| ( ( isset( $_SERVER['HTTP_ORIGIN'] ) && substr( $_SERVER['HTTP_ORIGIN'], 0, 5 ) === 'https' ) )
			|| ( ( isset( $_SERVER['HTTP_REFERER'] ) && substr( $_SERVER['HTTP_REFERER'], 0, 5 ) === 'https' ) )
		) {
			return true;
		}
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

    public static function inputFilter($input, $options = array() ) {

        return \OWA\Module\Base\Classes\Sanitize::cleanInput( $input, $options );
    }

    public static function fileInclusionFilter($str) {

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
     * @param array $constructorArguments
     * @return object
     */
    public static function factory($class_dir, $class_prefix, $class_name, $constructorArguments = array(), $class_suffix = '') {

        $class = $class_prefix . $class_name . $class_suffix;

        // NAMESPACE-FIRST: if $class is a legacy owa_* name that maps to a
        // migrated PSR-4 class, instantiate the new class directly (Composer
        // autoloads it -- no require, no compat-bridge alias needed). This is
        // what lets OWA run with the bridge disabled; the bridge stays only for
        // third-party callers of the old names. See resolveNamespacedClass().
        $nsClass = self::resolveNamespacedClass($class);
        if ($nsClass !== null) {
            return new $nsClass($constructorArguments);
        }

        // LEGACY FALLBACK: an old-style global-namespace class living in a file
        // named <class_name>.php under $class_dir (the pre-PSR-4 convention).
        // Reached only by classes NOT in the migration map -- i.e. third-party
        // code -- so emit a deprecation notice.
        $class_dir = $class_dir.'/';
        $classfile = $class_dir . $class_name . '.php';

        /*
         * Attempt to include a version of the named class, but don't treat
         * a failure as fatal.  The caller may have already included their own
         * version of the named class.
         */
        if (!class_exists($class)) {
            if (!file_exists($classfile)) {
                throw new \Exception('Class File '.$classfile.' not existend!');
            }
            self::noticeLegacyClass($class);
            require_once ($classfile);
        }

        if (!class_exists($class)) {
                throw new \Exception('Class '.$class.' does not exist!');
        }
        return new $class($constructorArguments);
    }

    public static function simpleFactory( $class_name, $file_path = '', $args = '' ) {

        // NAMESPACE-FIRST (see factory() above): resolve a legacy owa_* class
        // name to its migrated PSR-4 class before touching the filesystem.
        $nsClass = self::resolveNamespacedClass($class_name);
        if ($nsClass !== null) {
            return new $nsClass( $args );
        }

        if ( ! class_exists( $class_name ) ) {

            if ( ! file_exists( $file_path ) ) {

                throw new \Exception("Factory cannot make $class_name because $file_path does not exist!");

            } else {

                   self::noticeLegacyClass($class_name);
                   require_once( $file_path );
            }

        }

        if ( ! class_exists( $class_name ) ) {

            throw new \Exception("Class $class_name still does not exist!");
        }

        return new $class_name( $args );
    }

    /**
     * Resolve a legacy global-namespace `owa_*` class name to its migrated
     * PSR-4 class, NAMESPACE-FIRST.
     *
     * Returns the new fully-qualified class name if $legacy is a migrated OWA
     * class whose new class is loadable (Composer autoload), otherwise null so
     * the caller falls back to its legacy require path. The owa_compat_class_map()
     * (in owa_compat_aliases.php) is the authoritative old->new lookup and is
     * available regardless of whether the aliasing autoloader is enabled -- so
     * this works with OWA_DISABLE_COMPAT_BRIDGE set.
     *
     * @param string $legacy a synthesized/registered class name, e.g. 'owa_error'
     * @return string|null new FQCN (e.g. 'OWA\\Module\\Base\\Classes\\Error') or null
     */
    public static function resolveNamespacedClass(string $legacy): ?string {

        // Already a namespaced name (contains a backslash): nothing to map.
        if (strpos($legacy, '\\') !== false) {
            return null;
        }

        // Only legacy owa_* names are candidates.
        if (strncmp($legacy, 'owa_', 4) !== 0) {
            return null;
        }

        $map = \owa_compat_class_map();
        $new = $map[$legacy] ?? null;

        // Case-insensitive fallback -- legacy code references a couple of names
        // in the "wrong" case (PHP class names are case-insensitive; namespaced
        // names are not). Mirrors the bridge's own ci-fallback.
        if ($new === null) {
            static $ciMap = null;
            if ($ciMap === null) {
                $ciMap = [];
                foreach ($map as $old => $target) {
                    $ciMap[strtolower($old)] = $target;
                }
            }
            $new = $ciMap[strtolower($legacy)] ?? null;
        }

        if ($new !== null && class_exists($new)) {
            return $new;
        }

        return null;
    }

    /**
     * Emit a one-time-per-name deprecation notice when a factory loads a class
     * by its legacy global-namespace `owa_*` name (the pre-PSR-4 convention).
     * OWA's own classes resolve namespace-first and never reach here; only
     * un-migrated third-party classes do.
     */
    protected static function noticeLegacyClass(string $class): void {

        static $seen = array();
        if (isset($seen[$class])) {
            return;
        }
        $seen[$class] = true;

        \OWA\Core\CoreAPI::notice(
            "Class '{$class}' was loaded by its DEPRECATED legacy global-namespace "
            . "name. Migrate it to a PSR-4 namespaced class; the owa_* compatibility "
            . "bridge will be removed in a future major version (v2.0)."
        );
    }

    /**
     * Generic Object Singleton
     *
     * @param string $class_dir
     * @param string $class_prefix
     * @param string $class_name
     * @param array $conf
     * @return object
     */
    public static function singleton($class_dir, $class_prefix, $class_name, $conf = array()) {

        static $instance;
        
        if (!isset($instance)) {
            // below missing a reference becasue the static vriable can not handle a reference
            $instance = \OWA\Core\Lib::factory($class_dir, $class_prefix, $class_name, $conf);
        }
        
        return $instance;
    }
    
    /**
     * 302 HTTP redirect the user to a new url
     *
     * @param string $url
     */
    public static function redirectBrowser($url) {

        // 302 redirect to URL
        header ('Location: '.\OWA\Core\Lib::resolveRedirectUrl( $url ));
        header ('HTTP/1.0 302 Found');
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
     * @param  string $url
     * @return string
     */
    public static function resolveRedirectUrl( $url ) {

        $base = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );
        $url  = trim( (string) $url );

        if ( $url === '' ) {

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

        if ( $scheme !== '' && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {

            return $base;
        }

        // No host means a path relative to this installation.
        if ( empty( $parts['host'] ) ) {

            return $url;
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

    public static function getRequestParams() {

        $params = array();

        if (!empty($_POST)) {
            $params = $_POST;
        } else {
            $params = $_GET;
        }

        if (!empty($_COOKIE)) {

            $params = array_merge($params, $_COOKIE);
        }

        return $params;
    }

    public static function rekeyArray($array, $new_keys) {

        $new_keys = $new_keys;
        $new_array = array();
        foreach ($array as $k => $v) {

            if (array_key_exists($k, $new_keys)) {
                $k = $new_keys[$k];
            }

            $new_array[$k] = $v;
        }

        return $new_array;
    }


    public static function stripParams($params, $ns = '') {

        $striped_params = array();

        if (!empty($ns)) {

            $len = strlen($ns);

            foreach ($params as $n => $v) {

                // if namespace is present in param
                if (strstr($n, $ns)) {
                    // strip the namespace value
                    $striped_n = substr($n, $len);
                    //add to striped array
                    $striped_params[$striped_n] = $v;

                }

            }

            return $striped_params;

        } else {

            return $params;
        }

    }

    /**
     * redirects borwser to a particular view
     *
     * @param mixed $data
     */
    public static function redirectToView($data) {
        //print_r($data);
        $c = \OWA\Core\CoreAPI::configSingleton();
        $config = $c->fetch('base');

        $control_params = array('view_method', 'auth_status');


        $get = '';

        foreach ($data as $n => $v) {

            if (!in_array($n, $control_params)) {

                $get .= $config['ns'].$n.'='.$v.'&';

            }
        }

        $new_url = sprintf($config['link_template'], $config['main_url'], $get);

        \OWA\Core\Lib::redirectBrowser($new_url);
    }

    /**
     * Create guid from string
     *
     * @param     string $string
     * @return     int|string|null
     * @access     private
     */
    /**
     * A 63-bit id derived from the content, for the wide-hash scheme.
     *
     * WHY NOT THE OBVIOUS hexdec( substr( sha1( $s ), 0, 16 ) )
     * ---------------------------------------------------------
     * Sixteen hex characters is a full 64 bits, and PHP_INT_MAX is 2^63-1. So
     * hexdec() returns a FLOAT for roughly half of all inputs, and casting that
     * float to int wraps it negative. Measured over 2,000 values: 49% came back
     * negative. Worse, a float carries 53 bits of mantissa, so the low bits of
     * those ids were already gone before the cast -- the scheme delivered about
     * 53 bits of key space, not 64. And casting an out-of-range float to int is
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

    public static function setStringGuid($string) {

        if ( $string ) {


            if ( \OWA\Core\CoreAPI::getSetting('base', 'use_64bit_hash') && PHP_INT_SIZE >= 8 ) {

                return (string) self::wideStringGuid( $string );

            } else {
                // make 32 bit ID from crc32
                return crc32( strtolower( $string ) );
            }
        }

        return null;
    }

    /**
     * Add constraints into SQL where clause
     *
     * @param     array $constraints
     * @return     string|null $where
     * @access     public
     * @depricated
     * @todo remove
     */
    function addConstraints($constraints) {

        if (!empty($constraints)):

            $count = count($constraints);

            $i = 0;

            $where = '';

            foreach ($constraints as $key => $value) {

                if (empty($value)):
                    $i++;
                else:

                    if (!is_array($value)):
                        $where .= $key . ' = ' . "'$value'";
                    else:

                        switch ($value['operator']) {
                            case 'BETWEEN':
                                $where .= sprintf("%s BETWEEN '%s' AND '%s'", $key, $value['start'], $value['end']);
                                break;
                            default:
                                $where .= sprintf("%s %s '%s'", $key, $value['operator'], $value['value']);
                                break;
                        }


                    endif;

                    if ($i < $count - 1):

                        $where .= " AND ";

                    endif;

                    $i++;

                endif;

            }
            // needed in case all values in the array are empty
            if (!empty($where)):
                return $where;
            else:
                return null;
            endif;

        else:

            return null;

        endif;



    }

    public static function assocFromString($string_state, $inner = '=>', $outer = '|||') {

        if (!empty($string_state)):

            if (strpos($string_state, $outer) === false):

                return $string_state;

            else:

                $array = explode($outer, $string_state);

                $state = array();

                foreach ($array as $key => $value) {

                    list($realkey, $realvalue) = explode($inner, $value);
                    $state[$realkey] = $realvalue;

                }

            endif;

        endif;

        return $state;


    }

    /**
     * Lists all files in a Directory
     *
     */
    public static function listDir($start_dir='.', $recursive = true) {

        $files = array();

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
                    array_push($files, array('name' => $file, 'path' => $filepath));
                endif;
            }

            closedir($fh);

        else:
            // false if the function was called with an invalid non-directory argument
            $files = false;
        endif;

      return $files;

    }

    public static function makeDateArray($result, $format) {

        if (!empty($result)) {

            $timestamps = array();

            foreach ($result as $row) {

                $timestamps[]= mktime(0,0,0,$row['month'],$row['day'],$row['year']);
            }

            return \OWA\Core\Lib::makeDates($timestamps, $format);

        } else {

            return array();
        }

    }

    public static function makeDates($timestamps, $format) {

        sort($timestamps);

            $new_dates = array();

            foreach ($timestamps as $timestamp) {

                $new_dates[] = date($format, $timestamp);

            }

        return $new_dates;

    }

    public static function html2txt($document){
        $search = array('@<script[^>]*?>.*?</script>@si',  // Strip out javascript
                       '@<style[^>]*?>.*?</style>@siU',    // Strip style tags properly
                       '@<[\/\!]*?[^<>]*?>@si',            // Strip out HTML tags
                       '@<![\s\S]*?--[ \t\n\r]*>@'         // Strip multi-line comments including CDATA
        );
        $text = preg_replace($search, '', $document);
        return $text;
    }

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
        //return owa_coreAPI::saltedHash( $password, 'auth');
    }
    public static function encryptPassword($password) {

        // check function exists to support older PHP
        if ( function_exists('password_hash') ) {
            return password_hash( $password, PASSWORD_DEFAULT );
        } else {
            return self::encryptOldPassword($password);
        }
    }

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
     * A leading -- is stripped from the name. It used to survive into the key,
     * so a parameter could be literally called '--force'; anything relying on
     * that reads its value under the undashed name now.
     *
     * @param array $argv raw arguments, including the script name at index 0
     * @return array|string the parameter map, or a message describing the first
     *                      argument that could not be read
     */
    public static function parseCliArgs( $argv ) {

        $params = array();

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

    public static function timestampToYyyymmdd($timestamp = '') {

        if(empty($timestamp)) {
            $timestamp = time();
        }
        //print "before date";
        $yyyymmdd = date("Ymd", $timestamp);
        ///print "after date";
        return $yyyymmdd;
    }

    public static function setContentTypeHeader($type = 'html') {

        if (!$type) {
            $type = 'html';
        }

        $content_types = array('html' => 'text/html',
                               'xml' => 'text/xml',
                               'json' => 'application/json',
                               'jsonp' => 'application/json',
                               'csv' => 'text/csv');

        if (array_key_exists($type, $content_types)) {
            $mime = $content_types[$type];
            header('Content-type: '.$mime);
        }
    }

    public static function array_values_assoc($assoc) {

        $values = array();

        foreach ($assoc as $k => $v) {

            if (!empty($v)) {
                $values[] = $v;
            }
        }

        return $values;
    }

    public static function prepareCurrencyValue($string) {

        return $string * 100;
    }

    public static function utf8Encode($string) {

        if ( \OWA\Core\Lib::checkForUtf8( $string ) ) {
            return $string;
        } else {
            if (function_exists('iconv')) {
	            
	            if ( function_exists( 'mb_detect_encoding' ) ) {
		            
		            return iconv(mb_detect_encoding( $string ),'UTF-8//TRANSLIT', $string);
		            
		        } else {
			        
			    	return iconv('UTF-8','UTF-8//TRANSLIT', $string);
		        }
	             
            } else {
                // at least worth a try (utf8_encode() removed/deprecated; mbstring equivalent)
                return mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
            }
        }
    }

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
    }

    public static function formatCurrency($value, $local, $currency) {

        $value = $value / 100;

        if ( function_exists('numfmt_create') ) {

            $numberFormatter = new \NumberFormatter($local, \NumberFormatter::CURRENCY);
            return $numberFormatter->formatCurrency($value, $currency);

        } else {

            // Fallback for hosts without intl. money_format() was removed in
            // PHP 8.0, so format the amount directly instead.
            return $currency . ' ' . number_format( $value, 2 );
        }
    }

    public static function crc32AsHex($string) {
        $crc = crc32($string);
        //$crc += 0x100000000;
        if ($crc < 0) {
            $crc = 0xFFFFFFFF + $crc + 1;
        }
        return dechex($crc);
    }

    public static function getLocalTimestamp($utc = '') {

        if ( ! $utc ) {
            $utc = time();
        }
        $local_timezone_offset = date('Z');
        $daylight_savings = date('I') * 3600;
        $local_time = $utc - $local_timezone_offset + $daylight_savings;
        return $local_time;
    }

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

    public static function stripWWWFromDomain($domain) {

        $done = false;
        $part = substr( $domain, 0, 5 );
        if ($part === '.www.') {
            //strip .www.
            $domain = substr( $domain, 5);
            // add back the leading period
            $domain = '.'.$domain;
            $done = true;
        }

        if ( ! $done ) {
            $part = substr( $domain, 0, 4 );
            if ($part === 'www.') {
                //strip .www.
                $domain = substr( $domain, 4);
                $done = true;
            }

        }

        return $domain;
    }

    /**
     *  Use this function to parse out the url and query array element from
     *  a url.
     */
    public static function parse_url( $url ) {

        $url = parse_url($url);

        if ( isset( $url['query'] ) ) {
            $var = $url['query'];

            $var  = html_entity_decode($var);
            $var  = explode('&', $var);
            $arr  = array();

              foreach( $var as $val ) {

                if ( strpos($val, '=') ) {
                    $x = explode('=', $val);

                    if ( isset( $x[1] ) ) {
                        $arr[$x[0]] = urldecode($x[1]);
                    }
                } else {
                    $arr[$val] = '';
                }
               }
              unset($val, $x, $var);

              $url['query_params'] = $arr;

        }

          return $url;
    }

    public static function iniGet( $name ) {

        $b = ini_get( $name );

        switch ( strtolower( $b ) ) {
            case 'on':
            case 'yes':
            case 'true':
                return true;

            default:
                return (bool) (int) $b;
        }

    }

    // better empty check when you need to accept these as valid, non-empty values:
    // - 0 (0 as an integer)
    //- 0.0 (0 as a float)
    //- "0" (0 as a string)
    public static function isEmpty($value) {

        if ( empty( $value ) && ! is_numeric( $value ) ) {
	        
	        return true;
        }
    }

    public static function isIpAddressValid( $ip = '' ) {

        if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
              // it's valid
              return true;
        } else {
              // it's not valid
              return false;
        }
    }

    public static function zeroFill( $number, $char_length ) {

        return str_pad( (int) $number, $char_length, "0", STR_PAD_LEFT );
    }

    public static function generateRandomUid($seed='') {

        $time = (string) time();
        $random = \OWA\Core\Lib::zeroFill( mt_rand( 0, 999999 ), 6 );
        if ( defined('OWA_SERVER_ID') ) {
            $server = \OWA\Core\Lib::zeroFill( OWA_SERVER_ID, 3 );
        } else {
            $server = substr( getmypid(), 0, 3);
        }

        return $time.$random.$server;
    }

    public static function unparseUrl($parsed_url, $ommit = array() ) {

        $url = '';
        $p = array();

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
    
    public static function removeQueryParamFromUrl( $url, $key ) {
	    
	    $url = preg_replace('/([?&])'.$key.'=[^&]+(&|$)/','$1',$url);
	    return rtrim( $url, '&');
    }

    public static function moveFile( $oldfile, $newfile ) {

        if ( file_exists( $oldfile ) ) {

            if ( ! rename( $oldfile, $newfile ) ) {

                if ( copy( $oldfile, $newfile ) ) {

                    unlink( $oldfile );

                    return true;
                }

            } else {

                return true;
            }
        }
    }
    
    public static function anonymizeIp( $ip_address ) {
	    
	    $ipv4NetMask = "255.255.255.0";
	    $ipv6NetMask = "ffff:ffff:ffff:ffff:0000:0000:0000:0000";
	    
	    $packed_address = inet_pton( $ip_address);

        if ( strlen( $packed_address ) == 4 ) {
	        
            return inet_ntop( inet_pton( $ip_address ) & inet_pton( $ipv4NetMask ) );
            
        } elseif ( strlen( $packed_address ) == 16 ) {
	        
            return inet_ntop( inet_pton( $ip_address ) & inet_pton( $ipv6NetMask ) );
        }
    }
    
    public static function isIpv6SupportEnabled() {
	    
		if ( defined( 'AF_INET6' ) ) {
			
			return true;
		}
    }

    public static function isValidIp( $ip_address ) {
		
		return filter_var( $ip_address, FILTER_VALIDATE_IP, [] );
    }

    // check to see if the IP address falls within known private IP ranges
    public static function isNotPrivateIp( $ip_address ) {

		return filter_var( $ip_address, FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ] );
    }
    
    public static function isValidIpv6( $ip_address ) {
	    
	    return filter_var( $ip_address, FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_IPV6 ] );
    }
    
    public static function keyExistsNotEmpty( $key, $array ) {
	    
	    if ( array_key_exists($key, $array) && ! empty( $array[ $key ] ) )  {
		    
		    return true;
	    }
    }
    
    public static function setDefaultParams( $defaults, $params ) {
	    
	    if ( is_array( $defaults ) && is_array( $params ) ) {
	    
	    	return array_merge( $defaults, array_filter( $params) );
	    }
    }
    
    public static function inDebug() {
	    
	    if ( ( defined( 'OWA_DEBUG') &&  OWA_DEBUG === true ) ||
	    	 ( defined( 'OWA_ERROR_HANDLER') && OWA_ERROR_HANDLER === 'development' )
		){
			return true;
		}	    	
    }
    
     public static function inRestDebug() {
	    
	    if ( ( defined( 'OWA_REST_DEBUG') &&  OWA_REST_DEBUG === true ) ){
			
			return true;
		}	    	
    }

}

?>