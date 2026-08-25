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
 
 class Settings {

     /**
      * Configuration entity holding the merged settings tree.
      *
      * @var \owa_configuration
      */
     var $config;

     var $default_config;

     var $db_settings = array();

     var $fetched_from_db;

     var $is_dirty;

    /** @var bool  whether the shutdown save has been registered for this process */
    protected $shutdown_registered = false;

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
        $this->config = \OWA\Core\CoreAPI::entityFactory('base.configuration');
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
 
        /*
         * The same setting isConfigFilePresent() checks. It used to check one
         * path and include another, so the two could disagree about which file
         * an install was running on.
         */
        $file = $this->get('base', 'config_file');
        
        if ( $this->isConfigFilePresent() ) {
            
            include_once($file);
            $this->config_file_loaded = true;
        }
     }

     /**
      * Settings a config-file constant actually supplied this boot.
      *
      * Recorded so load() can keep the database from overwriting them. An
      * explicit define() in owa-config.php is a deliberate act by whoever runs
      * the installation, and it used to lose: applyConfigConstants() runs before
      * load(), and load() array_merges the database blob over the top, so a
      * constant silently stopped having any effect the first time anyone saved
      * the options form -- which writes EVERY field on the page, not only the
      * one that was edited.
      *
      * Opt-in by construction, which is what makes it safe to change: an
      * installation that defines no constants behaves exactly as before. Only a
      * key someone deliberately declared changes hands, and it changes hands to
      * what they declared.
      *
      * @var array<string, array<string, bool>>
      */
     public $config_file_constants = array();

     /**
      * Set a value that came from a config-file constant, and remember that it
      * did. Same as set(), plus the ledger entry.
      */
     private function setFromConfigConstant( $module, $key, $value, $constant ) {

         // The NAME, not just a flag: the options form shows the operator which
         // constant is governing a field, and "set somewhere in owa-config.php"
         // is not an actionable thing to tell someone.
         $this->config_file_constants[ $module ][ $key ] = $constant;

         return $this->set( $module, $key, $value );
     }

     /**
      * The config-file constant governing a setting, or '' if none is.
      *
      * Templates use this to render a field as read-only and name the constant
      * responsible. Returns the name rather than a boolean for that reason.
      *
      * @param string $module
      * @param string $key
      * @return string
      */
     public function configFileConstantFor( $module, $key ) {

         return isset( $this->config_file_constants[ $module ][ $key ] )
             ? (string) $this->config_file_constants[ $module ][ $key ]
             : '';
     }

     function applyConfigConstants() {

         if(!defined('OWA_DATA_DIR')){
            define('OWA_DATA_DIR', OWA_DIR.'owa-data/');

        }

        if (defined('OWA_DATA_DIR')) {
            $this->setFromConfigConstant( 'base', 'data_dir', OWA_DATA_DIR, 'OWA_DATA_DIR');
        }

        if(!defined('OWA_CACHE_DIR')){
            define('OWA_CACHE_DIR', OWA_DATA_DIR.'caches/');
         }

         if (defined('OWA_CACHE_DIR')) {
            $this->setFromConfigConstant( 'base', 'cache_dir', OWA_CACHE_DIR, 'OWA_CACHE_DIR');
        }

         // Looks for log level constant
        if (defined('OWA_ERROR_LOG_LEVEL')) {
            $this->setFromConfigConstant( 'base', 'error_log_level', OWA_ERROR_LOG_LEVEL, 'OWA_ERROR_LOG_LEVEL');
        }

        /* FACT-TABLE PARTITIONING */

        // These describe the shape of an installation -- how much history stays
        // finely partitioned, and how much of the server's open-file budget this
        // may claim -- rather than the behaviour of a single command, so they are
        // set once in owa-config.php:
        //
        //   define('OWA_PARTITION_DETAIL_MONTHS', 24);
        //
        // See the Partitioning Fact Tables page in the wiki.
        foreach (array(
            'OWA_PARTITION_DETAIL_MONTHS'       => 'partition_detail_months',
            'OWA_PARTITION_BUDGET_RESERVE'      => 'partition_budget_reserve',
            'OWA_PARTITION_MIN_LIMIT'           => 'partition_min_limit',
            'OWA_PARTITION_MAX_YEARS_PER_BLOCK' => 'partition_max_years_per_block',
            'OWA_PARTITION_MAX_PARTITIONS'      => 'partition_max_partitions',
        ) as $constant => $key) {

            if (defined($constant)) {
                $this->set('base', $key, constant($constant));
            }
        }

        /* SCHEDULED JOBS */

        // A single array rather than a constant per job, so an installation can
        // retune a shipped job, add one the release never registered, or run the
        // same command twice under different names -- without a code change and
        // without a new constant each time:
        //
        //   define('OWA_SCHEDULED_JOBS', array(
        //       'rotate-partitions' => array( 'params' => array( 'keep' => 24 ) ),
        //       'drain-queue'      => array( 'command'  => 'processEventQueue',
        //                                    'schedule' => '*/2 * * * *' ),
        //   ));
        //
        // Keyed by job name; see owa_service::applyConfiguredJobs() for how an
        // entry is merged and why a bad one disables only itself.
        if (defined('OWA_SCHEDULED_JOBS') && is_array(OWA_SCHEDULED_JOBS)) {
            $this->setFromConfigConstant( 'base', 'scheduled_jobs', OWA_SCHEDULED_JOBS, 'OWA_SCHEDULED_JOBS');
        }

        if (defined('OWA_SCHEDULER_ENABLED')) {
            $this->setFromConfigConstant( 'base', 'scheduler_enabled', (bool) OWA_SCHEDULER_ENABLED, 'OWA_SCHEDULER_ENABLED');
        }

        /* REPORTING TIMEZONE */

        /*
         * Declared here WINS over a value stored in the database -- see
         * stripSettingsSuppliedByConstants(), which removes the stored value
         * before load() merges it. So an operator who writes this constant gets
         * it, and gets it permanently, rather than until the next time somebody
         * saves the options form.
         *
         * That matters more for this setting than for most, because the options
         * form writes EVERY field on the page rather than only the edited one --
         * so timezone would enter the database the first time anyone saved
         * General Settings for an unrelated reason.
         *
         * The constant exists so a scripted or CLI install can declare the zone
         * up front, which matters because the choice is NOT retroactive: yyyymmdd
         * and the nine date-part columns are derived in this zone and written
         * into every fact row, so changing it later re-buckets new data while
         * history keeps the boundaries it was recorded with.
         *
         * Validated rather than trusted -- date_default_timezone_set() on an
         * unknown identifier leaves every later derivation on the previous
         * default, which is exactly the silent wrong-bucket failure this is
         * meant to prevent.
         */
        if (defined('OWA_TIMEZONE')
            && in_array( OWA_TIMEZONE, \DateTimeZone::listIdentifiers(), true )) {

            $this->setFromConfigConstant( 'base', 'timezone', OWA_TIMEZONE, 'OWA_TIMEZONE');
        }

        /* CONFIGURATION ID */

        if (defined('OWA_CONFIGURATION_ID')) {
            $this->setFromConfigConstant( 'base', 'configuration_id', OWA_CONFIGURATION_ID, 'OWA_CONFIGURATION_ID');
        }

        /* OBJECT CACHING */

        // Looks for object cache config constant
        // must comebefore user db values are fetched from db
        if (defined('OWA_CACHE_OBJECTS')) {
            $this->setFromConfigConstant( 'base', 'cache_objects', OWA_CACHE_OBJECTS, 'OWA_CACHE_OBJECTS');
        }

        /* STATIC CONFIG ONLY */

        // When true, owa_caller skips the boot-time load of user settings from
        // the owa_configuration DB table (owa_caller.php ~97). This is the ONLY
        // place the switch can be set early enough to matter: the check runs
        // before caller overrides (overloadConfig) are applied, so a value
        // passed to `new owa([...])` arrives too late. Setting it here -- from a
        // config-file define, applied in owa_settings::__construct before the
        // caller boots -- is what actually suppresses that DB read (and the
        // connection handshake it triggers). A pure file-queue logging node can
        // then accept + queue tracking events with zero DB access; everything it
        // needs must be pinned in owa-config.php since it can no longer read
        // persisted settings from the database.
        if (defined('OWA_USE_STATIC_CONFIG_ONLY')) {
            $this->setFromConfigConstant( 'base', 'useStaticConfigOnly', OWA_USE_STATIC_CONFIG_ONLY, 'OWA_USE_STATIC_CONFIG_ONLY');
        }

        /* DATABASE CONFIGURATION */

        // This needs to come before the fetch of user overrides from the DB
        // Constants defined in the config file have the final word
        // values passed from calling application must be applied prior
        // to the rest of the caller's overrides

        if (defined('OWA_DB_TYPE')) {
            $this->setFromConfigConstant( 'base', 'db_type', OWA_DB_TYPE, 'OWA_DB_TYPE');
        }

        if (defined('OWA_DB_NAME')) {
            $this->setFromConfigConstant( 'base', 'db_name', OWA_DB_NAME, 'OWA_DB_NAME');
        }

        if (defined('OWA_DB_HOST')) {
            $this->setFromConfigConstant( 'base', 'db_host', OWA_DB_HOST, 'OWA_DB_HOST');
        }

        if (defined('OWA_DB_PORT')) {
            $this->setFromConfigConstant( 'base', 'db_port', OWA_DB_PORT, 'OWA_DB_PORT');
        }

        if (defined('OWA_DB_USER')) {
            $this->setFromConfigConstant( 'base', 'db_user', OWA_DB_USER, 'OWA_DB_USER');
        }

        if (defined('OWA_DB_PASSWORD')) {
            $this->setFromConfigConstant( 'base', 'db_password', OWA_DB_PASSWORD, 'OWA_DB_PASSWORD');
        }

        /* SET ERROR HANDLER */
        if (defined('OWA_ERROR_HANDLER')) {
            $this->setFromConfigConstant( 'base', 'error_handler', OWA_ERROR_HANDLER, 'OWA_ERROR_HANDLER');
        }

        if (defined('OWA_PUBLIC_URL')) {
            $this->setFromConfigConstant( 'base', 'public_url', OWA_PUBLIC_URL, 'OWA_PUBLIC_URL');
        }

        if (defined('OWA_PUBLIC_PATH')) {
            $this->setFromConfigConstant( 'base', 'public_path', OWA_PUBLIC_PATH, 'OWA_PUBLIC_PATH');
        }

        if (defined('OWA_QUEUE_EVENTS')) {
            $this->setFromConfigConstant( 'base', 'queue_events', OWA_QUEUE_EVENTS, 'OWA_QUEUE_EVENTS');
        }

        if (defined('OWA_EVENT_QUEUE_TYPE')) {
            $this->setFromConfigConstant( 'base', 'event_queue_type', OWA_EVENT_QUEUE_TYPE, 'OWA_EVENT_QUEUE_TYPE');
        }

        if (defined('OWA_EVENT_SECONDARY_QUEUE_TYPE')) {
            $this->setFromConfigConstant( 'base', 'event_secondary_queue_type', OWA_EVENT_SECONDARY_QUEUE_TYPE, 'OWA_EVENT_SECONDARY_QUEUE_TYPE');
        }

        if (defined('OWA_USE_REMOTE_EVENT_QUEUE')) {
            $this->setFromConfigConstant( 'base', 'use_remote_event_queue', OWA_USE_REMOTE_EVENT_QUEUE, 'OWA_USE_REMOTE_EVENT_QUEUE');
        }

        if (defined('OWA_REMOTE_EVENT_QUEUE_TYPE')) {
            $this->setFromConfigConstant( 'base', 'remote_event_queue_type', OWA_REMOTE_EVENT_QUEUE_TYPE, 'OWA_REMOTE_EVENT_QUEUE_TYPE');
        }

        if (defined('OWA_REMOTE_EVENT_QUEUE_ENDPOINT')) {
            $this->setFromConfigConstant( 'base', 'remote_event_queue_endpoint', OWA_REMOTE_EVENT_QUEUE_ENDPOINT, 'OWA_REMOTE_EVENT_QUEUE_ENDPOINT');
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

        $db_config = \OWA\Core\CoreAPI::entityFactory('base.configuration');
        $db_config->getByPk('id', $id);
        // The settings blob is a nested array of scalars and nothing else --
        // save() writes serialize($this->db_settings). Refusing objects here
        // costs nothing and means a tampered-with row cannot instantiate a
        // class during unserialize.
        $db_settings = unserialize($db_config->get('settings'), ['allowed_classes' => false]);

        //print $db_settings;
        // store copy of config for use with updates and set a flag
        if (!empty($db_settings)) {

            // needed to get rid of legacy setting that used to be stored in the DB.
            if (array_key_exists('error_handler', $db_settings['base'])) {

                unset($db_settings['base']['error_handler']);
            }

            // Same treatment, generalised -- see stripConfigFileOnlySettings().
            $db_settings = self::stripConfigFileOnlySettings( $db_settings );

            /*
             * A constant declared in owa-config.php wins over the database.
             *
             * Implemented the same way the config-file-only settings are -- by
             * removing the key from the LOSING side before the merge -- because
             * load() array_merges the database over everything applyConfigConstants()
             * put in place, so precedence here can only be expressed as absence.
             *
             * Distinct from stripConfigFileOnlySettings() on purpose. That list is
             * a SECURITY denylist: keys an authenticated web request must never be
             * able to write, because doing so is an RCE primitive (error_log_file
             * + report_wrapper). This is a precedence rule about what the operator
             * explicitly declared. Same mechanism, different reason, and merging
             * the two would lose the reason.
             */
            $db_settings = $this->stripSettingsSuppliedByConstants( $db_settings );

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

        $config = \OWA\Core\CoreAPI::entityFactory('base.configuration');

        // if fetch from db flag is not true, try to fetch the config just in
        // case if was cached or something wen wrong.
        // Then merge the new values into it.
        if ($this->config_from_db != true) {

            $config->getByPk('id', $this->get('base', 'configuration_id'));

            $settings = $config->get('settings');

            if (!empty($settings)) {

                // Array data only, same as load() -- see the note there.
                $settings = unserialize($settings, ['allowed_classes' => false]);

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

         // Do not store a value that merely restates the code default.
         //
         // A stored value overrides the default FOREVER. Writing one that is
         // currently identical to the default looks harmless, but it silently
         // pins that value: when the default later changes, the install keeps
         // the old one and no longer tracks the code. report_wrapper is the
         // case that proved this -- installs had 'wrapper_default.tpl' stored
         // back when that WAS the default, so the .tpl -> .php migration could
         // not reach them and every report render fataled on an empty include
         // path, with the only clue written to OWA's own log rather than the
         // web server's.
         //
         // Keys with no code default (schema_version, install_complete) can
         // never match here, so they are always stored -- which is required,
         // since get() would otherwise return null and the install would look
         // uninstalled.
         if ( array_key_exists( $module, $this->default_config )
              && array_key_exists( $key, $this->default_config[ $module ] )
              && self::isEquivalentToDefault( $value, $this->default_config[ $module ][ $key ] ) ) {

             // Also drop any previously stored copy, so an install heals itself
             // the next time the setting is written.
             if ( isset( $this->db_settings[ $module ][ $key ] ) ) {
                 unset( $this->db_settings[ $module ][ $key ] );
                 $this->markDirty();
             }

             return;
         }

         $this->db_settings[$module][$key] = $value;
         $this->markDirty();
     }

     /**
      * Settings that must come from the config file or the installer, and must
      * NEVER be read from the database.
      *
      * These name filesystem paths, stream targets, credentials and template
      * files. Two rules combine to make a stored copy unreachable:
      *
      *   1. the options form refuses to write them (see
      *      OptionsUpdate::isSensitiveSettingKey -- allowing it is an RCE
      *      primitive), and
      *   2. load() merges the DB array OVER the config-file array, so a stored
      *      value WINS against owa-config.php.
      *
      * So once one is persisted there is no supported way to change it: not the
      * form, not the config file. Observed in the wild -- two installs carried
      * async_log_dir values pointing at a previous server's /home/padams/...
      * paths that do not exist, silently overriding a correct config file.
      *
      * Value is irrelevant here. Whatever it holds, it must not come from the
      * database, so it is dropped on load regardless -- the same treatment
      * error_handler already gets in load().
      *
      * NOT included: configuration_id, schema_version, install_complete and
      * is_active. Those are denylisted from the FORM but are legitimate
      * database state -- schema_version and install_complete have no code
      * default at all, so dropping them would make a working install look
      * uninstalled and re-run every update from scratch.
      *
      * @return array<string, array<string, bool>> module => key => true
      */
     public static function configFileOnlySettings() {

         return array(
             'base' => array(
                 'error_log_file'       => true,
                 'async_error_log_file' => true,
                 'async_log_file'       => true,
                 'async_log_dir'        => true,
                 'async_lock_file'      => true,
                 'report_wrapper'       => true,
                 'db_type'              => true,
                 'db_host'              => true,
                 'db_port'              => true,
                 'db_name'              => true,
                 'db_user'              => true,
                 'db_password'          => true,
                 'db_class_dir'         => true,
                 'plugin_dir'           => true,
                 'module_dir'           => true,
                 'templates_dir'        => true,
                 'public_path'          => true,
                 'search_engines.ini'   => true,
                 'query_strings.ini'    => true,

                 /*
                  * The role-to-capability model is configuration, not stored
                  * state. It ships with the code and is customised the same way
                  * the other entries here are -- from owa-config.php, which is
                  * included from inside this object before the configuration
                  * entity exists, so a call like
                  *
                  *     $this->addCapabilityToRole( 'everyone', ['view_reports'] );
                  *
                  * writes the defaults array and is re-applied on every request.
                  * That route is unaffected by this listing; only a copy read
                  * back out of the data store is dropped.
                  *
                  * Both keys are listed together because they are two halves of
                  * one model, and splitting them across two storage rules would
                  * be worse than either choice made consistently.
                  */
                 'capabilities'                      => true,
                 'capabilitiesThatRequireSiteAccess' => true,
             ),
         );
     }

     /**
      * Form-denylisted settings that ARE legitimate database state. Listed
      * separately so the two reasons for denylisting stay distinguishable.
      *
      * @return array<string, array<string, bool>>
      */
     public static function databaseStateSettings() {

         return array(
             'base' => array(
                 'configuration_id' => true,
                 'schema_version'   => true,
                 'install_complete' => true,
                 'is_active'        => true,
             ),
         );
     }

     /**
      * Remove config-file-only settings from a settings array loaded out of the
      * database.
      *
      * A stored copy of one of these is UNREACHABLE, and its value is
      * irrelevant to that fact:
      *
      *   - load() merges the DB array OVER the config-file array, so a stored
      *     value beats owa-config.php, and
      *   - the options form refuses to rewrite these keys.
      *
      * So there is no supported way to correct one. Two installs were found
      * carrying async_log_dir values naming a previous server's directories
      * that do not exist on the current host, silently overriding a correct
      * config file.
      *
      * Pure and static so the behaviour can be tested without a database.
      * Only the modules named in configFileOnlySettings() are touched -- every
      * other module's settings pass through untouched, which is what keeps a
      * module's own schema_version / is_active safe.
      *
      * @param  array $db_settings settings as read from the data store
      * @return array the same array minus any config-file-only keys
      */
     /**
      * Drop database values for settings a config-file constant supplied.
      *
      * Instance method, not static, because the ledger is per-boot: it records
      * what THIS process's owa-config.php actually defined. An installation that
      * defines nothing strips nothing and is completely unaffected.
      *
      * @param mixed $db_settings
      * @return mixed
      */
     public function stripSettingsSuppliedByConstants( $db_settings ) {

         if ( ! is_array( $db_settings ) ) {
             return $db_settings;
         }

         foreach ( $this->config_file_constants as $module => $keys ) {

             if ( ! isset( $db_settings[ $module ] ) || ! is_array( $db_settings[ $module ] ) ) {
                 continue;
             }

             foreach ( array_keys( $keys ) as $key ) {

                 if ( array_key_exists( $key, $db_settings[ $module ] ) ) {

                     \OWA\Core\CoreAPI::debug( sprintf(
                         'Ignoring stored %s.%s: supplied by a config file constant.',
                         $module, $key ) );

                     unset( $db_settings[ $module ][ $key ] );
                 }
             }
         }

         return $db_settings;
     }

     public static function stripConfigFileOnlySettings( $db_settings ) {

         if ( ! is_array( $db_settings ) ) {
             return $db_settings;
         }

         foreach ( self::configFileOnlySettings() as $module => $keys ) {

             if ( ! isset( $db_settings[ $module ] ) || ! is_array( $db_settings[ $module ] ) ) {
                 continue;
             }

             foreach ( array_keys( $keys ) as $key ) {

                 unset( $db_settings[ $module ][ $key ] );
             }
         }

         return $db_settings;
     }

     /**
      * Is a stored value equivalent to the code default, such that dropping it
      * changes nothing?
      *
      * Dropping a key is safe by construction: get() then returns the default.
      * The only question is whether the stored value MEANS the same thing.
      *
      * Cross-type comparison has to be loose, because the settings form submits
      * everything as strings while the defaults are typed. Real data looks like
      * '1' vs true, or NULL vs '' -- semantically identical, never === equal.
      * A strict test therefore matches nothing at all on a real install.
      *
      * PHP 8 already removed the historic hazards here: 'abc' == 0, '0' == ''
      * and 0 == '' are all false now. The one remaining trap is two NUMERIC
      * STRINGS in different notation ('1e2' == '100'), so string-to-string
      * comparison is required to be exact; loose equality applies only across
      * differing types.
      *
      * @param  mixed $stored
      * @param  mixed $default
      * @return bool
      */
     private static function isEquivalentToDefault( $stored, $default ) {

         if ( is_string( $stored ) && is_string( $default ) ) {
             return $stored === $default;
         }

         return $stored == $default;
     }

     /**
      * Drop persisted settings that merely restate the current code default.
      *
      * Historic installs accumulated these: an old config GUI wrote the whole
      * settings array rather than just changed fields, so a value identical to
      * the default of the day got stored and then pinned forever. Each one is a
      * latent bug that surfaces the moment that default changes -- see the
      * report_wrapper '.tpl' case, which turned into a fatal on every report
      * render years after it was written.
      *
      * Removing them is behaviour-preserving by definition: get() falls back to
      * the very default the stored value duplicates. Keys with no code default
      * (schema_version, install_complete) cannot match and are never touched.
      *
      * Caller is responsible for save().
      *
      * @return array list of "module.key" entries removed
      */
     public function pruneRedundantPersistedSettings() {

         $removed = array();

         foreach ( $this->db_settings as $module => $values ) {

             if ( ! is_array( $values ) ) {
                 continue;
             }

             foreach ( $values as $key => $value ) {

                 if ( ! array_key_exists( $module, $this->default_config )
                      || ! array_key_exists( $key, $this->default_config[ $module ] ) ) {
                     continue;
                 }

                 if ( self::isEquivalentToDefault( $value, $this->default_config[ $module ][ $key ] ) ) {

                     unset( $this->db_settings[ $module ][ $key ] );
                     $removed[] = $module . '.' . $key;
                     $this->markDirty();
                 }
             }
         }

         return $removed;
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
             $config2 = \OWA\Core\CoreAPI::configSingleton();
        }

         return $config2->fetch('base');

     }
     
     function setMailerDomain() {

	     // Only a fallback. This runs from the constructor, BEFORE load() merges
	     // the stored settings in, so overwriting unconditionally would clobber
	     // an address set by the config file or by an operator editing the
	     // defaults -- and the auto-computed value is 'owa@' . SERVER_NAME,
	     // which an authenticating relay rejects as an envelope sender the
	     // account does not own ("553 Sender address rejected"). A stored value
	     // still wins either way; this is about the two earlier layers.
	     if ( $this->get( 'base', 'mailer-from' ) ) {

		     return;
	     }

	     // Fall back to a valid domain: neither SERVER_NAME (CLI/cron have no
	     // web server context) nor a usable PUBLIC_URL host is guaranteed, and
	     // mailer-from below reads this unconditionally.
	     $mailer_domain = 'localhost';

	     if ( isset( $_SERVER[ 'SERVER_NAME' ] ) ) {

			 $mailer_domain = $_SERVER['SERVER_NAME'];

		 } elseif ( defined( 'PUBLIC_URL' ) ) {

			 $parts = parse_url( PUBLIC_URL );
			 $mailer_domain = $parts['host'] ?? 'localhost';
		 }

		 // A dotless domain (e.g. bare 'localhost', or an internal hostname)
		 // yields 'owa@localhost', which PHPMailer rejects as an invalid From.
		 // Append '.localdomain' so the default is a valid, deliverable address
		 // out of the box rather than relying on owa_mailer to repair it at send
		 // time. owa_mailer::repairFromAddress applies the same rule as a
		 // backstop for any persisted override.
		 if ( strpos( $mailer_domain, '.' ) === false ) {

			 $mailer_domain .= '.localdomain';
		 }

		 $this->set( 'base', 'mailer-from', 'owa@' . $mailer_domain );
     }


     /**
      * @return array
      */
     private function getDefaultSettingsArray() {
	 	 
         return array(
             'base' => array(
                /*
                 * 'ns' is the WIRE namespace. It is what keeps OWA's names from
                 * colliding with a tracked page's own: cookie names in a shared
                 * jar, the attribution params a customer puts on their URLs
                 * (owa_source, owa_campaign), the cross-domain owa_state param,
                 * and the OWA_ environment-variable prefix. Changing it breaks
                 * every existing cookie and every campaign URL in the wild.
                 *
                 * 'app_ns' is the namespace for OWA's OWN admin and reporting
                 * URLs and form fields, where OWA owns the whole query string
                 * and has nothing to collide with. It is empty: those URLs read
                 * 'do=base.sites', not 'owa_do=base.sites'.
                 *
                 * The two were one setting until the surfaces were separated.
                 * Prefixed admin URLs are still accepted on the way in -- see
                 * RequestContainer -- so existing bookmarks and links keep
                 * working; only what OWA EMITS changed.
                 */
                'ns'                                => 'owa_',
                'app_ns'                            => '',
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
                'report_wrapper'                    => 'wrapper_default.php',
                'announce_visitors'                    => false,
                'public_url'                        => '',
                'base_url'                            => '',
                'action_url'                        => '',
                'images_url'                        => '',
                'assets_url'                        => '',
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

                // Fact-table partitioning. Defaults; override with a constant in
                // owa-config.php -- see applyConfigConstants().
                //
                // How recent a period must be to keep fine granularity. Older
                // periods may be merged into coarser ones to stay within the
                // server's open-file budget -- merged, never dropped.
                'partition_detail_months'            => 36,
                // Fraction of the server's spare open-file slots partitioning may
                // claim, as a divisor: 2 means half. The cap is shared with every
                // other table on the instance, and the schema grows.
                'partition_budget_reserve'           => 2,
                // Fewest partitions a table may be limited to, whatever the
                // budget arithmetic says.
                'partition_min_limit'                => 24,
                // Largest run of calendar years that may be merged into a single
                // partition. A cap: without it, an unreachable budget would drive
                // everything into one partition, which fits no better and means
                // all of history ages out at once.
                'partition_max_years_per_block'      => 5,
                // Set to a positive integer to state the per-table partition
                // budget outright instead of deriving it from innodb_open_files.
                'partition_max_partitions'           => 0,
                'mailer-host'                        => '',
                'mailer-port'                        => '',
                'mailer-use-smtp'                    => false,
                'mailer-smtpAuth'                    => false,
                'mailer-username'                    => '',
                'mailer-password'                    => '',
                'queue_events'                        => false,
                // Retry-exhaustion caps for the processing queue. A queued event
                // that keeps failing (e.g. a session_update whose session never
                // persists, or an event for an unregistered site) is retried on
                // each processEventQueue run until it exceeds EITHER of these,
                // then marked 'broken' and retained for inspection rather than
                // retried forever. Set either to 0 to disable that check.
                'queue_max_retry_count'                => 25,          // attempts before giving up
                'queue_max_retry_age'                => 86400,       // seconds (24h) since first queued
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
                'memcachedPersistantConnections'    => true,
                'cacheType'                            => '', // file, memory, memcache
                'disabledEndpoints'                    => array('queue.php'),
                'disableAllEndpoints'                => false,
                // Scheduler. Jobs themselves are registered in code by each
                // module; this holds only what OWA_SCHEDULED_JOBS overlays on
                // top. See owa_service::loadJobs().
                'scheduled_jobs'                    => array(),
                'scheduler_enabled'                    => true,
                'maxCustomVars'                        => 5, //sdk
                'update_session_user_name'            => true, // updates the session with latest user_name value
                'log_owa_user_names'                => true,  // logs the OWA user name as the user_name property on events
                'logo_image_path'                    => 'base/i/owa-logo-100w.png',
                // Content-derived dimension ids are 63-bit. This flag marks an
                // installation whose existing ids are the old 32-bit crc32
                // values, so it keeps deriving them until its data has been
                // migrated. It is a FACT ABOUT THE DATA rather than a
                // preference: set by Update016 on an existing installation,
                // and removed by the migration command once every id has been
                // re-derived. A new installation never has it.
                'use_32bit_hash'                    => false,
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
        // Built, web-facing static assets (the webpack products AND every server-side
        // image emitted via makeImageLink) live in a dedicated public/ tree, physically
        // separated from the source they are built from so the deny-all .htaccess can
        // allow public/** wholesale without exposing PHP source, templates, or config.
        // setJs()/setCss() (assets_url) and makeImageLink() (images_url) all resolve
        // here, NOT modules_url -- which no longer serves anything to the browser. The
        // tracker family (owa.tracker/vendors/heatmap/player) now lives here too, under
        // public/base/dist/; old embeds hardcoding modules/base/dist/owa.tracker.js are
        // 301'd here, and the tracker pins its own chunk publicPath to owa_baseUrl +
        // 'public/base/dist/' so the async chunks resolve correctly through the redirect
        // (see .htaccess and src/tracker/tracker-dom.js).
        $assets_url = $public_url.'public/';
        $this->set('base','assets_url', $assets_url);
        $this->set('base','images_url', $assets_url);
        $this->set('base','images_absolute_url', $assets_url);
        $this->set('base','log_url',$public_url.'log.php');
        $this->set('base','rest_api_url',$public_url.'api/index.php');

        // Fill these only when nothing has already named a path.
        //
        // Both are declared config-file-only (configFileOnlySettings), which is
        // what a stored value from a previous server would otherwise poison --
        // so the config file is the ONE place they may be set. loadConfigFile()
        // runs before this method, so setting them unconditionally here silently
        // discarded whatever the file said, and the config-file-only contract
        // could not be honoured by the config file.
        //
        // Their declared default is '' (see the defaults array), so "empty means
        // nobody set it" is exactly the existing convention, and every install
        // that does not set them is unaffected.
        if ( ! $this->get( 'base', 'error_log_file' ) ) {

            $this->set(
                'base',
                'error_log_file',
                OWA_DATA_DIR . 'logs/errors_' . \OWA\Core\CoreAPI::generateInstanceSpecificHash() . '.txt'
            );
        }

        if ( ! $this->get( 'base', 'async_log_dir' ) ) {

            $this->set( 'base', 'async_log_dir', OWA_DATA_DIR . 'logs/' );
        }

        \OWA\Core\CoreAPI::debug('check for http host');
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

         /*
          * The same setting the loader reads.
          *
          * This method used to build the path itself while isConfigFilePresent()
          * and loadConfigFile() consulted `config_file`, so the installer could
          * write one file and the loader read another. They cannot disagree now.
          */
         $file = $this->get('base', 'config_file');

         if (file_exists($file)) {
             \OWA\Core\CoreAPI::error("Your config file already exists. If you need to change your configuration, edit that file at: ".$file);
             require_once($file);
            return true;
         }

         if (!file_exists(OWA_DIR.'owa-config-dist.php')) {
             $errorMsg = "We can't find the configuration file template. Are you sure you installed OWA's files correctly? Exiting.";
             \OWA\Core\CoreAPI::error($errorMsg);
             throw new \Exception($errorMsg);
         }

         $configFileTemplate = file(OWA_DIR . 'owa-config-dist.php');
         \OWA\Core\CoreAPI::debug('found sample config file.');

         $handle = fopen($file, 'w');

        foreach ($configFileTemplate as $line_num => $line) {
            switch (substr($line,0,20)) {
                case "define('OWA_DB_TYPE'":
                    fwrite($handle, str_replace("yourdbtypegoeshere", addcslashes( $config_values['db_type'], "\\'" ), $line));
                    break;
                case "define('OWA_DB_NAME'":
                    fwrite($handle, str_replace("yourdbnamegoeshere", addcslashes( $config_values['db_name'], "\\'" ), $line));
                    break;
                case "define('OWA_DB_USER'":
                    fwrite($handle, str_replace("yourdbusergoeshere", addcslashes( $config_values['db_user'], "\\'" ), $line));
                    break;
                case "define('OWA_DB_PASSW":
                    fwrite($handle, str_replace("yourdbpasswordgoeshere", addcslashes( $config_values['db_password'], "\\'" ), $line));
                    break;
                case "define('OWA_DB_HOST'":
                    fwrite($handle, str_replace("yourdbhostgoeshere", addcslashes( $config_values['db_host'], "\\'" ), $line));
                    break;
                case "define('OWA_DB_PORT'":
                    fwrite($handle, str_replace("3306", addcslashes( $config_values['db_port'], "\\'" ), $line));
                    break;
                case "define('OWA_PUBLIC_U":
                    fwrite($handle, str_replace("http://domain/path/to/owa/", addcslashes( $config_values['public_url'], "\\'" ), $line));
                    break;
                case "define('OWA_NONCE_KE":
                    fwrite($handle, str_replace("yournoncekeygoeshere", \OWA\Core\CoreAPI::secureRandomString(64), $line));
                    break;
                case "define('OWA_NONCE_SA":
                    fwrite($handle, str_replace("yournoncesaltgoeshere", \OWA\Core\CoreAPI::secureRandomString(64), $line));
                    break;
                case "define('OWA_AUTH_KEY":
                    fwrite($handle, str_replace("yourauthkeygoeshere", \OWA\Core\CoreAPI::secureRandomString(64), $line));
                    break;
                case "define('OWA_AUTH_SAL":
                    fwrite($handle, str_replace("yourauthsaltgoeshere", \OWA\Core\CoreAPI::secureRandomString(64), $line));
                    break;
                default:
                    fwrite($handle, $line);
            }
        }

        fclose($handle);
        chmod($file, 0750);
        \OWA\Core\CoreAPI::debug('Config file created');
        require_once($file);
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
     * @param mixed $domain
     */
    public function setCookieDomain ($domain = '') {

        $explicit = false;

        if ( ! $domain ) {
            $domain = $_SERVER['HTTP_HOST'];
            $explicit = true;
        }

        // strip port, add leading period etc.
        $domain = \OWA\Core\Lib::sanitizeCookieDomain($domain);

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
            \OWA\Core\CoreAPI::debug("Setting cookie domain to $domain");
         } else {
             \OWA\Core\CoreAPI::debug("Not setting cookie domain as $domain is not a FQDN.");
         }
     }

    /**
     * Flag unsaved settings, and arrange for them to be written while the
     * database is still reachable.
     *
     * PHP runs shutdown functions BEFORE object destructors, so saving from
     * __destruct races the database handle's own teardown. When the handle is
     * destroyed first the save throws "mysqli object is already closed": the
     * setting is silently lost, and because the Error is uncaught in a
     * destructor it also turns a CLI command that did its job into exit
     * status 255. Registering here fixes both, and costs one callback per
     * process that actually changes a setting.
     */
    protected function markDirty() {

        $this->is_dirty = true;

        if (!$this->shutdown_registered) {

            $this->shutdown_registered = true;
            register_shutdown_function(array($this, 'saveIfDirty'));
        }
    }

    /**
     * Write unsaved settings, if there are any.
     *
     * Runs during shutdown, from a registered function and again from the
     * destructor, so it must not be able to take the process down. There is no
     * caller left to hand a failure to, and an uncaught Throwable here would
     * change the exit status of a process that had already done its work --
     * which is the exact defect this class was changed to stop causing. A save
     * can legitimately fail at this point: an installation with no config file
     * has no auth key to hash a cache entry with, and a process that is shutting
     * down because the database went away has nothing to write to.
     *
     * The failure is logged rather than swallowed, because a lost setting is
     * worth a line in the log even when nothing can be done about it.
     *
     * @return void
     */
    public function saveIfDirty() {

        if (!$this->is_dirty) {
            return;
        }

        try {
            $this->save();
        } catch (\Throwable $e) {
            error_log('OWA: could not save settings during shutdown: ' . $e->getMessage());
        }
    }

    function __destruct() {

        // Fallback only: saveIfDirty() has normally already run as a shutdown
        // function, while the database was still reachable.
        $this->saveIfDirty();
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

        // make site access required, if role is not 'everyone'.
        // this read `! $role === 'everyone'`, which PHP parses as
        // `(! $role) === 'everyone'` -- a boolean compared identically against
        // a string, so always false, so the body never ran and no caller could
        // ever add a capability to the site-access list.
        if ( $role !== 'everyone' && $isSiteAccessRequired ) {
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