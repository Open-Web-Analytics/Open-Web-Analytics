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

//require_once 'owa_env.php';

//require_once(OWA_BASE_CLASS_DIR.'settings.php');

/**
 * Utility Functions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_lib {

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

    // php 4 compatible function
    public static function array_intersect_key() {

        $arrs = func_get_args();
        $result = array_shift($arrs);
        foreach ($arrs as $array) {
            foreach ($result as $key => $v) {
                if (!array_key_exists($key, $array)) {
                    unset($result[$key]);
                }
            }
        }
        return $result;
     }

    // php4 compatible function
    public static function array_walk_recursive(&$input, $funcname, $userdata = "")
    {
        if (!is_callable($funcname))
        {
            return false;
        }
        
        if (!is_array($input))
        {
            return false;
        }
        
        if (is_array($funcname))
        {
            $funcname = $funcname[0].'::'.$funcname[1];
        }
        
        
        foreach ($input AS $key => $value)
        {
            if (is_array($input[$key]))
            {
                array_walk_recursive($input[$key], $funcname, $userdata);
            }
            else
            {
                $saved_value = $value;
                if (!empty($userdata))
                {
                    $funcname($value, $key, $userdata);
                }
                else
                {
                    $funcname($value, $key);
                }
                
                if ($value != $saved_value)
                {
                    $input[$key] = $value;
                }
            }
        }
        return true;
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
     * Error Handler
     *
     * @param string $msg
     * @access public
     * @depricated
     */
    function errorHandler($msg) {
        require_once(OWA_PEARLOG_DIR . '/Log.php');
        $conf = array('mode' => 0755, 'timeFormat' => '%X %x');
        $error_logger = &Log::singleton('file', $this->config['error_log_file'], 'ident', $conf);
        $this->error_logger->_lineFormat = '[%3$s]';

        return;
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

            $months = owa_lib::months();

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
     * @return string
     */
    public static function getDatelabel($params) {

        switch ($params['period']) {

            case "day":
                return sprintf("%s, %d%s %s",
                            owa_lib::get_month_label($params['month']),
                            $params['day'],
                            owa_lib::setDaySuffix($params['day']),
                            $params['year']
                        );
                break;

            case "month":
                return sprintf("%s %s",
                            owa_lib::get_month_label($params['month']),
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
                            owa_lib::get_month_label($params['month']),
                            $params['day'],
                            owa_lib::setDaySuffix($params['day']),
                            $params['year'],
                            owa_lib::get_month_label($params['month2']),
                            $params['day2'],
                            owa_lib::setDaySuffix($params['day2']),
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
     * @param unknown_type $period
     * @return unknown
     */
    public static function get_period_label($period) {

        $periods = owa_lib::reporting_periods();

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

        return owa_sanitize::cleanInput( $input, $options );
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

        $class_dir = $class_dir.'/';
        $classfile = $class_dir . $class_name . '.php';
        $class = $class_prefix . $class_name . $class_suffix;

        /*
         * Attempt to include a version of the named class, but don't treat
         * a failure as fatal.  The caller may have already included their own
         * version of the named class.
         */
        if (!class_exists($class)) {
            if (!file_exists($classfile)) {
                throw new Exception('Class File '.$classfile.' not existend!');
            }
               require_once ($classfile);
        }

        if (!class_exists($class)) {
                throw new Exception('Class '.$class.' does not exist!');
        }
        return new $class($constructorArguments);
    }
    
    public static function simpleFactory( $class_name, $file_path = '', $args = '' ) {

        if ( ! class_exists( $class_name ) ) {

            if ( ! file_exists( $file_path ) ) {
                
                throw new Exception("Factory cannot make $class_name because $file_path does not exist!");
            
            } else {
            
                   require_once( $file_path );
            }
  
        }
       
        if ( ! class_exists( $class_name ) ) {

            throw new Exception("Class $class_name still does not exist!");
        }
       
        return new $class_name( $args );
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
            $instance = owa_lib::factory($class_dir, $class_prefix, $class_name, $conf);
        }
        
        return $instance;
    }
    
    /**
     * 302 HTTP redirect the user to a new url
     *
     * @param string $url
     */
    public static function redirectBrowser($url) {
        //print ($url); exit;
        // 302 redirect to URL
        header ('Location: '.$url);
        header ('HTTP/1.0 302 Found');
    }

    public static function makeLinkQueryString($query_params) {

        $new_query_params = array();

        //Load params passed by caller
        if (!empty($this->caller_params)):
            foreach ($this->caller_params as $name => $value) {
                if (!empty($value)):
                    $new_query_params[$name] = $value;
                endif;
            }
        endif;

        // Load overrides
        if (!empty($query_params)):
            foreach ($query_params as $name => $value) {
                if (!empty($value)):
                    $new_query_params[$name] = $value;
                endif;
            }
        endif;

        // Construct GET request
        if (!empty($new_query_params)):
            foreach ($new_query_params as $name => $value) {
                if (!empty($value)):
                    $get .= $name . "=" . $value . "&";
                endif;
            }
        endif;

        return $get;

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
     * module specific require method
     *
     * @param unknown_type $module
     * @param unknown_type $file
     * @return unknown
     * @deprecated
     */
    public static function moduleRequireOnce($module, $file) {

        return require_once(OWA_BASE_DIR.'/modules/'.$module.'/'.$file.'.php');
    }

    /**
     * module specific factory
     *
     * @param unknown_type $modulefile
     * @param unknown_type $class_suffix
     * @param unknown_type $params
     * @return unknown
     * @deprecated
     */
    public static function moduleFactory($modulefile, $class_suffix = null, $params = '') {

        list($module, $file) = explode(".", $modulefile);
        $class = 'owa_'.$file.$class_suffix;

        // Require class file if class does not already exist
        if(!class_exists($class)):
            owa_lib::moduleRequireOnce($module, $file);
        endif;

        $obj = owa_lib::factory(OWA_BASE_DIR.'/modules/'.$module, '', $class, $params);
        $obj->module = $module;

        return $obj;
    }
    
    /**
     * redirects borwser to a particular view
     *
     * @param unknown_type $data
     */
    public static function redirectToView($data) {
        //print_r($data);
        $c = owa_coreAPI::configSingleton();
        $config = $c->fetch('base');

        $control_params = array('view_method', 'auth_status');


        $get = '';

        foreach ($data as $n => $v) {

            if (!in_array($n, $control_params)) {

                $get .= $config['ns'].$n.'='.$v.'&';

            }
        }

        $new_url = sprintf($config['link_template'], $config['main_url'], $get);

        owa_lib::redirectBrowser($new_url);
    }

    /**
     * Displays a View without user authentication. Takes array of data as input
     *
     * @param array $data
     * @deprecated
     */
    public static function displayView($data, $params = array()) {

        $view =  owa_lib::moduleFactory($data['view'], 'View', $params);

        return $view->assembleView($data);

    }

    /**
     * Create guid from string
     *
     * @param     string $string
     * @return     integer
     * @access     private
     */
    public static function setStringGuid($string) {

        if ( $string ) {


            if ( owa_coreAPI::getSetting('base', 'use_64bit_hash') && PHP_INT_MAX == '9223372036854775807') {
                // make 64 bit ID from partial sha1
                return (string) (int) hexdec( substr( sha1( strtolower( $string ) ), 0, 16 ) );
            } else {
                // make 32 bit ID from crc32
                return crc32( strtolower( $string ) );
            }
        }
    }

    /**
     * Add constraints into SQL where clause
     *
     * @param     array $constraints
     * @return     string $where
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
                return;
            endif;

        else:

            return;

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
      * Simple function to replicate PHP 5 behaviour
      */

    public static function microtime_float() {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
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
                        $files = array_merge($files, owa_lib::listDir($filepath));
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

            return owa_lib::makeDates($timestamps, $format);

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

    public static function hash( $hash_type = 'md5', $data, $salt = '' ) {

        return hash_hmac( $hash_type, $data, $salt );
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

        if ( owa_lib::checkForUtf8( $string ) ) {
            return $string;
        } else {
            if (function_exists('iconv')) {
                return iconv('UTF-8','UTF-8//TRANSLIT', $string);
            } else {
                // at least worth a try
                return utf8_encode($string);
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

            $numberFormatter = new NumberFormatter($local, NumberFormatter::CURRENCY);
            return $numberFormatter->formatCurrency($value, $currency);

        } else {

            setlocale( LC_MONETARY, $local );
            return money_format( '%.' . 2 . 'n',$value );
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
        $random = owa_lib::zeroFill( mt_rand( 0, 999999 ), 6 );
        if ( defined('OWA_SERVER_ID') ) {
            $server = owa_lib::zeroFill( OWA_SERVER_ID, 3 );
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

    public static function isValidIp( $ip_address ) {

        // if valid ip address
        if ( ! empty( $ip_address )
            && ip2long( $ip_address ) != -1
            && ip2long( $ip_address ) != false
        ) {

            return true;
        }

    }

    // check to see if the IP address falls within known private IP ranges
    public static function isPrivateIp( $ip_address ) {

        $ip = ip2long( $ip_address);

        $private_ip_ranges = array (
            array('0.0.0.0','2.255.255.255'),
            array('10.0.0.0','10.255.255.255'),
            array('127.0.0.0','127.255.255.255'),
            array('169.254.0.0','169.254.255.255'),
            array('172.16.0.0','172.31.255.255'),
            array('192.0.2.0','192.0.2.255'),
            array('192.168.0.0','192.168.255.255'),
            array('255.255.255.0','255.255.255.255')
        );

        //check to see if it falls within a known private range
        foreach ( $private_ip_ranges as $range ) {

            $min = ip2long( $range[0] );
            $max = ip2long( $range[1] );

            if ( ( $ip >= $min ) && ( $ip <= $max ) ) {

                return true;
            }
        }

        // if it makes it through the checks then it's not private.
        return false;
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