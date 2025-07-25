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
 * Settings Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
 
 class owa_settings {

     /**
      * Main Settings Class
      *
      * @var owa_settings
      */
     var $config;

     var $default_config;

     var $db_settings = array();

     var $fetched_from_db;

     var $is_dirty;

     var $config_id;

     var $config_from_db;
     
     var $config_file_loaded;

     /**
      * Constructor
      *
      * Loads the config file and initializes the default settings.
      * This must be called as early as possible in the overall call stack 
      * and certainly before any database access can occur.
      */
      function __construct() {
     
        // load default settings
        $this->default_config = $this->getDefaultSettingsArray();
        
        // include/load config file
        // This needs to happen as early as possible in order to make constants available
        // to coreAPI methods and entities. 
        $this->loadConfigFile();
        
        // create configuration object
        $this->config = owa_coreAPI::entityFactory('base.configuration');
        // load entity with the default settings
        $this->config->set('settings', $this->default_config);
        
        // set mailer domain (must be after config file is loaded)
        $this->setMailerDomain();
        
        // apply config constants as settings
        $this->applyConfigConstants();
        
        // setup directory paths
        $this->setupPaths();        
    }
     
     public function setTimezone() {

         // set default timezone while surpressing any warning
        if ( function_exists( 'date_default_timezone_set' ) ) {
            $level = error_reporting( 0 );
            date_default_timezone_set( $this->get( 'base', 'timezone' ) );
            error_reporting( $level );
        }
     }

     /**
      * @return boolean
      */
     public function isConfigFilePresent() {

        $file = $this->get('base', 'config_file');
        
        if ( file_exists( $file ) ) {
            
            return true;
        }
     }
     
     public function isConfigFileLoaded() {
         
         return $this->config_file_loaded;
     }

     private function loadConfigFile() {
 
        //$file = $this->get('base', 'config_file');
        $file = OWA_DIR.'owa-config.php';
        
        if ( $this->isConfigFilePresent() ) {
            
            include_once($file);
            $this->config_file_loaded = true;
        }
     }

     function applyConfigConstants() {

         if(!defined('OWA_DATA_DIR')){
            define('OWA_DATA_DIR', OWA_DIR.'owa-data/');

        }

        if (defined('OWA_DATA_DIR')) {
            $this->set('base', 'data_dir', OWA_DATA_DIR);
        }

        if(!defined('OWA_CACHE_DIR')){
            define('OWA_CACHE_DIR', OWA_DATA_DIR.'caches/');
         }

         if (defined('OWA_CACHE_DIR')) {
            $this->set('base', 'cache_dir', OWA_CACHE_DIR);
        }

         // Looks for log level constant
        if (defined('OWA_ERROR_LOG_LEVEL')) {
            $this->set('base', 'error_log_level', OWA_ERROR_LOG_LEVEL);
        }

        /* CONFIGURATION ID */

        if (defined('OWA_CONFIGURATION_ID')) {
            $this->set('base', 'configuration_id', OWA_CONFIGURATION_ID);
        }

        /* OBJECT CACHING */

        // Looks for object cache config constant
        // must comebefore user db values are fetched from db
        if (defined('OWA_CACHE_OBJECTS')) {
            $this->set('base', 'cache_objects', OWA_CACHE_OBJECTS);
        }

        /* DATABASE CONFIGURATION */

        // This needs to come before the fetch of user overrides from the DB
        // Constants defined in the config file have the final word
        // values passed from calling application must be applied prior
        // to the rest of the caller's overrides

        if (defined('OWA_DB_TYPE')) {
            $this->set('base', 'db_type', OWA_DB_TYPE);
        }

        if (defined('OWA_DB_NAME')) {
            $this->set('base', 'db_name', OWA_DB_NAME);
        }

        if (defined('OWA_DB_HOST')) {
            $this->set('base', 'db_host', OWA_DB_HOST);
        }

        if (defined('OWA_DB_PORT')) {
            $this->set('base', 'db_port', OWA_DB_PORT);
        }

        if (defined('OWA_DB_USER')) {
            $this->set('base', 'db_user', OWA_DB_USER);
        }

        if (defined('OWA_DB_PASSWORD')) {
            $this->set('base', 'db_password', OWA_DB_PASSWORD);
        }

        /* SET ERROR HANDLER */
        if (defined('OWA_ERROR_HANDLER')) {
            $this->set('base', 'error_handler', OWA_ERROR_HANDLER);
        }

        if (defined('OWA_PUBLIC_URL')) {
            $this->set('base', 'public_url', OWA_PUBLIC_URL);
        }

        if (defined('OWA_PUBLIC_PATH')) {
            $this->set('base', 'public_path', OWA_PUBLIC_PATH);
        }

        if (defined('OWA_QUEUE_EVENTS')) {
            $this->set('base', 'queue_events', OWA_QUEUE_EVENTS);
        }

        if (defined('OWA_EVENT_QUEUE_TYPE')) {
            $this->set('base', 'event_queue_type', OWA_EVENT_QUEUE_TYPE);
        }

        if (defined('OWA_EVENT_SECONDARY_QUEUE_TYPE')) {
            $this->set('base', 'event_secondary_queue_type', OWA_EVENT_SECONDARY_QUEUE_TYPE);
        }

        if (defined('OWA_USE_REMOTE_EVENT_QUEUE')) {
            $this->set('base', 'use_remote_event_queue', OWA_USE_REMOTE_EVENT_QUEUE);
        }

        if (defined('OWA_REMOTE_EVENT_QUEUE_TYPE')) {
            $this->set('base', 'remote_event_queue_type', OWA_REMOTE_EVENT_QUEUE_TYPE);
        }

        if (defined('OWA_REMOTE_EVENT_QUEUE_ENDPOINT')) {
            $this->set('base', 'remote_event_queue_endpoint', OWA_REMOTE_EVENT_QUEUE_ENDPOINT);
        }

     }
      
     /**
      * Ovverrides settings - used in some controllers (@see owa_caller )
      * @param string $module
      * @param array $config
      */
     public function applyModuleOverrides($module, $config) {

         // merge default config with overrides

         if (!empty($config)) {

             $in_place_config = $this->config->get('settings');

             $old_array = $in_place_config[$module];

             $new_array = array_merge($old_array, $config);

            $in_place_config[$module] = $new_array;

             $this->config->set('settings', $in_place_config);

             //print_r($this->config->get('settings'));

         }
     }

     /**
      * Loads configuration from data store
      *
      * @param string id  the id of the configuration array to load
      */
     function load($id = 1) {

        $this->config_id = $id;

        $db_config = owa_coreAPI::entityFactory('base.configuration');
        $db_config->getByPk('id', $id);
        $db_settings = unserialize($db_config->get('settings'));

        //print $db_settings;
        // store copy of config for use with updates and set a flag
        if (!empty($db_settings)) {

            // needed to get rid of legacy setting that used to be stored in the DB.
            if (array_key_exists('error_handler', $db_settings['base'])) {
                
                unset($db_settings['base']['error_handler']);
            }

            $this->db_settings = $db_settings;
            $this->config_from_db = true;
        }

        if (!empty($db_settings)) {
            //print_r($db_settings);
            //$db_settings = unserialize($db_settings);

            $default = $this->config->get('settings');

            // merge default config with overrides fetched from data store

            $new_config = array();

            foreach ($db_settings as $k => $v) {

                if (isset($default[$k]) && is_array($default[$k])) {
                 
                    $new_config[$k] = array_merge($default[$k], $db_settings[$k]);
                
                } else {
                 
                    $new_config[$k] = $db_settings[$k];
                }
            }

            $this->config->set('settings', $new_config);
        }

        $db_id = $db_config->get('id');
        $this->config->set('id', $db_id);
     }

     /**
      * Fetches a modules entire configuration array
      *
      * @param string $module The name of module whose configuration values you want to fetch
      * @return array Config values
      */
     function fetch($module = '') {
        
        $v = $this->config->get('settings');

        if (!empty($module)) {

            return $v[$module];
        
        } else {
         
            return $v['base'];
        }
     }

     /**
      * updates or creates configuration values
      *
      * @return boolean
      */
     function save() {

         // serialize array of values prior to update

        $config = owa_coreAPI::entityFactory('base.configuration');

        // if fetch from db flag is not true, try to fetch the config just in
        // case if was cached or something wen wrong.
        // Then merge the new values into it.
        if ($this->config_from_db != true) {

            $config->getByPk('id', $this->get('base', 'configuration_id'));

            $settings = $config->get('settings');

            if (!empty($settings)) {

                $settings = unserialize($settings);

                $new_config = array();

                foreach ($this->db_settings as $k => $v) {

                    if (!is_array($settings[$k])) {
                        $settings[$k] = array();
                    }

                    $new_config[$k] = array_merge($settings[$k], $this->db_settings[$k]);
                }

                $config->set('settings', serialize($new_config));

                //$config->set('settings', serialize(array_merge($settings, $this->db_settings)));
            } else {
                $config->set('settings', serialize($this->db_settings));
            }

            // test to see if object exists
            $id = $config->get('id');

            if (!empty($id)) {
                // if it does just update
                $status = $config->update();

            // else create the object
            } else {
             
                $config->set('id', $this->get('base', 'configuration_id'));
                $status = $config->create();
            }
            
        } else {
            // update the config
            $config->set('settings', serialize($this->db_settings));
            $config->set('id', $this->get('base', 'configuration_id'));
            $status = $config->update();
        }

        $this->is_dirty = false;

        return $status;
     }

     /**
      * Accessor Method
      *
      * @param string $module the name of the module
      * @param string $key the configuration key
      * @return mixed
      */
     function get(string $module, string $key) {
        
        if ( $this->config ) {
            
            $values = $this->config->get('settings');          
        
        } else {
            // setting on the default values array can only happen if a get/set 
            // is called from within the config file. 
            $values = $this->default_config;    
        }

         if ( isset( $values[$module] ) && array_key_exists($key, $values[$module])) {
             return $values[$module][$key];
         } else {
             return false;
         }

     }

     /**
      * Sets configuration value. will not be persisted.
      *
      * @param string $module the name of the module
      * @param string $key the configuration key
      * @param string $value the configuration value
      * @return boolean
      */
     function set($module, $key, $value) {
        
        if ( $this->config ) {
            
            $values = $this->config->get('settings');
        
        } else {
            // setting on the default values array can only happen if a get/set 
            // is called from within the config file. 
            $values = $this->default_config; 
        }
         $values[$module][$key] = $value;
        
        if ( $this->config ) {
            
            $this->config->set('settings', $values);
        
        } else {
        
            $this->default_config = $values;
        }
     }


     /**
      * Adds Setting value to be configuration and persistant data store
      * same as $this->set
      *
      * @param string $module the name of the module
      * @param string $key the configuration key
      * @param string $value the configuration value
      * @depricated
      */
     function setSetting($module, $key, $value) {
         return $this->set($module, $key, $value);
     }

     /**
      * Adds Setting value to be configuration and persistant data store
      *
      * @param string $module the name of the module
      * @param string $key the configuration key
      * @param string $value the configuration value
      * @return
      */
     public function persistSetting($module, $key, $value) {

         $this->set($module, $key, $value);
         $this->db_settings[$module][$key] = $value;
         $this->is_dirty = true;
     }

     /**
      * Replaces all values of a particular module's configuration
      * @todo: search to see where else this is used. If unused then make it for use in persist only.
      */
     private function replace($module, $values, $persist = false) {

         if ($persist) {
             $this->db_settings[$module] = $values;
             return;
         }

         $settings = $this->config->get('settings');

         $settings[$module] = $values;

         $this->config->set('settings', $settings);
     }

     /**
      * Alternate Constructor for base module settings
      * Needed for backwards compatibility with older classes
      *
      */
     function &get_settings($id = 1) {

         static $config2;

         if (!isset($config2)) {
             //print 'hello from alt constructor';
             $config2 = owa_coreAPI::configSingleton();
        }

         return $config2->fetch('base');

     }
     
     function setMailerDomain() {
	     
	     if ( isset( $_SERVER[ 'SERVER_NAME' ] ) ) {
		 	 
		 	 $mailer_domain = $_SERVER['SERVER_NAME'];
	 	 
	 	 } else {
		 	 
		 	 if ( defined( 'PUBLIC_URL' ) ) {
			 	 
			 	 $parts = parse_url( PUBLIC_URL );
			 	 $mailer_domain = $parts['host'];
		 	 }
	 	 }
	 	 
	 	 $this->set( 'base', 'mailer-from', 'owa@' . $mailer_domain );
     }


     /**
      * @return array
      */
     private function getDefaultSettingsArray() {
	 	 
         return array(
             'base' => array(
                'ns'                                => 'owa_',
                'visitor_param'                        => 'v',
                'session_param'                        => 's',
                'site_session_param'                => 'ss', //sdk
                'last_request_param'                => 'last_req',
                'feed_subscription_param'            => 'sid',
                'source_param'                        => 'source',
                'graph_param'                        => 'graph',
                'period_param'                        => 'period',
                'document_param'                    => 'document',
                'referer_param'                        => 'referer',
                'site_id'                            => '',
                'configuration_id'                    => '1',
                'session_length'                    => 1800, //sdk
                'requests_table'                    => 'request',
                'sessions_table'                    => 'session',
                'referers_table'                    => 'referer',
                'ua_table'                            => 'ua',
                'os_table'                            => 'os',
                'documents_table'                    => 'document',
                'sites_table'                        => 'site',
                'hosts_table'                        => 'host',
                'config_table'                        => 'configuration',
                'version_table'                        => 'version',
                'feed_requests_table'                => 'feed_request',
                'visitors_table'                    => 'visitor',
                'impressions_table'                    => 'impression',
                'clicks_table'                        => 'click',
                'exits_table'                        => 'exit',
                'users_table'                        => 'user',
                'db_type'                            => '',
                'db_name'                            => '',
                'db_host'                            => '',
                'db_port'                            => 3306,
                'db_user'                            => '',
                'db_password'                        => '',
                'db_force_new_connections'            => true,
                'db_make_persistant_connections'    => false,
                'resolve_hosts'                        => true,
                'log_feedreaders'                    => true,
                'log_robots'                        => false,
                'log_sessions'                        => true,
                'log_dom_clicks'                    => true,
                'async_db'                            => false,
                'clean_query_string'                => true,
                'fetch_refering_page_info'            => true,
                'query_string_filters'                => '', // move to site settings
                'async_log_dir'                        => '', //OWA_DATA_DIR . 'logs/',
                'async_log_file'                    => 'events.txt',
                'async_lock_file'                    => 'owa.lock',
                'async_error_log_file'                => 'events_error.txt',
                'notice_email'                        => '',
                'log_php_errors'                    => false,
                'error_handler'                        => 'production',
                'error_log_level'                    => 0,
                'error_log_file'                    => '', //OWA_DATA_DIR . 'logs/errors.txt',
                'ua-regexes'                        => '',
                'search_engines.ini'                => OWA_BASE_DIR . '/conf/search_engines.ini',
                'query_strings.ini'                    => OWA_BASE_DIR . '/conf/query_strings.ini',
                'db_class_dir'                        => OWA_BASE_DIR . '/plugins/db/',
                'templates_dir'                        => OWA_BASE_DIR . '/templates/',
                'plugin_dir'                        => OWA_BASE_DIR . '/plugins/',
                'module_dir'                        => OWA_BASE_DIR . '/modules',
                'public_path'                        => '',
                'geolocation_lookup'                => false,
                'geolocation_service'                => '',
                'report_wrapper'                    => 'wrapper_default.tpl',
                'announce_visitors'                    => false,
                'public_url'                        => '',
                'base_url'                            => '',
                'action_url'                        => '',
                'images_url'                        => '',
                'reporting_url'                        => '',
                'p3p_policy'                        => 'NOI ADM DEV PSAi COM NAV OUR OTRo STP IND DEM',
                'graph_link_template'                => '%s?owa_action=graph&name=%s&%s', //action_url?...
                'link_template'                        => '%s?%s', // main_url?key=value....
                'owa_user_agent'                    => 'Open Web Analytics Bot '.OWA_VERSION,
                'fetch_owa_news'                    => true,
                'owa_news_url'                        => 'https://api.github.com/repositories/3891123/releases?page=1&per_page=5',
                'use_summary_tables'                => false,
                'summary_framework'                    => '',
                'click_drawing_mode'                => 'center_on_page', // remove
                'log_clicks'                        => true,
                'timezone'                            => 'America/Los_Angeles',
                'log_dom_stream_percentage'            => 50,
                'wiki_url'                            => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki',
                'password_length'                    => 4,
                'modules'                            => array('base'),
                'mailer-from'                        => '',  // Set default address, because sending from root@localhost wont work
                'mailer-fromName'                    => 'OWA Server',
                'mailer-host'                        => '',
                'mailer-port'                        => '',
                'mailer-use-smtp'                    => false,
                'mailer-smtpAuth'                    => false,
                'mailer-username'                    => '',
                'mailer-password'                    => '',
                'queue_events'                        => false,
                'event_queue_type'                    => 'file',
                'event_secondary_queue_type'        => '',
                'use_remote_event_queue'            => true,
                'remote_event_queue_type'            => 'http',
                'remote_event_queue_endpoint'        => '',
                'allowed_queued_event_types'        => [],
                'cookie_domain'                        => false,
                'cookie_persistence'                => true,  // Controls persistence of cookies, only for use in europe needed
                'ws_timeout'                        => 10,
                'is_active'                            => true,
                'per_site_visitors'                    => false, // remove
                'cache_objects'                        => false,
                'log_named_users'                    => true,
                'log_visitor_pii'                    => true,
                'excluded_ips'                        => '',
                'anonymize_ips'                        => false,
                'track_feed_links'                    => true,
                'theme'                                => '',
                'reserved_words'                    => array('do' => 'action'),
                'login_view'                        => 'base.login',
                'not_capable_view'                    => 'base.error',
                'start_page'                        => 'base.sites',
                'default_action'                    => 'base.loginForm',
                'default_page'                        => '', // move to site settings
                'default_cache_expiration_period'    => 604800,
                'nonce_expiration_period'            => 7200,
                'max_prior_campaigns'                => 5, //sdk
                'default_reporting_period'            => 'last_seven_days',
                'campaign_params'                    => array(
                        'campaign'        => 'owa_campaign',
                        'medium'        => 'owa_medium',
                        'source'        => 'owa_source',
                        'search_terms'    => 'owa_search_terms',
                        'ad'            => 'owa_ad',
                        'ad_type'        => 'owa_ad_type'),
                'trafficAttributionMode'            => 'direct', //sdk
                'campaignAttributionWindow'            => 60, //sdk
                 //list of capabilities that require access to the site
                 'capabilitiesThatRequireSiteAccess' => array(
                     'view_reports',
                     'view_reports_ecommerce',
                     'edit_sites',
                 ),
                 // role to capabilities configuration
                'capabilities'                        => array(
                        'admin' => array(
                                'install_schema',
                                'view_site_list',
                                'view_reports',
                                 'view_reports_ecommerce',
                                'edit_settings',
                                'edit_sites',
                                'edit_users',
                                'edit_modules'
                        ),
                        'analyst' => array('install_schema', 'view_site_list', 'view_reports', 'view_reports_ecommerce'),
                        'viewer' => array('install_schema', 'view_site_list', 'view_reports'),
                        'everyone' => array('install_schema')
                ),
                'numGoals'                            => 15,
                'numGoalGroups'                        => 5,
                'enableEcommerceReporting'            => false, // move to site settings
                'currencyLocal'                        => 'en_US', // move to site settings
                'currencyISO3'                        => 'USD',   // move to site settings
                'memcachedServers'                    => array(),
                'memcachedPersisantConnections'        => true,
                'cacheType'                            => '', // file, memory, memcache
                'disabledEndpoints'                    => array('queue.php'),
                'disableAllEndpoints'                => false,
                'processQueuesJobSchedule'            => '10 * * * *',
                'maxCustomVars'                        => 5, //sdk
                'update_session_user_name'            => true, // updates the session with latest user_name value
                'log_owa_user_names'                => true,  // logs the OWA user name as the user_name property on events
                'logo_image_path'                    => 'base/i/owa-logo-100w.png',
                'use_64bit_hash'                    => false,
                'user_id_illegal_chars'                => array( " ", ";", "'", "\"", "|", ")", "("),
                'archive_old_events'                => true, // used by event queues to archive processed events.
                'request_mode'						=> 'web_app',
                'useStaticConfigOnly'				=> false,
                'allow_slowly_changing_dimensions'	=> true,
                'slowly_changing_dimension_entities' => [],
                'db_supported_types'				=> ['mysql' => 'MySQL'],
                'instance_mode'                     => '',
                'tracking_event_types'              => [
                    'dom.click', 
                    'ecommerce.transaction', 
                    'base.page_request', 
                    'dom.stream', 
                    'base.feed_request', 
                    'track.action' 
                ],
                'config_file'                       => OWA_DIR . 'owa-config.php'
            )
        );

     }

     /**
      * sets the basic path settings in the config object like "public_path" / "images_url" ...
      * @return void
      */
     private function setupPaths() {

         //build base url
         $base_url = '';
         $proto  = "http";

        if(isset($_SERVER['HTTPS'])) {
            $proto .= 's';
        }
        if(isset($_SERVER['SERVER_NAME'])) {
            $base_url .= $proto.'://'.$_SERVER['SERVER_NAME'];
        }

        if(isset($_SERVER['SERVER_PORT'])) {
            if($_SERVER['SERVER_PORT'] != 80) {
                $base_url .= ':'.$_SERVER['SERVER_PORT'];
            }
        }
        // there is some plugin use case where this is needed i think. if not get rid of it.
        if (!defined('OWA_PUBLIC_URL')) {
            define('OWA_PUBLIC_URL', '');
        }

        // set base url
        $this->set('base', 'base_url', $base_url);

        //set public path if not defined in config file
        $public_path = $this->get('base', 'public_path');

        if (empty($public_path)) {
            $public_path = OWA_PATH.'/public/';
            $this->set('base','public_path', $public_path);
        }

        // set various paths
        $public_url = $this->get('base', 'public_url');
        $main_url = $public_url.'index.php';
        $this->set('base','main_url', $main_url);
        $this->set('base','main_absolute_url', $main_url);
        $modules_url = $public_url.'modules/';
        $this->set('base','modules_url', $modules_url);
        //$this->set('base','action_url',$public_url.'action.php');
        $this->set('base','images_url', $modules_url);
        $this->set('base','images_absolute_url',$modules_url);
        $this->set('base','log_url',$public_url.'log.php');
        $this->set('base','rest_api_url',$public_url.'api/index.php');

        $this->set('base', 'error_log_file', OWA_DATA_DIR . 'logs/errors_'. owa_coreAPI::generateInstanceSpecificHash() .'.txt');
        $this->set('base', 'async_log_dir', OWA_DATA_DIR . 'logs/');

        owa_coreAPI::debug('check for http host');
        // Set cookie domain
        if (!empty($_SERVER['HTTP_HOST'])) {

            $this->setCookieDomain();
        }
     }

     /**
      * Writes the config file based on the default config file - but with the given database credentials
      *
      * @param array $config_values with the database setting keys
      */
     public function createConfigFile($config_values) {

         if (file_exists(OWA_DIR.'owa-config.php')) {
             owa_coreAPI::error("Your config file already exists. If you need to change your configuration, edit that file at: ".OWA_DIR.'owa-config.php');
             require_once(OWA_DIR . 'owa-config.php');
            return true;
         }

         if (!file_exists(OWA_DIR.'owa-config-dist.php')) {
             $errorMsg = "We can't find the configuration file template. Are you sure you installed OWA's files correctly? Exiting.";
             owa_coreAPI::error($errorMsg);
             throw new Exception($errorMsg);
         }

         $configFileTemplate = file(OWA_DIR . 'owa-config-dist.php');
         owa_coreAPI::debug('found sample config file.');

         $handle = fopen(OWA_DIR . 'owa-config.php', 'w');

        foreach ($configFileTemplate as $line_num => $line) {
            switch (substr($line,0,20)) {
                case "define('OWA_DB_TYPE'":
                    fwrite($handle, str_replace("yourdbtypegoeshere", $config_values['db_type'], $line));
                    break;
                case "define('OWA_DB_NAME'":
                    fwrite($handle, str_replace("yourdbnamegoeshere", $config_values['db_name'], $line));
                    break;
                case "define('OWA_DB_USER'":
                    fwrite($handle, str_replace("yourdbusergoeshere", $config_values['db_user'], $line));
                    break;
                case "define('OWA_DB_PASSW":
                    fwrite($handle, str_replace("yourdbpasswordgoeshere", $config_values['db_password'], $line));
                    break;
                case "define('OWA_DB_HOST'":
                    fwrite($handle, str_replace("yourdbhostgoeshere", $config_values['db_host'], $line));
                    break;
                case "define('OWA_DB_PORT'":
                    fwrite($handle, str_replace("3306", $config_values['db_port'], $line));
                    break;
                case "define('OWA_PUBLIC_U":
                    fwrite($handle, str_replace("http://domain/path/to/owa/", $config_values['public_url'], $line));
                    break;
                case "define('OWA_NONCE_KE":
                    fwrite($handle, str_replace("yournoncekeygoeshere", owa_coreAPI::secureRandomString(64), $line));
                    break;
                case "define('OWA_NONCE_SA":
                    fwrite($handle, str_replace("yournoncesaltgoeshere", owa_coreAPI::secureRandomString(64), $line));
                    break;
                case "define('OWA_AUTH_KEY":
                    fwrite($handle, str_replace("yourauthkeygoeshere", owa_coreAPI::secureRandomString(64), $line));
                    break;
                case "define('OWA_AUTH_SAL":
                    fwrite($handle, str_replace("yourauthsaltgoeshere", owa_coreAPI::secureRandomString(64), $line));
                    break;
                default:
                    fwrite($handle, $line);
            }
        }

        fclose($handle);
        chmod(OWA_DIR . 'owa-config.php', 0750);
        owa_coreAPI::debug('Config file created');
        require_once(OWA_DIR . 'owa-config.php');
        return true;

    }

    function reset($module) {

        if ($module) {

            $defaults = array();
            $defaults['install_complete'] = true;
            $defaults['schema_version'] = $this->get($module, 'schema_version');
            $this->replace('base', $defaults, true);
            return $this->save();
        } else {
            return false;
        }
    }

    /**
     * sets and checks the cookie domain setting
     *
     * @param unknown_type $domain
     */
    public function setCookieDomain ($domain = '') {

        $explicit = false;

        if ( ! $domain ) {
            $domain = $_SERVER['HTTP_HOST'];
            $explicit = true;
        }

        // strip port, add leading period etc.
        $domain = owa_lib::sanitizeCookieDomain($domain);

        // Set the cookie domain only if the domain name is a Fully qualified domain name (FQDN)
        // i.e. avoid attempts to set cookie domain for e.g. "localhost" as that is not valid

        //check for two dots in the domain name
        $twodots = substr_count($domain, '.');

        if ( $twodots >= 2 ) {

            // unless www.domain.com is passed explicitly
            // strip the www from the domain.
            if ( ! $explicit ) {
                $part = substr( $domain, 0, 5 );
                if ($part === '.www.') {
                    //strip .www.
                    $domain = substr( $domain, 5);
                    // add back the leading period
                    $domain = '.'.$domain;
                }
            }

            $this->set('base','cookie_domain', $domain);
            owa_coreAPI::debug("Setting cookie domain to $domain");
         } else {
             owa_coreAPI::debug("Not setting cookie domain as $domain is not a FQDN.");
         }
     }

    function __destruct() {

        if ($this->is_dirty) {
            $this->save();
        }
    }

    /**
     * Adds a capability ot a role, creating the role if it does
     * not already exist. Also adds the capability to the
     * siteAccessRequired list if role is not 'everyone'.
     *
     * @param $role                        string    role name.
     * @param $capability                string    capability name.
     * @param $isSiteAccesssRequired    boolean    flag for adding to SA list.
     *
     */
    function addCapabilityToRole( $role, $capability, $isSiteAccessRequired = false ) {

        $caps = $this->get('base', 'capabilities');

        // check to make sure role exists
        if ( ! isset( $caps[ $role ] ) || ! is_array( $caps[ $role ] ) ) {
            $caps[ $role ] = array();
        }

        //add capability to role
        if ( is_array( $capability ) ) {
            //merge new values
            $caps[ $role ] = array_merge($caps[ $role ], $capability);
        } else {
            $caps[ $role ][] = $capability;
        }

        // unique the array
        $caps[ $role ] = array_unique( $caps[ $role ] );
        // set new values

        $this->set('base', 'capabilities', $caps);

        // make site access is required, if role is not 'everyone'
        if ( ! $role === 'everyone' && $isSiteAccessRequired ) {
            $sar = $this->get('base', 'capabilitiesThatRequireSiteAccess');
            $sar[] = $capability;
            // unique the array
            $sar = array_unique( $sar );
            $this->set('base', 'capabilitiesThatRequireSiteAccess', $sar);
        }
    }

    function removeCapabilityFromRole( $role, $capability ) {

        $caps = $this->get('base', 'capabilities');

        if ( isset( $caps[ $role ] ) && in_array( $capability, $caps[ $role ] ) ) {
            $caps[ $role ] = array_flip($caps[ $role ]);
            unset( $caps[ $role ][ $capability ] );
            $caps[ $role ] = array_unique( array_flip($caps[ $role ] ) );
            $this->set('base', 'capabilities', $caps);
        }
    }

    function removeSiteAccessRequiredFromCapability( $capability ) {

        $sar = $this->get('base', 'capabilitiesThatRequireSiteAccess');

        if ( in_array( $capability, $sar ) ) {
            $sar = array_flip( $sar );
            unset( $sar[ $capability ] );
            $sar = array_unique( array_flip($sar ) );
            $this->set('base', 'capabilitiesThatRequireSiteAccess', $sar);
        }
    }

    function getAllRolesAndCapabilities() {
        return $this->get('base', 'capabilities');
    }

    function getCapabilitiesThatRequireSiteAccess() {
        return $this->get('base', 'capabilitiesThatRequireSiteAccess');
    }

    function getCapabilitiesForRole( $role ) {

        $caps = $this->get('base', 'capabilities');

        if ( isset( $caps[ $role ] ) ) {
            return $caps[ $role ];
        }
    }
}

?>