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
      * @var \OWA\Module\Base\Entity\Configuration
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

     /** @var bool|null  whether owa_configuration still exists; see legacyBlobIsAuthoritative(). */
     private $legacy_blob_present = null;

     /** @var array  "module|key" => registration args. The catalogue. */
     private $registry = array();

     /** @var array  id => fieldset declaration. Chrome only; see registerFieldSet(). */
     private $fieldsets = array();

     /**
      * @var array  module => true for modules that ship a settings.php.
      *
      * Adoption is per module. A module that has declared nothing keeps having
      * ALL of its stored settings loaded at boot, exactly as before registration
      * existed, so nothing has to be converted in one go.
      */
     private $declared_modules = array();

     /** @var bool  whether a read may consult the store on its own. See load(). */
     private $store_ready = false;

     /** @var bool  the undeclared-module warning is once per process, not per boot. */
     private $warned_about_undeclared = false;

     /** @var array  "module|key" => true for rows the BOOT query already applied. */
     private $loaded_at_boot = array();

     /**
      * @var bool  guards re-entry.
      *
      * resolveFromStore() calls dbSingleton(), which reads db_type back out of
      * here. Without this, that read would be an unresolved key, which would
      * resolve, which would call dbSingleton() again.
      */
     private $resolving = false;

     /** @var array  "module|key" => true for registered keys not yet resolved. */
     private $pending = array();

     /**
      * "module|key" => true once the store has been consulted for this key.
      *
      * Set whether or not a row came back. "Nothing is stored" is an answer,
      * and remembering it is what keeps a key with no row from being queried
      * on every read.
      *
      * @var array
      */
     private $loaded = array();


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

        /*
         * The settings catalogue, before anything is read from the database.
         *
         * Here rather than later because load() builds its query from it, and
         * BEFORE applyConfigConstants() because a registered default is a
         * default -- the lowest precedence there is. A constant in
         * owa-config.php still beats it, as it beats a stored value.
         */
        $this->applyRegistry();
        
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

     /**
      * Set a value that came from a config-file constant, and remember that it
      * did. Same as set(), plus the ledger entry.
      */
     private function setFromConfigConstant( $module, $key, $value, $constant ) {

         // The NAME, not just a flag: the options form shows the operator which
         // constant is governing a field, and "set somewhere in owa-config.php"
         // is not an actionable thing to tell someone.
         $this->noteConfigConstant( $module, $key, $constant );

         /*
          * A constant governs this key, so the key becomes STATIC for this
          * install: nothing may store a value for it, and nothing goes looking
          * for one.
          *
          * That is what makes the constant's precedence structural rather than
          * something every read has to remember. A stored row for a static key
          * is never queried, and persistSetting() refuses to create one -- so
          * the options form's disabled field stops being a courtesy backed by a
          * special case in OptionsUpdate and becomes the ordinary consequence
          * of the declaration.
          *
          * Declared storability is what a setting CAN be; a constant narrows it
          * for one installation. Only ever narrower -- a constant cannot make
          * something storable that was not.
          */
         if ( isset( $this->registry[ $module . '|' . $key ] ) ) {

             $this->registry[ $module . '|' . $key ]['storable'] = false;
             $this->registry[ $module . '|' . $key ]['autoload'] = false;
         }

         unset( $this->pending[ $module . '|' . $key ] );

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

         return (string) ( $this->registry[ $module . '|' . $key ]['constant'] ?? '' );
     }

     /**
      * Record that a config-file constant governs this setting.
      *
      * ON THE REGISTRY, not in a ledger beside it, because everything that
      * follows is a property of the setting: it becomes static, it is left OUT
      * OF THE BOOT QUERY, and it cannot be persisted. Keeping the name
      * somewhere else meant those three had to be kept in step by hand -- and
      * the value was fetched, overwritten by the stored row, then set back from
      * the constant, which is three steps to arrive where it started.
      *
      * The NAME, not a flag: the options form tells the operator which constant
      * governs a field, and "set somewhere in owa-config.php" is not an
      * actionable thing to tell someone.
      *
      * Creates an entry for a key nothing declared -- a constant governs it
      * whether or not a module got round to describing it.
      *
      * @return void
      */
     public function noteConfigConstant( $module, $key, $constant ) {

         $id = $module . '|' . $key;

         $args = $this->registry[ $id ] ?? array();

         /*
          * Records the constant and NOTHING ELSE. Being static follows from
          * it -- see isStorable() -- rather than being written into the entry,
          * because a written demotion has to be undone and forgetConfigConstant()
          * could not know what to put back. Derived state needs no inverse.
          */
         $args['constant'] = (string) $constant;

         $this->registry[ $id ] = $args;

         unset( $this->pending[ $id ] );
     }

     /**
      * Forget that a constant governs this setting.
      *
      * The inverse of noteConfigConstant(). Nothing in production calls it --
      * a constant does not stop existing mid-request -- but a test that
      * records one has to be able to put things back, and without this it
      * would leave the singleton claiming a constant that is not defined.
      *
      * @return void
      */
     public function forgetConfigConstant( $module, $key ) {

         $id = $module . '|' . $key;

         if ( ! isset( $this->registry[ $id ] ) ) {

             return;
         }

         unset( $this->registry[ $id ]['constant'] );

         if ( $this->registry[ $id ] === array() ) {

             // It existed only to carry the constant.
             unset( $this->registry[ $id ] );
         }
     }

     /**
      * Every setting a config-file constant governs, as module => key => name.
      *
      * @return array
      */
     public function configConstants() {

         $out = array();

         foreach ( $this->registry as $id => $args ) {

             if ( ! empty( $args['constant'] ) ) {

                 list( $module, $key ) = explode( '|', $id, 2 );

                 $out[ $module ][ $key ] = $args['constant'];
             }
         }

         return $out;
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
      * The scope every install-wide setting is stored at.
      *
      * There is one install, so the id is a constant. Carrying one at all
      * keeps a stored setting the same shape at every level, which is what
      * lets one query answer the whole chain.
      *
      * '1' AND NOT '0', which is not cosmetic. scope_id is a string column, and
      * Entity::set() skips a falsy value on one of those -- deliberately, since
      * '' is how a caller says "I have nothing" and several handlers rely on it
      * preserving the existing value. A '0' therefore writes as '', the row
      * stops matching `scope_id = '0'`, and the setting silently resolves to
      * its default. Caught by SettingsStoreConsolidationTest rather than by
      * reading the code.
      */
     const INSTALL_SCOPE_ID = '1';

     /**
      * Loads configuration from data store
      *
      * Reads the install-scope rows of owa_setting in ONE query and merges them
      * over the code defaults. Until Update043 this read a single serialized
      * blob out of owa_configuration; the merge below is unchanged, only where
      * the overrides come from.
      *
      * Which store is read is decided by legacyBlobIsAuthoritative(), the same
      * way round as save() decides where to write.
      *
      * @param string id  retained for callers; the install scope has one id
      */
     function load($id = 1) {

        $this->config_id = $id;

        $db_settings = $this->legacyBlobIsAuthoritative()
            ? $this->readLegacyConfigurationBlob()
            : $this->readInstallSettings();

        if (!empty($db_settings)) {

            // needed to get rid of legacy setting that used to be stored in the DB.
            if ( isset( $db_settings['base'] )
                 && array_key_exists('error_handler', $db_settings['base'] ) ) {

                unset($db_settings['base']['error_handler']);
            }

            /*
             * No config-file-only strip. Base declares all 21 of these static,
             * so the boot query never asks for them and persistSetting()
             * refuses to create one.
             *
             * The route this used to guard -- modules/Base/settings.php missing,
             * so Base falls back into the wholesale sweep and its rows load
             * again -- is guarded where it belongs now:
             * BaseDeclarationPresentTest fails if the file is not there, and
             * OptionsUpdate refuses to save a setting the registry does not
             * describe. A rule enforced in two places acquires two slightly
             * different definitions.
             */

            /*
             * A constant declared in owa-config.php wins over the database.
             *
             * Implemented by removing the key from the LOSING side before the
             * merge, because load() array_merges the database over everything
             * applyConfigConstants() put in place -- so precedence here can
             * only be expressed as absence.
             *
             * A precedence rule about what the operator declared, and nothing
             * more. The security half of this used to live beside it as a
             * denylist of keys an authenticated request must never write, an
             * RCE primitive in the case of error_log_file and report_wrapper.
             * That is the declaration's job now: those settings are declared
             * static, so no query asks for them and persistSetting() refuses
             * them.
             */
            $db_settings = $this->stripSettingsSuppliedByConstants( $db_settings );

            $this->db_settings = $db_settings;
            $this->config_from_db = true;

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

            /*
             * Modules absent from the stored settings are dropped here rather
             * than merged, which is how this has always behaved. It looks
             * wrong -- a module holding only in-memory values from
             * applyConfigConstants() loses them -- but default_config carries
             * 'base' alone and every install stores base rows, so nothing has
             * ever been observed to fall through it. Left as found: changing
             * it would resurrect values for modules this has been discarding
             * for years, which is not a change to make alongside a storage
             * migration.
             */
            $this->config->set('settings', $new_config);
        }

        $this->config->set('id', $id);

        /*
         * Only now may a read go to the database on its own. Before this,
         * settings are being assembled -- the config file is loading, entities
         * are being built, dbSingleton() itself is reading db_type out of here
         * -- and a lookup that queried would be asking the database how to
         * reach the database.
         */
        $this->store_ready = true;
     }

     /**
      * Every install-scope setting, as [module][key] => value.
      *
      * ONE query: the blob this replaced was read whole because a blob cannot
      * be read in part, and rows would otherwise cost a query each.
      *
      * @return array
      */
     private function readInstallSettings() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $sql = sprintf(
            "SELECT module, name, value FROM %s WHERE scope_type = 'install' AND ( %s )%s",
            $entity->getTableName(),
            $this->eagerPredicate( $db ),
            $this->constantExclusion( $db ) );

        $rows = (array) $db->get_results( $sql );

        $settings = array();

        foreach ( $rows as $row ) {

            /*
             * Recorded BEFORE the value is used, and for every row -- including
             * ones the filters below will drop. The question this answers is
             * "has the store been consulted for this key", not "did it yield a
             * value", and a key dropped by the config-file-only filter has
             * still been consulted.
             */
            $this->loaded[ $row['module'] . '|' . $row['name'] ] = true;
            $this->loaded_at_boot[ $row['module'] . '|' . $row['name'] ] = true;

            /*
             * And it is no longer pending. Registration runs in the
             * constructor, before load(), so a key the registry marked pending
             * may well be one boot then fetched -- leaving it pending would
             * send get() to the database for a value already in hand.
             */
            unset( $this->pending[ $row['module'] . '|' . $row['name'] ] );

            /*
             * Array data only, as the blob read was. A row is written by
             * save() and holds a scalar or an array of them; refusing objects
             * costs nothing and means a tampered-with row cannot instantiate a
             * class during unserialize.
             */
            $settings[ $row['module'] ][ $row['name'] ] =
                unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
        }

        return $settings;
     }

     /**
      * Whether the pre-Update043 blob is still the store of record.
      *
      * TRUE exactly while owa_configuration exists. That table is what
      * Update043 drops, so its presence is the migration's own switch rather
      * than a second flag that could disagree with the schema -- and it points
      * the same way on the way back down, when a rollback recreates it.
      *
      * Memoised: a request that saves twice should not ask twice, and the
      * table cannot appear or vanish under a running request that is not
      * itself the migration. The migration calls settingStoreRecheck() when it
      * is.
      *
      * @return bool
      */
     private function legacyBlobIsAuthoritative() {

        if ( $this->legacy_blob_present === null ) {

            $db = \OWA\Core\CoreAPI::dbSingleton();

            $legacy = \OWA\Core\CoreAPI::entityFactory( 'base.configuration' );

            $this->legacy_blob_present = (bool) $db->tableExists( $legacy->getTableName() );
        }

        return $this->legacy_blob_present;
     }

     /**
      * Forget which store is authoritative.
      *
      * For Update043 and its rollback, which change the answer mid-request.
      */
     public function settingStoreRecheck() {

        $this->legacy_blob_present = null;
     }

     /**
      * The pre-Update043 write path: one serialized blob in one row.
      *
      * Unchanged from what save() used to be, and reachable only while
      * owa_configuration exists. It goes when that table's last install does.
      *
      * @return boolean
      */
     private function saveToLegacyConfigurationBlob() {


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
      * The pre-Update043 blob, or an empty array once the table is gone.
      *
      * Only reachable during the migration window -- see load().
      *
      * @return array
      */
     private function readLegacyConfigurationBlob() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $legacy = \OWA\Core\CoreAPI::entityFactory( 'base.configuration' );

        if ( ! $db->tableExists( $legacy->getTableName() ) ) {

            return array();
        }

        /*
         * Raw, like readInstallSettings(), and not through the entity. The
         * Configuration entity is setCachable() and Update043 drops and
         * recreates its table, so an entity read in a process that has run the
         * migration can answer from a cache of the table as it was before --
         * see Update043's note on the same hazard on the write side.
         */
        $row = $db->get_row( sprintf( "SELECT settings FROM %s WHERE id = '%s'",
            $legacy->getTableName(), $db->prepare( (string) $this->config_id ) ) );

        if ( ! $row ) {

            return array();
        }

        $settings = unserialize(
            (string) $row['settings'], array( 'allowed_classes' => false ) );

        return is_array( $settings ) ? $settings : array();
     }

     /**
      * Fetches a modules entire configuration array
      *
      * @param string $module The name of module whose configuration values you want to fetch
      * @return array Config values
      */
     /**
      * One module's whole settings map, or an empty array when it has none.
      *
      * fetch() indexes $v[$module] unguarded, so asking it about a module that
      * has never stored a setting is an undefined-key warning rather than an
      * empty answer. Callers that iterate a map -- the scoped settings screens
      * -- need the empty answer.
      */
     public function getModuleSettings( $module = 'base' ) {

         $v = $this->config->get('settings');

         if ( is_array( $v ) && isset( $v[ $module ] ) && is_array( $v[ $module ] ) ) {

             return $v[ $module ];
         }

         return array();
     }

     function fetch($module = '') {
        
        $v = $this->config->get('settings');

        if (!empty($module)) {

            return $v[$module];
        
        } else {
         
            return $v['base'];
        }
     }

     /**
      * Write the install-wide settings back, one row per key.
      *
      * The stored set is made to MATCH db_settings, which is what writing
      * serialize($this->db_settings) into a single blob did implicitly. Both
      * places that drop a key -- persistSetting()'s default-equivalence prune
      * and pruneRedundantPersistedSettings() -- unset it from db_settings and
      * rely on that, so the deletes here are not an extra feature, they are
      * the half of the old behaviour that rows make explicit.
      *
      * When load() never ran, the stored rows are merged UNDER db_settings and
      * nothing is deleted. That is the same guard the blob version carried, and
      * for the same reason: a process that never read the settings does not
      * know what it would be throwing away.
      *
      * While owa_configuration still exists this writes the blob instead, by
      * the rule in legacyBlobIsAuthoritative(). It matters in both directions:
      * a rollback of Update043 restores that table, and Update::rollback() then
      * persists the reverted schema_version and calls save() -- which must land
      * in the store the rollback just restored.
      *
      * @return boolean
      */
     function save() {

        if ( $this->legacyBlobIsAuthoritative() ) {

            return $this->saveToLegacyConfigurationBlob();
        }

        $target = $this->db_settings;

        if ( $this->config_from_db != true ) {

            $stored = $this->readInstallSettings();

            foreach ( $target as $module => $values ) {

                $stored[ $module ] = array_merge(
                    isset( $stored[ $module ] ) && is_array( $stored[ $module ] )
                        ? $stored[ $module ] : array(),
                    (array) $values );
            }

            $target = $stored;

            $status = $this->writeInstallSettings( $target, false );

        } else {

            $status = $this->writeInstallSettings( $target, true );
        }

        $this->is_dirty = false;

        return $status;
     }

     /**
      * Store one row per setting, and optionally remove the rows for keys that
      * are no longer held.
      *
      * @param  array $settings [module][key] => value
      * @param  bool  $prune    delete stored keys absent from $settings
      * @return boolean
      */
     private function writeInstallSettings( $settings, $prune ) {

        $status = true;

        $wanted = array();

        foreach ( $settings as $module => $values ) {

            if ( ! is_array( $values ) ) {

                /*
                 * db_settings has been seen holding a scalar under a module
                 * key -- Update012 asserts the scan tolerates it. A scalar is
                 * not a settings map and there is no key to name a row after,
                 * so it is skipped rather than guessed at.
                 */
                continue;
            }

            foreach ( $values as $key => $value ) {

                $wanted[ $module . '|' . $key ] = true;

                if ( ! $this->writeInstallSetting( $module, $key, $value ) ) {

                    $status = false;
                }
            }
        }

        if ( ! $prune ) {

            return $status;
        }

        foreach ( $this->readInstallSettings() as $module => $values ) {

            foreach ( $values as $key => $value ) {

                if ( ! isset( $wanted[ $module . '|' . $key ] ) ) {

                    $this->deleteInstallSetting( $module, $key );
                }
            }
        }

        return $status;
     }

     /**
      * Upsert one install-scope row.
      *
      * The id is derived from the scope/module/name, so the same setting is
      * always the same row and two writers cannot produce two rows that
      * disagree. See Entity\Setting::makeId().
      *
      * @return boolean
      */
     private function writeInstallSetting( $module, $key, $value ) {

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $id = $setting->makeId( 'install', self::INSTALL_SCOPE_ID, $module, $key );

        $setting->load( $id );

        $setting->set( 'scope_type', 'install' );
        $setting->set( 'scope_id', self::INSTALL_SCOPE_ID );
        $setting->set( 'module', $module );
        $setting->set( 'name', $key );
        $setting->set( 'value', serialize( $value ) );

        if ( $setting->wasPersisted() ) {

            return $setting->update() !== false;
        }

        $setting->set( 'id', $id );
        /*
         * Set explicitly although the column defaults to 1: the column is NOT
         * NULL, and an entity that omits it is one behaviour change away from
         * sending NULL into it, which under STRICT_ALL_TABLES aborts the whole
         * statement rather than falling back to the default.
         */
        $setting->set( 'autoload', 1 );
        $setting->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        return $setting->create() !== false;
     }

     /**
      * Remove one install-scope row, so the key reverts to its code default.
      */
     private function deleteInstallSetting( $module, $key ) {

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        return $setting->delete(
            $setting->makeId( 'install', self::INSTALL_SCOPE_ID, $module, $key ) );
     }

     /**
      * Accessor Method
      *
      * @param string $module the name of the module
      * @param string $key the configuration key
      * @return mixed
      */
     function get(string $module, string $key) {

         $id = $module . '|' . $key;

         /*
          * Registered, and the store has not been consulted for it yet. One
          * query settles it: a row is applied over the default, no row leaves
          * the default standing, and either way the key is marked resolved so
          * this never runs twice.
          */
         if ( isset( $this->pending[ $id ] ) ) {

             $this->resolveAllPending();
         }

        if ( $this->config ) {
            
            $values = $this->config->get('settings');          
        
        } else {
            // setting on the default values array can only happen if a get/set 
            // is called from within the config file. 
            $values = $this->default_config;    
        }

         if ( isset( $values[$module] ) && array_key_exists($key, $values[$module])) {
             return $values[$module][$key];
         }

         /*
          * No last-ditch lookup, deliberately.
          *
          * It used to query for any key that was neither declared nor in the
          * array, justified as keeping un-adopted modules working. That
          * justification was wrong: a module with no settings.php is swept up
          * wholesale by the boot query's `module NOT IN (declared)` clause, and
          * so is one whose files were deleted but whose rows remain. The only
          * case that ever reached the fallback was a DECLARED module with a row
          * for a key its declaration does not mention -- which is a bug in the
          * declaration, and papering over it at one query per key per request
          * hid the exact condition someone needed to be told about.
          *
          * A declaration is now the complete list of what a module stores.
          * Anything else is reported by declarationProblems() and ignored, the
          * same way a key declared static already ignored its row.
          */
         return false;
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

         /*
          * A value set in memory is RESOLVED. Nothing may go to the store for
          * it afterwards.
          *
          * get() consults the pending map before it reads the array, so
          * without this a key that had not been resolved yet would be fetched
          * on the next read and the stored row would overwrite what was just
          * set. That is not hypothetical: persistSetting() sets before it
          * queues the write, so an admin saving a new MaxMind licence key and
          * a screen re-reading it to redisplay got the OLD key back -- and
          * re-saving what it displayed would have discarded the new one.
          *
          * It applies just as much to an in-memory override with no intention
          * to persist, which overloadConfig() makes: an explicit set must not
          * be silently replaced by a lazy read that happens later.
          */
         $this->loaded[ $module . '|' . $key ] = true;

         unset( $this->pending[ $module . '|' . $key ] );

         $this->writeValue( $module, $key, $value );
     }

     /**
      * Seed a declared default.
      *
      * ITS OWN PATH, deliberately, because seeding a default is not the same
      * act as setting a value and the two must not share a method.
      *
      * set() means "this is the value now", and therefore resolves the key:
      * nothing may go to the store for it afterwards. Seeding means "this is
      * what it falls back to", and must leave the key pending so the stored
      * value can still be read and applied over it.
      *
      * registerField() went through set() for one commit. Every registered
      * field was marked resolved the moment it was declared, so nothing was
      * ever pending and no stored value would ever have been read again. The
      * test that counts the batch query caught it on the first run.
      *
      * @return void
      */
     private function seedDefault( $module, $key, $value ) {

         $this->writeValue( $module, $key, $value );
     }

     /**
      * Write a value into the settings array, and claim nothing about it.
      *
      * The shared mechanism under set() and seedDefault(). It exists so those
      * two can differ in what they RECORD while agreeing on what they store.
      *
      * @return void
      */
     private function writeValue( $module, $key, $value ) {

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

         /*
          * A setting that cannot be read back must not be written.
          *
          * A static setting is never queried -- that is what not declaring
          * `storable` means -- so persisting one writes a durable row that
          * nothing will ever look at. The write succeeds, save() reports
          * success, and the value has no effect for the life of the install.
          * Demonstrated across two processes: the row is there, and getSetting
          * answers with the default.
          *
          * The same applies to a setting whose declared scopes do not include
          * install: storing it here puts it at a level nothing resolves from.
          *
          * Unregistered settings are unconstrained, as everywhere else -- base
          * has not declared yet and OptionsUpdate writes its keys through here.
          */
         if ( ! $this->mayPersistInstallWide( $module, $key ) ) {

             $governing = $this->configFileConstantFor( $module, $key );

             if ( $governing ) {

                 $why = sprintf( 'it is set by %s in owa-config.php, which wins on every boot',
                     $governing );

             } elseif ( ! self::isStorable( $this->registeredField( $module, $key ) ) ) {

                 $why = 'it is not declared storable, so nothing would ever read the value';

             } else {

                 $why = sprintf( 'it is declared for %s scope',
                     implode( ', ', (array) $this->scopesFor( $module, $key ) ) );
             }

             \OWA\Core\CoreAPI::notice( sprintf(
                 'Refusing to persist %s.%s: %s.', $module, $key, $why ) );

             return;
         }

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
      * Take the catalogue Module::settingsRegistry() assembled and record it.
      *
      * Separated from building it so that the scan lives with modules and the
      * consequences live here: defaults, the pending map, and which modules
      * have narrowed their own boot cost.
      *
      * @return void
      */
     private function applyRegistry() {

         $registry = \OWA\Core\Module::settingsRegistry();

         $this->declared_modules = (array) $registry['declared'];

         foreach ( (array) $registry['fields'] as $id => $args ) {

             list( $module, $key ) = explode( '|', $id, 2 );

             $this->registerField( $module, $key, (array) $args );
         }
     }

     /**
      * Tell whoever is looking that a module is still being resolved
      * wholesale.
      *
      * The sweep is a compatibility clause with an end (see eagerPredicate()),
      * and a module author who never hears about it will find out when it goes
      * -- by their settings quietly reverting to code defaults. A warning
      * during the grace period is the whole point of having one.
      *
      * NOT ON EVERY REQUEST. load() runs on every tracking beacon, and OWA's
      * error log is not rotated, so a line per request per module is a
      * log-growth problem rather than a message. Limited to the contexts where
      * somebody is reading: the CLI -- so `cmd=update` and the cron jobs show
      * it -- and development mode, which is this project's existing signal for
      * verbose output.
      *
      * Once per process either way: the second boot of the same request would
      * have nothing new to say.
      *
      * @return void
      */
     private function warnAboutUndeclaredModules() {

         if ( $this->warned_about_undeclared ) {

             return;
         }

         $this->warned_about_undeclared = true;

         if ( ! defined( 'OWA_CLI' )
              && \OWA\Core\CoreAPI::getSetting( 'base', 'error_handler' ) !== 'development' ) {

             return;
         }

         $undeclared = $this->undeclaredModules();

         if ( ! $undeclared ) {

             return;
         }

         \OWA\Core\CoreAPI::notice( sprintf(
             'These modules store settings but ship no settings.php, so every row they '
           . 'own is still loaded at boot: %s. That fallback is temporary -- declare '
           . 'their settings before it is removed, or their stored values will revert '
           . 'to code defaults.',
             implode( ', ', $undeclared ) ) );
     }

     /**
      * Modules with stored settings and no declaration.
      *
      * These are the ones the compatibility clause carries: boot loads every
      * row they own, because there is nothing saying which ones it needs. The
      * log notice above and cmd=instance-info both report it, from here, so
      * the two cannot come to different conclusions about which modules are
      * still relying on the fallback.
      *
      * @return array module names
      */
     public function undeclaredModules() {

         $db = \OWA\Core\CoreAPI::dbSingleton();

         $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

         $rows = (array) $db->get_results( sprintf(
             "SELECT DISTINCT module FROM %s WHERE scope_type = 'install'",
             $entity->getTableName() ) );

         $undeclared = array();

         foreach ( $rows as $row ) {

             if ( ! isset( $this->declared_modules[ $row['module'] ] ) ) {

                 $undeclared[] = $row['module'];
             }
         }

         return $undeclared;
     }

     /**
      * Modules that ship a settings.php.
      *
      * @return array module names
      */
     public function declaredModules() {

         return array_keys( $this->declared_modules );
     }

     /**
      * Keys a config-file constant governs, as a NOT clause.
      *
      * The constant is the last word, so fetching its key is fetching a value
      * that is about to be discarded. Leaving it out is the whole mechanism --
      * there is no stored value in play at any point, rather than one read,
      * overwritten and then set back. It is also what leaves
      * stripSettingsSuppliedByConstants() nothing to strip on the boot path.
      *
      * ONLY for modules that have not declared, because that is the only
      * clause this compensates for. A declared module's constant-governed key
      * is already absent from the query: noteConfigConstant() clears its
      * autoload, so it never reaches the eager list. What needs carving out is
      * the wholesale `module NOT IN (declared)` sweep, which takes every row a
      * module has whether anyone asked for it or not.
      *
      * That sweep is PERMANENT, not transitional. Third-party modules will not
      * ship declarations, so there will always be modules resolved wholesale --
      * and this will always have to hold their constants out of it.
      *
      * @param  object $db for escaping
      * @return string a leading " AND NOT ( ... )", or ''
      */
     private function constantExclusion( $db ) {

         $parts = array();

         foreach ( $this->configConstants() as $module => $keys ) {

             if ( isset( $this->declared_modules[ $module ] ) ) {

                 continue;
             }

             $quoted = array();

             foreach ( array_keys( $keys ) as $key ) {

                 $quoted[] = "'" . $db->prepare( (string) $key ) . "'";
             }

             $parts[] = sprintf( "( module = '%s' AND name IN ( %s ) )",
                 $db->prepare( (string) $module ), implode( ', ', $quoted ) );
         }

         if ( ! $parts ) {

             return '';
         }

         return sprintf( ' AND NOT ( %s )', implode( ' OR ', $parts ) );
     }

     /**
      * The keys boot must fetch, as module => list of names.
      *
      * Only what the registry declares eager. Everything else is resolved when
      * something asks for it.
      *
      * @return array
      */
     public function eagerSettings() {

         $eager = array();

         foreach ( $this->registry as $id => $args ) {

             // A governed key is static, so boot has nothing to fetch for it.
             if ( empty( $args['autoload'] ) || ! empty( $args['constant'] ) ) {

                 continue;
             }


             list( $module, $key ) = explode( '|', $id, 2 );

             $eager[ $module ][] = $key;
         }

         return $eager;
     }

     /**
      * What boot fetches, as a WHERE fragment.
      *
      * Three parts, and the third is the one that makes this safe to land:
      *
      *   1. the mechanical names, for every module including ones core has
      *      never heard of;
      *   2. what each module that HAS declared says boot needs;
      *   3. EVERYTHING belonging to a module that has declared nothing.
      *
      * Part 3 is the adoption path. Before registration existed every stored
      * setting was loaded at boot, and a module that has not adopted keeps
      * exactly that behaviour -- so this can ship without converting anything,
      * and each module narrows its own boot cost when it declares.
      *
      * PART 3 IS MEANT TO BE REMOVED. It is a compatibility clause, not a
      * permanent feature: every module in this repository can declare, and a
      * third-party module needs a few releases' notice to do the same. Once
      * that notice has been given and taken, delete it and the boot query
      * becomes a plain statement of what boot wants -- the mechanical names
      * plus each module's declared eager keys, and nothing else.
      *
      * Know what removal does before doing it: an undeclared module's stored
      * settings stop being read. Not an error, not a warning -- its values
      * quietly revert to whatever its code defaults say, which for `is_active`
      * and `schema_version` is covered by part 1, and for everything else is
      * not. So it wants a release note and a version to land in, not a quiet
      * commit. declarationProblems() already reports the same condition for
      * modules that HAVE declared, and is the right place to surface the
      * warning for those that have not.
      *
      * @param  object $db for escaping
      * @return string
      */
     private function eagerPredicate( $db ) {

         $parts = array();

         $names = array();

         foreach ( self::mechanicalSettingNames() as $name ) {

             $names[] = "'" . $db->prepare( $name ) . "'";
         }

         $parts[] = sprintf( 'name IN ( %s )', implode( ', ', $names ) );

         foreach ( $this->eagerSettings() as $module => $keys ) {

             $quoted = array();

             foreach ( $keys as $key ) {

                 $quoted[] = "'" . $db->prepare( $key ) . "'";
             }

             $parts[] = sprintf( "( module = '%s' AND name IN ( %s ) )",
                 $db->prepare( $module ), implode( ', ', $quoted ) );
         }

         if ( $this->declared_modules ) {

             $declared = array();

             foreach ( array_keys( $this->declared_modules ) as $module ) {

                 $declared[] = "'" . $db->prepare( $module ) . "'";
             }

             $parts[] = sprintf( 'module NOT IN ( %s )', implode( ', ', $declared ) );

         } else {

             // Nothing has declared, so nothing is narrowed: load it all.
             $parts[] = '1 = 1';
         }

         $this->warnAboutUndeclaredModules();

         return implode( ' OR ', $parts );
     }

     /**
      * Keys that are eager for EVERY module, matched by name alone.
      *
      * is_active and schema_version mean the same thing wherever they appear,
      * and boot needs all of them -- getActiveModules() decides what loads by
      * scanning the settings array for is_active, so a module missing from that
      * scan simply does not load.
      *
      * Matched by name rather than per module because core cannot name the
      * modules: the directory-to-runtime-name mapping is lossy (see
      * buildRegistry()). `name IN (...)` needs no module names at all, and is
      * exactly right -- these are eager wherever they occur, including for a
      * third-party module core has never heard of.
      *
      * @return array
      */
     public static function mechanicalSettingNames() {

         return array_keys( \OWA\Core\Module::mechanicalSettings() );
     }


     /**
      * Declare a setting: what it defaults to, whether boot needs it, and --
      * only if the UI should render it -- how to draw it.
      *
      * Registration is the catalogue. Every setting is declared, including the
      * ones only code ever writes; those simply carry no chrome, which is what
      * keeps them off the settings screens without a second list to maintain.
      * schema_version is the shape: boot cannot start without it, the UI must
      * never offer it, and both of those are said here rather than in a
      * denylist that fails open.
      *
      * Registering does NOT read the database. It records that the key MIGHT
      * have a stored value, and the read happens when someone asks for it --
      * unless boot already resolved it, in which case there is nothing left to
      * do and the key is never marked pending at all.
      *
      * @param string $module
      * @param string $key
      * @param array  $args  default, autoload, and optional chrome
      * @return void
      */
     public function registerField( $module, $key, array $args = array() ) {

         $id = $module . '|' . $key;

         /*
          * A constant already governs this key, so it is static here whatever
          * the declaration says.
          *
          * Checked on the way IN as well as when the constant is applied,
          * because the two can happen in either order: constants are applied
          * during boot, but a module registering through
          * Module::registerSettingsField() does so when it loads, which is
          * after. Without this, that later registration would hand storability
          * back and the constant would stop being the last word.
          */
         /*
          * Carried across a later declaration. Constants are applied during
          * boot and a module registers when it loads, which is after -- so
          * without this, that registration would drop the constant and the
          * setting would stop being static.
          */
         $governing = $this->configFileConstantFor( $module, $key );

         if ( $governing ) {

             $args['constant'] = $governing;
         }

         $this->registry[ $id ] = $args;

         if ( array_key_exists( 'default', $args ) && ! isset( $this->default_config[ $module ][ $key ] ) ) {

             $this->default_config[ $module ][ $key ] = $args['default'];

             if ( ! $this->isLoaded( $module, $key ) ) {

                 $this->seedDefault( $module, $key, $args['default'] );
             }
         }

         /*
          * Only a STORABLE setting is ever looked up.
          *
          * The vast majority of registered settings are static: a code
          * constant with a default and no way to persist one, which will never
          * have a row no matter how long the install runs. Marking those
          * pending would mean going to the database to discover an absence
          * that is guaranteed by the declaration itself.
          *
          * Boot already resolved the eager set, so those are not pending
          * either. What is left -- storable, not eager -- is the only thing
          * the batch has to ask about.
          */
         if ( self::isStorable( $args ) && ! $this->isLoaded( $module, $key ) ) {

             $this->pending[ $id ] = true;
         }
     }

     /**
      * Record a fieldset: a group of settings that render together.
      *
      * Chrome only. Nothing here affects what is read from the database --
      * a fieldset listing a setting does not make it storable, and a fieldset
      * that lists a setting which is NOT storable is a page promising to save
      * something that cannot be saved. See fieldSetProblems().
      *
      * @param array $set
      * @return void
      */
     public function registerFieldSet( array $set ) {

         $this->fieldsets[ (string) $set['id'] ] = $set;
     }

     /** Every registered fieldset, as id => declaration. */
     public function registeredFieldSets() {

         return $this->fieldsets;
     }

     /**
      * Stored values a declaration has orphaned.
      *
      * A declaration is the complete list of what a module stores. Two ways a
      * stored row can fall outside it, and BOTH mean the value is ignored:
      *
      *   - the key is not in the declaration at all;
      *   - the key is declared without `storable`, so it is never queried.
      *
      * Neither is visible otherwise. The row sits in the table, the setting
      * answers with its default, and nothing reports the disagreement -- which
      * is the whole reason the read path stopped quietly querying for the first
      * case rather than continuing to mask it.
      *
      * Only DECLARED modules are examined. A module with no settings.php has
      * all its rows loaded at boot and has made no claim to contradict.
      *
      * This QUERIES. It is for an operator asking what is wrong, not for a
      * request path.
      *
      * @return array human-readable strings
      */
     public function declarationProblems() {

         if ( ! $this->declared_modules ) {

             return array();
         }

         $db = \OWA\Core\CoreAPI::dbSingleton();

         $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

         $quoted = array();

         foreach ( array_keys( $this->declared_modules ) as $module ) {

             $quoted[] = "'" . $db->prepare( (string) $module ) . "'";
         }

         $rows = (array) $db->get_results( sprintf(
             "SELECT module, name FROM %s WHERE scope_type = 'install' AND module IN ( %s )",
             $entity->getTableName(), implode( ', ', $quoted ) ) );

         $problems = array();

         foreach ( $rows as $row ) {

             $args = $this->registeredField( $row['module'], $row['name'] );

             if ( ! $args ) {

                 $problems[] = sprintf(
                     '%s stores %s but does not declare it, so the stored value is ignored',
                     $row['module'], $row['name'] );

                 continue;
             }

             if ( ! self::isStorable( $args ) ) {

                 $problems[] = sprintf(
                     '%s stores %s but declares it static, so the stored value is ignored',
                     $row['module'], $row['name'] );
             }
         }

         return $problems;
     }

     /**
      * Declarations that cannot work, as human-readable strings.
      *
      * Reported rather than thrown. A settings screen with one bad field
      * should render its other fields, and an install should not fail to boot
      * over a third-party module's typo -- but the mistake has to be visible,
      * because both of these fail silently otherwise: a fieldset naming a
      * setting nobody registered renders an empty row, and one naming a
      * setting that is not storable renders a form whose save does nothing.
      *
      * @return array
      */
     public function fieldSetProblems() {

         $problems = array();

         foreach ( $this->fieldsets as $id => $set ) {

             $module = (string) ( $set['module'] ?? '' );

             foreach ( (array) ( $set['settings'] ?? array() ) as $key ) {

                 $args = $this->registeredField( $module, $key );

                 if ( ! $args ) {

                     $problems[] = sprintf(
                         'fieldset %s lists %s.%s, which no module registered', $id, $module, $key );

                     continue;
                 }

                 if ( ! self::isStorable( $args ) ) {

                     $problems[] = sprintf(
                         'fieldset %s renders %s.%s, which is not storable: the form would save nothing',
                         $id, $module, $key );
                 }
             }
         }

         return $problems;
     }

     /**
      * Whether this setting may be stored install-wide.
      *
      * True for anything unregistered. Only a setting that HAS declared is
      * held to what it declared.
      *
      * @return bool
      */
     public function mayPersistInstallWide( $module, $key ) {

         /*
          * Checked BEFORE registration, because a constant makes a key static
          * whether or not anything declared it. The constant is the last pass
          * of boot and beats the stored value, so persisting one writes a row
          * that will never be read -- the same durable no-op that persisting a
          * static setting is, arrived at from the other direction.
          *
          * This is also the rule OptionsUpdate states for itself. Having it
          * here means a page that forgets to state it still cannot write one.
          */
         if ( $this->configFileConstantFor( $module, $key ) ) {

             return false;
         }

         if ( ! $this->isRegistered( $module, $key ) ) {

             return true;
         }

         $args = $this->registeredField( $module, $key );

         if ( ! self::isStorable( $args ) ) {

             return false;
         }

         return in_array( 'install', (array) $this->scopesFor( $module, $key ), true );
     }

     /**
      * The scopes a setting may be stored at, or null when nothing declared it.
      *
      * null is not "no scopes" -- it means UNKNOWN, and the caller must treat
      * it as unconstrained. Base has not adopted a declaration file yet, so
      * every one of its scoped settings is unregistered, and a rule that read
      * null as "install only" would refuse the writes the Observation Settings
      * screen has always made.
      *
      * @return array|null
      */
     public function scopesFor( $module, $key ) {

         if ( ! $this->isRegistered( $module, $key ) ) {

             return null;
         }

         $args = $this->registeredField( $module, $key );

         /*
          * Install-only unless the declaration says otherwise. A setting that
          * has not thought about the hierarchy should not silently acquire
          * per-Property overrides -- schema_version is the case that matters,
          * and it is why the default is the narrow one.
          */
         return array_values( (array) ( $args['scopes'] ?? array( 'install' ) ) );
     }

     /**
      * Whether this setting may be written at this scope.
      *
      * True for anything unregistered, which is the adoption path: a
      * third-party module, or base before it declares, keeps working exactly
      * as it did. Only a setting that HAS declared its scopes is held to them.
      *
      * @return bool
      */
     public function mayWriteAtScope( $module, $key, $scopeType ) {

         $scopes = $this->scopesFor( $module, $key );

         if ( $scopes === null ) {

             return true;
         }

         return in_array( (string) $scopeType, $scopes, true );
     }

     /**
      * Whether a value for this setting can be persisted.
      *
      * ONE flag, said explicitly. It decides whether the code ever goes
      * looking for a stored value, which is too load-bearing to infer from
      * something else -- an earlier version read it off the presence of a
      * `type` key, so one rule was stated in two places and a field could
      * become queryable by acquiring a label.
      *
      * Without it a setting is static: a code constant with a default, in the
      * catalogue so the UI layer knows it exists and refuses to write it, and
      * never queried however long the install runs. That is the vast majority
      * of them.
      *
      * `autoload` implies it. Declaring that boot must fetch a value only
      * means anything if a value can exist.
      *
      * @param  array $args
      * @return bool
      */
     public static function isStorable( array $args ) {

         /*
          * A config-file constant makes the setting static, whatever it
          * declared. The constant is the last word on boot, so a stored value
          * could never be read -- and this is where that is said, once, rather
          * than by rewriting the declaration when the constant is applied.
          */
         if ( ! empty( $args['constant'] ) ) {

             return false;
         }

         return ! empty( $args['storable'] ) || ! empty( $args['autoload'] );
     }

     /** Whatever was registered for a key, or an empty array. */
     public function registeredField( $module, $key ) {

         return $this->registry[ $module . '|' . $key ] ?? array();
     }

     /**
      * Whether anything declared this key at all.
      *
      * Distinct from registeredField() being non-empty, and the distinction is
      * load-bearing: a setting can be declared with NOTHING -- static, no
      * default, no chrome -- which is exactly what a config-file-only setting
      * whose default is derived from OWA_DIR looks like. Reading the empty
      * array as "not registered" made those unconstrained, so persistSetting()
      * would have accepted a stored db_class_dir. Caught by the contract test
      * the moment the baked-in paths were removed.
      */
     public function isRegistered( $module, $key ) {

         return isset( $this->registry[ $module . '|' . $key ] );
     }

     /** Every registered key, as "module|key" => args. */
     public function registeredFields() {

         return $this->registry;
     }

     /** Whether this key has already been resolved against the database. */
     private function isLoaded( $module, $key ) {

         return isset( $this->loaded[ $module . '|' . $key ] );
     }

     /**
      * Resolve several settings in ONE query.
      *
      * The batch exists because a settings screen reads a whole page of keys
      * at once, and resolving them one at a time would be a query each. Keys
      * already resolved are dropped before the query, so calling this twice
      * costs one query and then none.
      *
      * A key with NO ROW is resolved too. "There is nothing stored" is an
      * answer -- the default stands -- and recording it is what stops the next
      * read asking again.
      *
      * @param  array $pairs  list of array($module, $key)
      * @return array "module|key" => effective value
      */
     public function getSettings( array $pairs ) {

         $wanted = array();

         foreach ( $pairs as $pair ) {

             list( $module, $key ) = $pair;

             $id = $module . '|' . $key;

             if ( ! $this->isLoaded( $module, $key ) ) {

                 $wanted[ $id ] = array( $module, $key );
             }
         }

         if ( $wanted ) {

             $this->resolveFromStore( $wanted );
         }

         $out = array();

         foreach ( $pairs as $pair ) {

             list( $module, $key ) = $pair;

             $out[ $module . '|' . $key ] = $this->get( $module, $key );
         }

         return $out;
     }

     /**
      * Resolve EVERY pending key at once, in one query.
      *
      * Registering a setting says "there might be a row for this". Asking the
      * database once per key would make registration expensive in proportion
      * to how many settings exist -- base alone has 159, and about six of them
      * have ever been stored, so that would be ~150 queries to discover ~150
      * absences.
      *
      * Collectively it is one query, and it does not enumerate the keys: the
      * rows not already in hand ARE the answer, and there are only ever as many
      * as someone has actually stored. Every pending key is then marked
      * resolved, including the overwhelming majority that had no row, because
      * "nothing is stored" is an answer and recording it is what stops this
      * running again.
      *
      * The result: a request that only reads eager settings never runs this at
      * all, and one that reads any non-eager setting runs it exactly once.
      *
      * @return void
      */
     private function resolveAllPending() {

         if ( ! $this->pending || ! $this->store_ready || $this->resolving ) {

             return;
         }

         $this->resolving = true;

         /*
          * Kept, because only these keys may be APPLIED.
          *
          * The query below asks for the rows it does not already have, which
          * is cheaper than enumerating a hundred names -- but that means it
          * also brings back rows for keys nobody asked about: static ones, and
          * the config-file-only ones whose whole point is never to come from
          * the database. Applying what arrived rather than what was wanted
          * made a static setting's stored value take effect whenever something
          * else happened to trigger the batch, and do nothing when it did not.
          */
         $wanted = $this->pending;

         foreach ( array_keys( $this->pending ) as $id ) {

             $this->loaded[ $id ] = true;
         }

         $this->pending = array();

         $db = \OWA\Core\CoreAPI::dbSingleton();

         $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

         $rows = (array) $db->get_results( sprintf(
             "SELECT module, name, value FROM %s WHERE scope_type = 'install'",
             $entity->getTableName() ) );

         $stored = array();

         foreach ( $rows as $row ) {

             $id = $row['module'] . '|' . $row['name'];

             /*
              * Rows boot already applied are skipped rather than re-applied:
              * load() ran its two filters over them, and re-applying the raw
              * value here would put back a config-file-only setting that was
              * deliberately dropped.
              */
             if ( isset( $this->loaded_at_boot[ $id ] ) || ! isset( $wanted[ $id ] ) ) {

                 continue;
             }

             $stored[ $row['module'] ][ $row['name'] ] =
                 unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
         }

         /*
          * No config-file-only strip here, and no constant strip either. Both
          * are redundant on this path now: only keys that were PENDING are
          * applied, a config-file-only setting is declared static so is never
          * pending, and a constant-governed key is made static the moment the
          * constant is applied.
          *
          * The strip stays in load(), which is not redundant -- see there.
          */

         foreach ( $stored as $module => $values ) {

             foreach ( $values as $key => $value ) {

                 $this->set( $module, $key, $value );
             }
         }

         $this->resolving = false;
     }

     /**
      * One query for a set of keys, applied over whatever the defaults say.
      *
      * Marks every key asked for as resolved, not merely the ones a row came
      * back for. That is the whole reason this can be called from get()
      * without turning every miss into a query.
      *
      * @param array $wanted "module|key" => array(module, key)
      * @return void
      */
     private function resolveFromStore( array $wanted ) {

         if ( ! $this->store_ready || $this->resolving ) {

             return;
         }

         $this->resolving = true;

         foreach ( $wanted as $id => $pair ) {

             $this->loaded[ $id ] = true;

             unset( $this->pending[ $id ] );
         }

         $db = \OWA\Core\CoreAPI::dbSingleton();

         $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

         $predicates = array();

         foreach ( $wanted as $pair ) {

             $predicates[] = sprintf( "( module = '%s' AND name = '%s' )",
                 $db->prepare( (string) $pair[0] ), $db->prepare( (string) $pair[1] ) );
         }

         $rows = (array) $db->get_results( sprintf(
             "SELECT module, name, value FROM %s WHERE scope_type = 'install' AND ( %s )",
             $entity->getTableName(), implode( ' OR ', $predicates ) ) );

         if ( ! $rows ) {

             $this->resolving = false;

             return;
         }

         $stored = array();

         foreach ( $rows as $row ) {

             $stored[ $row['module'] ][ $row['name'] ] =
                 unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
         }

         /*
          * The same two filters the boot load applies. A stored value is a
          * stored value whenever it is read: a config-file constant still beats
          * it, and a config-file-only key must still never come from the
          * database, which is a security rule rather than a precedence one.
          */
         /*
          * No config-file-only strip here, and no constant strip either. Both
          * are redundant on this path now: only keys that were PENDING are
          * applied, a config-file-only setting is declared static so is never
          * pending, and a constant-governed key is made static the moment the
          * constant is applied.
          *
          * The strip stays in load(), which is not redundant -- see there.
          */

         foreach ( $stored as $module => $values ) {

             foreach ( $values as $key => $value ) {

                 $this->set( $module, $key, $value );
             }
         }

         $this->resolving = false;
     }

     /**
      * Remove a stored install setting, so the key falls back to its code
      * default.
      *
      * persistSetting() cannot express this. Writing '' or false STORES that
      * value -- a row holding an empty string is not the same as no row, and
      * the caller who meant "unset this" gets a setting that overrides the
      * default with emptiness forever. SettingsShutdownSaveTest did exactly
      * that and left `base.owa_settings_shutdown_probe` in the config of every
      * install it ever ran against, including this one.
      *
      * The row goes on the next save(), through the pruning that already
      * removes stored keys db_settings no longer holds.
      *
      * @return void
      */
     public function removeSetting( $module, $key ) {

         unset( $this->db_settings[ $module ][ $key ] );

         /*
          * And out of the live array, or this request keeps answering with the
          * value it just removed.
          */
         if ( array_key_exists( $module, $this->default_config )
              && array_key_exists( $key, $this->default_config[ $module ] ) ) {

             $this->set( $module, $key, $this->default_config[ $module ][ $key ] );

         } else {

             $values = $this->config ? $this->config->get('settings') : $this->default_config;

             unset( $values[ $module ][ $key ] );

             if ( $this->config ) {
                 $this->config->set( 'settings', $values );
             } else {
                 $this->default_config = $values;
             }
         }

         $this->markDirty();
     }

     /**
      * Settings that cannot hold a stored value, DERIVED from the registry.
      *
      * The replacement for configFileOnlySettings(), which was a
      * hand-maintained list of 21 keys saying what the declaration now says:
      * not storable. Two statements of one rule are two things to keep in
      * step, and the list is the one that goes.
      *
      * A setting is here because its module declared it without `storable`, or
      * because a config-file constant governs it. Nothing to update when a
      * setting is added -- the declaration is the source.
      *
      * @return array module => key => true
      */
     public static function staticSettings() {

         $c = \OWA\Core\CoreAPI::configSingleton();

         $out = array();

         foreach ( $c->registeredFields() as $id => $args ) {

             if ( self::isStorable( (array) $args ) ) {

                 continue;
             }

             list( $module, $key ) = explode( '|', $id, 2 );

             $out[ $module ][ $key ] = true;
         }

         return $out;
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

         foreach ( $this->configConstants() as $module => $keys ) {

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
                 * 'do=base.reportingHome', not 'owa_do=base.reportingHome'.
                 *
                 * The two were one setting until the surfaces were separated.
                 * Prefixed admin URLs are still accepted on the way in -- see
                 * RequestContainer -- so existing bookmarks and links keep
                 * working; only what OWA EMITS changed.
                 */
                'ns'                                => 'owa_',
                'app_ns'                            => '',
                'feed_subscription_param'            => 'sid',
                'source_param'                        => 'source',
                'site_id'                            => '',
                'configuration_id'                    => '1',
                'session_length'                    => 1800, //sdk
                'db_type'                            => '',
                'db_name'                            => '',
                'db_host'                            => '',
                'db_port'                            => 3306,
                'db_user'                            => '',
                'db_password'                        => '',
                'db_force_new_connections'            => true,
                'db_make_persistant_connections'    => false,
                'resolve_hosts'                        => true,
                'log_robots'                        => false,
                'clean_query_string'                => true,
                'query_string_filters'                => '', // move to site settings
                'async_log_dir'                        => '', //OWA_DATA_DIR . 'logs/',
                'async_log_file'                    => 'events.txt',
                'async_lock_file'                    => 'owa.lock',
                'async_error_log_file'                => 'events_error.txt',
                'notice_email'                        => '',
                'error_handler'                        => 'production',
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
                'p3p_policy'                        => 'NOI ADM DEV PSAi COM NAV OUR OTRo STP IND DEM',
                'link_template'                        => '%s?%s', // main_url?key=value....
                'owa_user_agent'                    => 'Open Web Analytics Bot '.OWA_VERSION,
                'owa_news_url'                        => 'https://api.github.com/repositories/3891123/releases?page=1&per_page=5',
                'timezone'                            => 'America/Los_Angeles',
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
                // Fewest partitions a table may be limited to, whatever the
                // budget arithmetic says.
                // How long a day stays in the cube's daily front tier before
                // its month merges back. This IS the late-arrival window: past
                // it, an event for that day is in raw and not in the cube
                // until someone rebuilds that month. 3.1 has the number open
                // pending a measurement of client-side lateness.
                'cube_rebuild_window_days'           => 7,
                // Largest run of calendar years that may be merged into a single
                // partition. A cap: without it, an unreachable budget would drive
                // everything into one partition, which fits no better and means
                // all of history ages out at once.
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
                'remote_event_queue_endpoint'        => '',
                'allowed_queued_event_types'        => [],
                'cookie_domain'                        => false,
                'cookie_persistence'                => true,  // Controls persistence of cookies, only for use in europe needed
                'is_active'                            => true,
                'cache_objects'                        => false,
                'log_named_users'                    => true,
                'log_visitor_pii'                    => true,
                'excluded_ips'                        => '',
                'anonymize_ips'                        => false,
                'theme'                                => '',
                'reserved_words'                    => array('do' => 'action'),
                'start_page'                        => 'base.reportingHome',
                'default_page'                        => '', // move to site settings
                'default_cache_expiration_period'    => 604800,
                'nonce_expiration_period'            => 7200,
                'default_reporting_period'            => 'last_seven_days',
                /*
                 * campaignAttributionWindow stood here, 60 days, and was inert
                 * for its whole life: StateManager::set() overwrote its own
                 * expiration argument, so the campaign cookie was a browser-
                 * session cookie whatever this said. Removed rather than
                 * repaired -- v2 resolves attribution from stored evidence at
                 * read time, where a window is a reporting choice and not a
                 * cookie lifetime frozen at collection.
                 */
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
                                'edit_modules',
                                // Authoring a custom report. Admin-only by
                                // default; grant it to another role in the
                                // config file to let analysts build their own.
                                // NOT in capabilitiesThatRequireSiteAccess: a
                                // custom report is site-agnostic, so there is
                                // no one site to check access against.
                                'edit_reports',
                                // Changing the email address on your own
                                // account. Admin-only by default; grant it to
                                // another role in the config file to let people
                                // change their own.
                                //
                                // Separate from edit_users, which is about
                                // other people's accounts. The address is where
                                // password resets are sent, so changing it
                                // moves who can recover the account -- everyone
                                // may edit their own name without that being
                                // true of their address.
                                //
                                // Not site-scoped, so not in
                                // capabilitiesThatRequireSiteAccess.
                                'edit_own_email'
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
                /*
                 * v2's event names. Kept as their own list rather than merged
                 * into tracking_event_types, so that what v1 collects and what
                 * v2 collects stay legible as two sets -- the whole of v1's
                 * side is retired at cutover, and a merged list would have to
                 * be untangled then.
                 *
                 * page_view, click and purchase are NOT here: they arrive under
                 * their v1 names and Classes\V2Event maps them. Only the names
                 * that have no v1 spelling need admitting.
                 */
                'v2_event_types'                    => [
                    'user_engagement',
                    'scroll',
                    'file_download',
                    'form_start',
                    'form_submit',
                    'view_search_results',
                    'exception',
                    'custom_event',
                ],
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

        /*
         * The file is NOT loaded here.
         *
         * Its constants are already defined in this request -- InstallConfig
         * defines the database ones from the submitted form so it can test the
         * connection before writing anything, and install.php defines the error
         * handler and cache ones. Loading the file then re-ran define() on all
         * of them, and define() on an existing constant keeps the first value
         * and emits a warning: eight "Constant OWA_DB_TYPE already defined in
         * owa-config.php" warnings per install, in the error log, and printed
         * ahead of the redirect below on any host with display_errors on.
         *
         * Nothing needs it loaded. The only caller redirects immediately
         * (InstallConfig::action), and a redirect is a new request that reads
         * the file from scratch with nothing predefined.
         *
         * Guarding the defines in owa-config-dist.php would have fixed only
         * files written from that template afterwards; every existing install
         * would keep warning.
         */
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