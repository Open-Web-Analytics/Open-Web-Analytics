<?php
namespace OWA\Core;


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
 * OWA Core API
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class CoreAPI {

    /** @var array  site id => is it registered. See isSiteRegistered(). */
    protected static $registered_sites = array();



    // @depricated
    // @todo remove
    public static function singleton($params = array()) {

        static $api;

        if(!isset($api)):
            $api = new \OWA\Core\CoreAPI();
        endif;

        if(!empty($params)):
            $api->params = $params;
        endif;

        return $api;
    }

    /**
     * Load the DB driver class for a given database type.
     *
     * THIRD-PARTY DB-DRIVER SEAM
     * --------------------------
     * OWA's bundled drivers (e.g. "mysql") live in the OWA\Core\Db namespace
     * and autoload via Composer; their legacy `owa_db_<type>` names resolve
     * through the compat bridge (owa_compat_aliases.php). A third party can add
     * support for another database WITHOUT patching OWA core: drop a class file
     * at `plugins/db/owa_db_<type>.php` declaring `class owa_db_<type> extends
     * \owa_db`, then set the `db_type` config to `<type>`.
     *
     * The `plugins/` directory is NOT shipped in the repo — it is a convention
     * location that a driver author creates in their own install. Because this
     * method `require`s the file by its constructed path (below), the driver
     * does NOT need to be Composer-classmap-discoverable to load; and because
     * the file is untracked by OWA's git, a `git pull` upgrade never removes it.
     */
    /**
     * The driver name to actually load for a configured db_type.
     *
     * Every existing install has db_type = 'mysql', which historically meant the
     * mysqli driver. It now means "MySQL", and PDO is used to reach it wherever
     * pdo_mysql is available -- so installs get bound parameters without editing
     * owa-config.php, and a host that has mysqli but NOT pdo_mysql keeps working
     * instead of being left broken by an upgrade. That combination is unusual on
     * distro-packaged PHP, where one package ships both, but it is entirely
     * possible in containers and hand-built PHP, where extensions are enabled
     * one at a time.
     *
     * Anything explicit is honoured as written:
     *
     *   'mysql'      MySQL, preferring PDO, falling back to mysqli   (default)
     *   'mysqli'     force the mysqli driver
     *   'pdo_mysql'  force MySQL over PDO
     *   'pdo'        friendly alias for pdo_mysql
     *   'pdo_pgsql'  Postgres over PDO, once a PgsqlDialect exists
     *
     * A third-party owa_db_<type> from the plugins/ seam is passed through
     * untouched.
     *
     * @param string $type
     * @return string
     */
    public static function resolveDbDriver($type, $pdo_available = null) {

        // Injectable so the fallback can be tested on a machine that has
        // pdo_mysql, which is every machine that runs the suite.
        if ( $pdo_available === null ) {

            $pdo_available = extension_loaded('pdo_mysql');
        }

        if ( $type === 'mysql' ) {

            // Both arms must name a driver the loader can find: the caller
            // turns this into the class owa_db_<type>. The legacy driver's own
            // token is 'mysql' -- 'mysqli' is only an alias accepted from
            // configuration, and naming it here produced owa_db_mysqli, which
            // does not exist, so an installation without pdo_mysql could not
            // load a driver at all.
            return $pdo_available ? 'pdo_mysql' : 'mysql';
        }

        // 'mysqli' names the legacy driver, whose class is owa_db_mysql.
        if ( $type === 'mysqli' ) {

            return 'mysql';
        }

        return $type;
    }

    public static function setupStorageEngine($type) {

        $type = self::resolveDbDriver($type);



		if ( $type ) {
        	$connection_class = "owa_db_" . $type;

            // NAMESPACE-FIRST: a bundled driver (owa_db_mysql) maps to a PSR-4
            // class (OWA\Core\Db\Mysql) that Composer autoloads -- referencing
            // it triggers the file's define() of the OWA_DTD_* column-type
            // constants. resolveNamespacedClass() returns null for a
            // third-party owa_db_<type>, which falls through to the plugins/
            // seam below. class_exists() on the resolved name forces autoload.
            $nsClass = \OWA\Core\Lib::resolveNamespacedClass( $connection_class );

            if ( $nsClass === null && ! class_exists( $connection_class ) ) {

                // Third-party driver seam: load a custom owa_db_<type> dropped
                // in at plugins/db/. Guarded above so bundled (autoloadable)
                // drivers never reach this fallback.
                $connection_class_path = OWA_PLUGIN_DIR.'db/' . $connection_class . ".php";

				if ( file_exists( $connection_class_path ) ) {
					
	                 if ( ! require_once( $connection_class_path ) ) {
	                     
	                     \OWA\Core\CoreAPI::error(sprintf('Cannot locate proper db class at %s.', $connection_class_path));
	                     
	                     return false;
	                }
	                
				} else {
					
					\OWA\Core\CoreAPI::error("$type database connection class file not found.");
				}
			}

        } else {
	        
	        \OWA\Core\CoreAPI::error("$type is not a supported database.");
        }

         return true;

    }
    /**
     * @return \owa_db
     */
    public static function dbSingleton() {

        static $db;

        if (!isset($db)) {

            $db = \OWA\Core\CoreAPI::dbFactory();
        }

        return $db;
    }

    public static function dbFactory() {

        $db_type = \OWA\Core\CoreAPI::getSetting('base', 'db_type');
        $ret = \OWA\Core\CoreAPI::setupStorageEngine($db_type);

         if (!$ret) {
             \OWA\Core\CoreAPI::error(sprintf('Failed to initialize db type %s. Exiting.', $db_type));
             return;
        } else {
            // NAMESPACE-FIRST: resolve the bundled driver (owa_db_mysql ->
            // OWA\Core\Db\Mysql) via the migration map so OWA runs bridge-free;
            // a third-party owa_db_<type> from the plugins/ seam keeps its
            // legacy name (setupStorageEngine required it in).
            $connection_class = 'owa_db_'.self::resolveDbDriver($db_type);
            $connection_class = \OWA\Core\Lib::resolveNamespacedClass($connection_class) ?? $connection_class;
            $db = new $connection_class(
                \OWA\Core\CoreAPI::getSetting('base','db_host'),
                \OWA\Core\CoreAPI::getSetting('base','db_port'),
                \OWA\Core\CoreAPI::getSetting('base','db_name'),
                \OWA\Core\CoreAPI::getSetting('base','db_user'),
                \OWA\Core\CoreAPI::getSetting('base','db_password'),
                \OWA\Core\CoreAPI::getSetting('base','db_force_new_connections'),
                \OWA\Core\CoreAPI::getSetting('base','db_make_persistant_connections')
            );

            return $db;
        }
    }

    /**
     * @return \owa_settings
     */
    public static function configSingleton() {

        static $config;

        if( ! isset( $config ) ) {


            $config = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'settings' );
        }

        return $config;
    }

    public static function errorSingleton() {

        static $e;

        if( ! $e ) {


            $e = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'error' );

        }

        return $e;
    }

    public static function getSetting($module, $name) {

        $s = \OWA\Core\CoreAPI::configSingleton();
        return $s->get($module, $name);
    }

    /**
     * The namespace to EMIT on OWA's own admin/reporting URLs and form fields.
     *
     * Empty: OWA owns its whole query string there and has nothing to collide
     * with. Distinct from the WIRE namespace ('ns'), which still prefixes
     * cookie names and the params OWA reads off a TRACKED page's URL, where the
     * query string belongs to someone else.
     *
     * @return string
     */
    public static function appNs() {

        return (string) \OWA\Core\CoreAPI::getSetting('base', 'app_ns');
    }

    public static function setSetting($module, $name, $value, $persist = false) {

        $s = \OWA\Core\CoreAPI::configSingleton();

        if ($persist === true) {
            $s->persistSetting($module, $name, $value);
        } else {
            $s->setSetting($module, $name, $value);
        }

    }

    public static function persistSetting($module, $name, $value) {

        $s = \OWA\Core\CoreAPI::configSingleton();
        $s->persistSetting($module, $name, $value);

    }

    public static function getSiteSetting($site_id, $name) {

        // No site id means there is no row to look up. load() would reach
        // getByColumn(), which throws on an empty value, so the absence of a
        // parameter would surface as an uncaught exception rather than as the
        // "no setting" answer every caller already handles.
        if ( ! $site_id ) {

            return;
        }

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load( $site->generateId( $site_id ) );

        if ( $site->wasPersisted() ) {

            return $site->getSiteSetting($name);
        }
    }

    /**
     * Is this site id one this installation actually knows about?
     *
     * Tracking accepts a site id from the request and has never checked it, so
     * an event naming a site that does not exist is recorded in full: fact rows,
     * sessions, the lot. Nothing ever reads them, because reporting is entered
     * through a site that cannot be selected, and nothing ever removes them. Two
     * real installations carry 165 and 15,173 such rows.
     *
     * The values are not hypothetical either. Observed in production data:
     * 'yoursiteidgoeshere' and 'your_site_id' (the documentation placeholder,
     * pasted into live tracking code), 'No options are available.' (a select-box
     * label submitted as a value), and one real site id truncated at six
     * different lengths.
     *
     * MEMOISED, because a queue worker processes many events per process and the
     * answer cannot change within one. A single request pays one lookup at most.
     *
     * @param string $site_id
     * @return bool
     */
    public static function isSiteRegistered( $site_id ) {

        $site_id = (string) $site_id;

        if ( $site_id === '' ) {

            return false;
        }

        if ( ! array_key_exists( $site_id, self::$registered_sites ) ) {

            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->load( $site->generateId( $site_id ) );

            self::$registered_sites[ $site_id ] = (bool) $site->wasPersisted();
        }

        return self::$registered_sites[ $site_id ];
    }

    /**
     * Test seam: forget which site ids have been checked.
     *
     * @return void
     */
    public static function forgetRegisteredSites() {

        self::$registered_sites = array();
    }

    public static function getRegisteredDomain( $full_domain ) {

        static $psl;

        if ( ! $psl ) {
            $psl = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'pslReader' );
        }

        return $psl->getRegisteredDomain( $full_domain );
    }

    public static function persistSiteSetting($site_id, $name, $value) {

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load( $site->generateId( $site_id ) );
        if ( $site->wasPersisted() ) {
            $settings = $site->get('settings');
            if ( ! $settings ) {
                $settings = array();
            }
            $settings[$name] = $value;
            $site->set('settings', $settings);
            $site->update();
        }
    }

    public static function getSiteSettings($site_id) {

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load( $site->generateId( $site_id ) );
        if ( $site->wasPersisted() ) {

            $settings = $site->get('settings');

            if ( $settings ) {
                return $settings;
            } else {
                return array();
            }
        }

    }

    public static function getAllRoles() {

        $caps = \OWA\Core\CoreAPI::getSetting('base', 'capabilities');
        return array_keys($caps);
    }

    public static function getCapabilities($role) {
        $caps = \OWA\Core\CoreAPI::getSetting('base', 'capabilities');
        if (array_key_exists($role, $caps)) {
            return $caps[$role];
        } else {
            return array();
        }
    }

    /**
     * @return \owa_serviceUser
     */
    public static function getCurrentUser() {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        return $s->getCurrentUser();
    }

    /**
     * check to see if the current user has a capability
     * always returns a bool
     * @return boolean
     */
    public static function isCurrentUserCapable($capability, $site_id = null) {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        \OWA\Core\CoreAPI::debug("Current User Role: ".$cu->getRole());
        \OWA\Core\CoreAPI::debug("Current User Authentication: ".$cu->isAuthenticated());
        $ret = $cu->isCapable($capability, $site_id);
        \OWA\Core\CoreAPI::debug("Is current User capable: ".$ret);
        return $ret;
    }

    public static function isCurrentUserAuthenticated() {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        return $cu->isAuthenticated();
    }
    
    public static function getCurrentUserApiKey() {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        return $cu->getApiKey();
    }
    
    /**
     * @return \owa_service
     */
    public static function serviceSingleton() {

        static $s;

        if(empty($s)) {


            $s = \OWA\Core\CoreAPI::supportClassFactory('base', 'service');

        }

        return $s;
    }

    public static function cacheSingleton( $params = [] ) {

        static $cache;

        if ( empty ( $cache ) ) {
	        
            $cache = \OWA\Core\Lib::simpleFactory( 'owa_cache', OWA_BASE_CLASS_DIR.'cache.php', $params );
        }

        return $cache;
    }
    
    public static function implementationFactory( $group, $implementation_name ) {
      
        // get implementation
        $s = \OWA\Core\CoreAPI::serviceSingleton();
    
        $mapValue = $s->getMapValue( $group, $implementation_name );
		
        if ( $mapValue && is_array( $mapValue ) ) {
	        
	        // new style map format
	        if ( array_key_exists('class_name', $mapValue ) ) {
		        
		        $class_name = $mapValue['class_name'];
		       		            
		        if ( array_key_exists('file', $mapValue ) ) {
			        
			        $file = $mapValue['file'];
		        }
		        
		        if ( array_key_exists('params', $mapValue ) ) {
			        
			        $params = $mapValue['params'];
		        }
		        
	        } else {
				// old style compatability
	        	list( $class_name, $file, $params ) = $mapValue;
            }

            //owa_coreAPI::debug(print_r($implementation, true));
            return \OWA\Core\Lib::simpleFactory( $class_name, $file, $params );

        } else {

            throw new \Exception("No implementation by the name $implementation_name found in group $group.");
        }
        
    }


    public static function requestContainerSingleton() {

        static $request;

        if(!isset($request)):


            $request = \OWA\Core\Lib::factory(OWA_DIR, '', 'owa_requestContainer');

        endif;

        return $request;

    }

    public static function moduleRequireOnce($module, $class_dir, $file) {

        if (!empty($class_dir)) {

            $class_dir .= '/';

        }

        // runtime module name is lowercase; on-disk dir is PascalCase (PSR-4)
        $full_file_path = OWA_BASE_DIR.'/modules/'.\OWA\Core\Lib::moduleDirName($module).'/'.$class_dir.$file.'.php';

        if (file_exists($full_file_path)) {
            return require_once($full_file_path);
        } else {
            \OWA\Core\CoreAPI::debug("moduleRequireOnce says no file found at: $full_file_path");
            return false;
        }
    }

    public static function moduleFactory($modulefile, $class_suffix = null, $params = '', $class_ns = 'owa_') {
        /*
         * Both halves are used to build a filesystem path -- moduleRequireOnce()
         * builds modules/<dir>/<file>.php and Lib::factory() builds
         * <dir>/owa_<file><suffix>.php -- and both then require_once() it. This
         * is the legacy resolution path, reached when an action is not in the
         * action registry (i.e. third-party modules). The value is request
         * supplied, so it is validated here rather than trusted.
         *
         * RequestContainer sanitizes params through Sanitize::cleanInput(), but
         * that is HTML/encoding oriented and does not constrain a path.
         * Sanitize::cleanFilename() does, but has never been wired to anything.
         *
         * Require a bare identifier for each half. A module or action name has
         * never legitimately contained a separator, and a positive match is used
         * rather than blacklist replacement.
         */
        $segments = explode( '.', (string) $modulefile );

        if ( count( $segments ) !== 2 ) {

            \OWA\Core\CoreAPI::notice( 'Refusing to resolve action: expected <module>.<action>.' );

            throw new \OWA\Core\Exception\InvalidAction( 'Invalid action.' );
        }

        list( $module, $file ) = $segments;

        foreach ( array( 'module' => $module, 'action' => $file ) as $part => $value ) {

            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $value ) ) {

                \OWA\Core\CoreAPI::notice(
                    sprintf( 'Refusing to resolve action: %s segment is not a bare identifier.', $part )
                );

                throw new \OWA\Core\Exception\InvalidAction( 'Invalid action.' );
            }
        }

        $class = $class_ns.$file.$class_suffix;
        //print $class;
        // Require class file if class does not already exist
        if(!class_exists($class)):
            \OWA\Core\CoreAPI::moduleRequireOnce($module, '', $file);
        endif;

        /*
         * A class that was never called owa_* has no legacy name to bridge, so
         * the compat map has no entry for it and Lib::factory() would fall
         * through to looking for a pre-PSR-4 file that does not exist.
         *
         * Tried AFTER the map, never before it: owa_reportController maps to
         * OWA\Core\ReportController, while this convention computes
         * OWA\Module\Base\Controller\Report -- the report dispatcher, a
         * different class entirely. Map first means nothing that resolves today
         * resolves anywhere new; this only catches what currently cannot
         * resolve at all.
         */
        if ( \OWA\Core\Lib::resolveNamespacedClass( $class ) === null ) {

            $psr4 = '\\OWA\\Module\\' . \OWA\Core\Lib::moduleDirName( $module )
                  . '\\' . $class_suffix . '\\' . ucfirst( $file );

            if ( $class_suffix && class_exists( $psr4 ) ) {

                $obj = new $psr4( $params );
                $obj->module = $module;

                return $obj;
            }
        }

        $obj = \OWA\Core\Lib::factory(OWA_BASE_DIR.'/modules/'.\OWA\Core\Lib::moduleDirName($module), '', $class, $params);

        //if (isset($obj->module)):
            $obj->module = $module;
        //endif;

        return $obj;
    }

    public static function moduleGenericFactory($module, $sub_directory, $file, $class_suffix = null, $params = '', $class_ns = 'owa_') {

        $class = $class_ns.$file.$class_suffix;

        // Require class file if class does not already exist
        if(!class_exists($class)):
            \OWA\Core\CoreAPI::moduleRequireOnce($module, $sub_directory, $file);
        endif;

        $obj = \OWA\Core\Lib::factory(OWA_DIR.'modules'.'/'.\OWA\Core\Lib::moduleDirName($module).'/'.$sub_directory, '', $class, $params);

        return $obj;
    }

    /**
     * Produces Module Classes (module.php)
     *
     * @return Object module class object
     */
    public static function moduleClassFactory($module) {

        // Callers pass either the lowercase runtime name ('maxmind_geoip', from
        // getActiveModules) or the on-disk dir name ('MaxmindGeoip', from a
        // directory scan). moduleDirName() is idempotent on its own output, so
        // it normalises both to the PascalCase PSR-4 segment, from which the
        // module class FQCN (OWA\Module\<Seg>\Module) is directly derivable.
        $seg   = \OWA\Core\Lib::moduleDirName( $module );
        $class = 'OWA\\Module\\' . $seg . '\\Module';

        if ( class_exists( $class ) ) {
            return \OWA\Core\Lib::factory( OWA_BASE_CLASSES_DIR . $seg, '', $class );
        }

        // BACKWARDS COMPAT (third-party modules): a pre-PSR-4 module ships its
        // registry class the old way -- a global-namespace `owa_<name>Module`
        // declared in modules/<dir>/module.php, not an autoloadable
        // OWA\Module\<Seg>\Module. Fall back to the legacy require + instantiate
        // so such a module still loads through a full major-version deprecation
        // window. OWA's own modules never reach here (their PSR-4 class exists).
        \OWA\Core\CoreAPI::notice(
            "Module '{$module}' loaded via the DEPRECATED pre-PSR-4 path "
            . "(modules/{$seg}/module.php declaring owa_{$module}Module). Migrate it "
            . "to a PascalCase directory with an OWA\\Module\\" . \OWA\Core\Lib::moduleDirName( $module )
            . "\\Module class; the legacy layout will be removed in a future major version."
        );

        $legacy_file  = OWA_MODULES_DIR . $seg . '/module.php';
        $legacy_class = 'owa_' . $module . 'Module';

        if ( ! class_exists( $legacy_class ) && file_exists( $legacy_file ) ) {
            require_once( $legacy_file );
        }

        return \OWA\Core\Lib::factory( OWA_MODULES_DIR . $seg, 'owa_', $module . 'Module' );
    }


    public static function updateFactory($module, $filename, $class_ns = 'owa_') {

        // NAMESPACE-FIRST. Update classes are PSR-4: UpdateNNN.php declares
        // OWA\Module\<Module>\Update\UpdateNNN, which Composer autoloads with no
        // require. The legacy name built below ('owa_base_Update011_update')
        // has not existed since the PSR-4 relocation, so resolving it first is
        // what makes updates loadable at all.
        // Callers name the update two different ways. Module::getUpdates()
        // passes the PSR-4 class basename it just read off disk ('Update017'),
        // while the updatesApply CLI's apply=/rollback= arguments are written
        // by a human as 'base.17' and arrive here as a bare sequence. Only the
        // first resolved, so targeted apply and rollback both fell through to
        // the legacy branch and died looking for owa_base_17_update.php -- a
        // filename that has not existed since the PSR-4 relocation.
        $basename = preg_match( '/^\d+$/', (string) $filename )
            ? sprintf( 'Update%03d', (int) $filename )
            : $filename;

        $namespaced = '\\OWA\\Module\\' . \OWA\Core\Lib::moduleDirName( $module )
                    . '\\Update\\' . $basename;

        if ( class_exists( $namespaced ) ) {

            $obj = new $namespaced();

        } else {

            // Legacy fallback: a pre-PSR-4 third-party module shipping
            // updates/<seq>.php with an owa_*_update class.
            $class = $class_ns.$module.'_'.$filename.'_update';

            // Require class file if class does not already exist
            if(!class_exists($class)):
                \OWA\Core\CoreAPI::moduleRequireOnce($module, 'updates', $filename);
            endif;

            $obj = \OWA\Core\Lib::factory(OWA_DIR.'modules'.'/'.\OWA\Core\Lib::moduleDirName($module).'/'.'Update', '', $class);
        }

        $obj->module_name = $module;
        if (!$obj->schema_version) {
            // Derive the sequence from the filename for updates that do not
            // declare one (Update003 and Update004 still rely on this).
            //
            // Legacy files were named '<seq>.php', so assigning $filename gave
            // the right number. PSR-4 files are 'UpdateNNN.php', so the same
            // assignment yielded the string 'Update004' -- which casts to 0 and
            // makes apply()/rollback() sequence checks compare against zero.
            // Take the digits, whichever naming form was passed.
            $obj->schema_version = (int) preg_replace( '/\D/', '', (string) $filename );
        }
        return $obj;
    }

    public static function subViewFactory($subview, $params = array()) {

        list($module, $class) = explode(".", $subview);
        //print_r($module.' ' . $class);

        $subview =  \OWA\Core\CoreAPI::moduleFactory($subview, 'View', $params);
        $subview->is_subview = true;

        return $subview;
    }

    public static function supportClassFactory($module, $class, $params = array(),$class_ns = 'owa_') {

        $obj = \OWA\Core\Lib::factory(OWA_BASE_DIR.'/'.'modules'.'/'.\OWA\Core\Lib::moduleDirName($module).'/'.'Classes'.'/', $class_ns, $class, $params);
        //$obj->module = $module;

        return $obj;
    }

    /**
     * Convienence method for generating entities
     *
     * @param mixed $entity_name
     * @return unknown
     */
    public static function entityFactory($entity_name) {

        /* SETUP STORAGE ENGINE */

        // Must be called before any entities are created

        if (!defined('OWA_DTD_INT')) {
            if (defined('OWA_DB_TYPE')) {
                \OWA\Core\CoreAPI::setupStorageEngine(OWA_DB_TYPE);
            } else {
                //owa_coreAPI::setupStorageEngine('mysql');
                self::error("OWA_DB_TYPE constant has not been set for some reason.");
            }

        }




        $entity = \OWA\Core\CoreAPI::moduleSpecificFactory($entity_name, 'entities', '', '', false);
        $entity->name = $entity_name;
        return $entity;
        //return owa_coreAPI::supportClassFactory('base', 'entityManager', $entity_name);

    }

    /**
     * Convienence method for generating entities
     *
     * @param mixed $entity_name
     * @return unknown
     * @depricated
     * @todo REMOVE
     */
    public static function rawEntityFactory($entity_name) {

        return \OWA\Core\CoreAPI::entityFactory($entity_name);

    }

    /**
     * Factory for generating module specific classes
     *
     * @param string $modulefile
     * @param string $class_dir
     * @param string $class_suffix
     * @param mixed $params
     * @return mixed
     */
    public static function moduleSpecificFactory($modulefile, $class_dir, $class_suffix = null, $params = '', $add_module_name = true, $class_ns = 'owa_') {

        list($module, $file) = explode(".", $modulefile);
        $class = $class_ns.$file.$class_suffix;

        // Require class file if class does not already exist
        if(!class_exists($class)):
            \OWA\Core\CoreAPI::moduleRequireOnce($module, $class_dir, $file);
        endif;

        $obj = \OWA\Core\Lib::factory(OWA_BASE_DIR.'/'.'modules'.'/'.$class_dir.'/'.\OWA\Core\Lib::moduleDirName($module), '', $class, $params);

        if ($add_module_name == true):
            $obj->module = $module;
        endif;

        return $obj;


    }

    public static function executeApiCommand($map) {
		
		// carve out for REST API backwards compatability during migration
		if ( array_key_exists('version', $map) ) {
			
			$route = self::lookupRestRoute( $map['request_method'], $map['module'], $map['version'], $map['do']);
			
			if ( $route ) {
				
				//$params['rest_route'] = $route;
				\OWA\Core\CoreAPI::debug('API params: ');
				\OWA\Core\CoreAPI::debug($map);
				\OWA\Core\CoreAPI::debug('API route: ');
				\OWA\Core\CoreAPI::debug($route);
				$controller = \OWA\Core\Lib::simpleFactory( $route['class_name'], $route['file'], $map );					
				$response = self::runController( $controller );
				
				$response = json_decode($response);
				
				return $response->data;
			}
		}
		
        if (!array_key_exists('do', $map)) {
            echo ("API Command missing from request.");
            \OWA\Core\CoreAPI::debug('API Command missing from request. Aborting.');
            exit;
        } else {
            // load service
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            // lookup method class
            $do = $s->getApiMethodClass($map['do']);

        }

        // if exists, pass to OWA as a request
        if ($do) {

            if (array_key_exists('args', $do)) {

                $passed_args = array();

                foreach ($do['args'] as $arg) {

                    if (isset($map[$arg])) {
                        $passed_args[] = $map[$arg];
                    } else {
                        $passed_args[] = '';
                    }
                }

                if (!empty($do['file'])) {

                    if (!class_exists($do['callback'][0])) {
                        require_once($file);
                    }
                }

                $something = call_user_func_array($do['callback'], $passed_args);
            }

            return $something;
        } else {
            echo "No API Method Found.";
        }

    }

    /**
     * Convienence method for generating metrics
     *
     * @param string $metric_name
     * @param array $params
     * @return mixed
     */
    public static function metricFactory($metric_name, $params = array()) {

        if (!strpos($metric_name, '.')) {
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            $metric_name = $s->getMetricClasses($metric_name);
        }


        return \OWA\Core\CoreAPI::moduleSpecificFactory($metric_name, 'metrics', '', $params, false);
    }

    /**
     * Returns a consolidated list of admin/options panels from all active modules
     *
     * @return array
     */
    public static function getAdminPanels() {

        $panels = array();

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ($service->modules as $k => $v) {
            $v->registerAdminPanels();
            $module_panels = $v->getAdminPanels();
            if ($module_panels) {
                foreach ($module_panels as $key => $value) {

                    $panels[$value['group']][] = $value;
                }
            }
        }

        return $panels;
    }

    /**
     * Returns a consolidated list of nav links from all active modules for a particular view
     * and named navigation element.
     *
     * @param string $view
     * @param string $nav_name the name of the navigation element that you want links for
     * @param string $sortby the array value to sort the navigation array by
     * @return array|false
     */
    /**
     * The report registry, built on first use.
     *
     * Lazy on purpose, exactly as getNavigation() is: Module::__construct()
     * runs on every request including every tracker beacon, so registration
     * must not live there. The guard makes it idempotent -- a request that
     * renders two reports registers once.
     *
     * @return array<string, array> definitions keyed by report id
     */
    public static function getReportRegistry() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ( $service->modules as $module ) {

            if ( empty( $module->reports_registered ) ) {

                $module->registerReports();
                $module->reports_registered = true;
            }
        }

        $map = $service->getMap( 'reports' );

        return is_array( $map ) ? $map : array();
    }

    /**
     * One report's definition, or false.
     *
     * @param  string $id
     * @return array|false
     */
    public static function getReportDefinition( $id ) {

        if ( ! $id ) {
            return false;
        }

        $registry = \OWA\Core\CoreAPI::getReportRegistry();

        return $registry[ $id ] ?? false;
    }

    public static function getNavigation($view, $nav_name, $sortby ='order') {

        $links = array();

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ($service->modules as $k => $v) {

            // If the module does not have nav links, register them. needed in case this function is called twice on
            // same view.
            if (empty($v->nav_links)):
                $v->registerNavigation();
            endif;

            $module_nav = $v->getNavigationLinks();


            if (!empty($module_nav)) {
                // assemble the navigation for a specific view's named navigation element'
                foreach ($module_nav as $key => $value) {

                    $links[$value['view']][$value['nav_name']][] = $value;
                }
            }

        }

        //print_r($links[$view][$nav_name]);
        if (!empty($links[$view][$nav_name])):
               // sort the array
               usort($links[$view][$nav_name], function($a, $b) use ($sortby) {
                return strnatcmp($a[$sortby], $b[$sortby]);
            });

            return $links[$view][$nav_name];
        else:
            return false;
        endif;

    }

    public static function getGroupNavigation($group_name, $sortby ='order') {

        $links = array();

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ($service->modules as $k => $v) {

            // If the module does not have nav links, register them. needed in case this function is called twice on
            // same view.
            if ( empty( $v->nav_links ) ) {

                $v->registerNavigation();
            }

            $module_nav = $v->getNavigationLinks();

            if ( $module_nav ) {

                //loop through returned nav array
                foreach ( $module_nav as $group => $nav_links ) {

                    foreach ( $nav_links as $subgroup => $link ) {

                        // check to see if group exists
                        if ( array_key_exists( $group, $links ) ) {

                            // check to see if subgroup is already present in the main array
                            if ( array_key_exists( $subgroup, $links[ $group ] ) ) {
                                // merge various elements?? not now.

                                //check to see if there is an existing set of subgroup links
                                if ( array_key_exists( 'subgroup', $links[ $group ][ $subgroup ] ) ) {
                                    // if so, merge the subgroups
                                    $links[ $group ][ $subgroup ][ 'subgroup' ] = array_merge( $links[ $group ][ $subgroup ][ 'subgroup' ], $link[ 'subgroup' ] );
                                } else {

                                }
                            } else {
                                // else populate the link
                                $links[$group][$subgroup] = $link;
                            }

                        } else {
                            $links[$group][$subgroup] = $link;
                        }
                    }

                }
            }
        }

        if ( isset( $links[$group_name] ) ) {

            return $links[$group_name];
        }
    }

    /**
     * @Todo REMOVE
     */
    public static function getNavSort($a, $b) {

        return strnatcmp($a['order'], $b['order']);
    }


    public static function getActiveModules() {

        $c = \OWA\Core\CoreAPI::configSingleton();

        $config = $c->config->get('settings');


        $active_modules = array();

        foreach ($config as $k => $module) {

            if ( isset($module['is_active']) && $module['is_active'] == true) {
                $active_modules[] = $k;
            }
        }

        return $active_modules;

    }
    
    public static function getPresentModules() {
	    $path = OWA_DIR.'modules';
	    // Check directory exists or not
		if( file_exists($path) && is_dir($path)) {
        	// Scan the files in this directory
			$result = scandir($path);
        
			// Filter out the current (.) and parent (..) directories
			$files = array_diff($result, array('.', '..', 'index.php'));
			\OWA\Core\CoreAPI::debug('Modules present are: ');
			\OWA\Core\CoreAPI::debug( $files );
			
			return $files;
		}
    }

    public static function getModulesNeedingUpdates() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        return $service->getModulesNeedingUpdates();
    }

    /**
     * Invokes controller to perform controller
     *
     * @param $action string
     *
     */
    public static function performAction( $action, $params = array() ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
			
		// Load action controller from service map which uses the 'module.action' convention	
		$action_map = $service->getMapValue('actions', $action );
			
		// create the controller object
        if ( $action_map ) {
	    
            $controller = \OWA\Core\Lib::simpleFactory( $action_map['class_name'], $action_map['file'], $params );
        
        } else {
        
            // attempt to use old style convention
            //
            // This is the fallback for third-party modules that predate the
            // action registry, and it is the only resolution path a request can
            // reach with a name nothing answers to. Both ways it can fail --
            // a name that is not a bare <module>.<action>, and one that is but
            // names no controller -- raise, and until this was caught they left
            // the request as an uncaught exception: a 500 and a PHP fatal for
            // what is a missing page.
            try {

                $controller = \OWA\Core\CoreAPI::moduleFactory($action, 'Controller', $params);

            } catch ( \OWA\Core\Exception\InvalidAction $e ) {

                return self::actionNotResolved( $action, 400, $e );

            } catch ( \Exception $e ) {

                return self::actionNotResolved( $action, 404, $e );
            }
        }
		
		return \OWA\Core\CoreAPI::runController( $controller );
    }
    
    /**
     * Answer a request whose action resolves to nothing.
     *
     * A name nothing answers to is a client error, not a server fault, so it
     * gets a 4xx and the ordinary error page rather than a 500 and a fatal in
     * the log. Which 4xx depends on how it failed: a malformed name was never a
     * route anywhere (400), a well-formed one simply is not present here (404).
     *
     * The action is recorded server-side, where an administrator can see what
     * was asked for, and deliberately kept out of the response: it is
     * request-supplied, and reflecting it would put attacker-chosen text on the
     * page.
     *
     * @param string     $action
     * @param int        $code
     * @param \Exception $exception
     * @return string|null  the rendered page, or null under the CLI
     */
    public static function actionNotResolved( $action, $code, $exception = null ) {

        self::notice( sprintf(
            'No controller for action "%s" (%d): %s',
            $action,
            $code,
            $exception ? $exception->getMessage() : 'not registered'
        ) );

        // The CLI has already reported through notice(); rendering an HTML
        // error page into a terminal would help nobody.
        if ( defined( 'OWA_CLI' ) ) {

            return null;
        }

        if ( ! headers_sent() ) {

            http_response_code( $code );
        }

        return self::displayView(
            array( 'error_msg' => 'The page you requested could not be found.' ),
            'base.error'
        );
    }

    public static function runController( $controller ) {
	    
	    if ( ! $controller || ! method_exists( $controller, 'doAction' ) ) {

            \OWA\Core\CoreAPI::debug("Class is not a controller. no doAction method found.");
            return;
        }

        // call the doAction method which is part of the abstract controller class
        // inherited by all other controller classes
        $data = $controller->doAction();

        // Display view if controller calls for one.
        if ( ! empty( $data['view'] ) || ! empty( $data['action'] ) ) {

            // Redirect to a view
            if ( $data['view_method'] == 'redirect' ) {

                return \OWA\Core\Lib::redirectToView( $data );

            // return an image . Will output headers and binary data.
            } elseif ( $data['view_method'] == 'image' ) {

                return \OWA\Core\CoreAPI::displayImage( $data );

            } else {

                return \OWA\Core\CoreAPI::displayView( $data );
            }

        } elseif( ! empty( $data['do'] ) ) {

            return \OWA\Core\Lib::redirectToView( $data );
        }
    }

    /**
     * Logs a tracking event to the event queue
     *
     * take an owa_event object as a message.
     *
     * @param string $event_type
     * @param object|string $message
     * @return boolean
     */
    public static function logEvent( $event_type, $message = '') {

        \OWA\Core\CoreAPI::debug("Logging new event $event_type");
		
        // Check to ensure that the event is in fact a tracking event
        if ( ! in_array( $event_type, \OWA\Core\CoreAPI::getSetting('base', 'tracking_event_types' ) ) ) {
            
            \OWA\Core\CoreAPI::debug("Not logging. Event with $event_type is not a tracking event.");
            return false;
        }
        
        // Check to see if named users should be logged
        if (\OWA\Core\CoreAPI::getSetting('base', 'log_named_users') != true) {
            $cu = \OWA\Core\CoreAPI::getCurrentUser();
            $cu_user_id = $cu->getUserData('user_id');

            if( ! empty( $cu_user_id ) ) {
				\OWA\Core\CoreAPI::debug("Not logging named user.");
                return false;
            }
        }
        
		// backwards compatibility with old style messages
		// @todo is this needed anymore?
        $class = \OWA\Module\Base\Classes\Event::class;

        if ( ! ( $message instanceof $class ) ) {
	        
            $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
            $event->setProperties( $message );
            $event->setEventType( $event_type );
            
        } else {
	        
            $event = $message;
        }
        
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        
        // Tracking Event processing STAGE 1
        // sets any necessary environmental properties from SERVER global
        $teh = \OWA\Core\CoreAPI::getInstance( 'owa_trackingEventHelpers', OWA_BASE_CLASS_DIR.'trackingEventHelpers.php');
        $environmentals = $service->getMap( 'tracking_properties_environmental' );
        $teh->setTrackerProperties( $event, $environmentals );
		
        // do not log if the do not log property is set on the event.
        if ($event->get('do_not_log')) {
            return false;
        }
        
        // do not log if the request is robotic
        \OWA\Core\CoreAPI::debug("Testing to see if event was generated by a robot");
        \OWA\Core\CoreAPI::debug("User Agent: ". $event->get('HTTP_USER_AGENT') );

        $bcap = $service->getBrowscap( $event->get('HTTP_USER_AGENT') );
       
        if ( ! \OWA\Core\CoreAPI::getSetting('base', 'log_robots') ) {

            if ( $bcap->robotCheck() ) {
	            
                \OWA\Core\CoreAPI::debug("ABORTING: request appears to be from a robot");
                \OWA\Core\CoreAPI::setRequestParam('is_robot', true);

                return false;
            }
        }

        // check to see if IP should be excluded
        if ( \OWA\Core\CoreAPI::isIpAddressExcluded( $event->get('ip_address') ) ) {
	        
            \OWA\Core\CoreAPI::debug("Not logging event. IP address found in exclusion list.");
            
            return false;
        }

        // Refuse an event for a site this installation does not have.
        //
        // The site id arrives in the request and, until now, was written to
        // every fact row without ever being checked. Such rows are unreachable
        // by design -- reporting is entered through a site, and the site does
        // not exist -- so they are recorded, never read, and never removed.
        //
        // That also made tracking an unauthenticated write: anyone could post
        // events naming any site id and add rows indefinitely, invisible in
        // every report while consuming partitions and open-file budget.
        //
        // Placed after the robot and IP gates so a rejected request costs no
        // lookup, and before queueing so bad events cannot fill the queue.
        // Nothing on this path creates sites -- they come only from the admin
        // UI, cmd=add-site and install -- so nothing legitimate is being
        // refused.
        $site_id = $event->getSiteId();

        if ( ! \OWA\Core\CoreAPI::isSiteRegistered( $site_id ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Not logging event: site id "%s" is not registered on this installation. '
              . 'Check the site id in your tracking code against cmd=add-site or the Sites admin page.',
                (string) $site_id
            ) );

            return false;
        }
        
        // queue for later or process event straight away
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'queue_events' ) ||
             \OWA\Core\CoreAPI::getSetting( 'base', 'queue_incoming_tracking_events' ) ) {

            $q = \OWA\Core\CoreAPI::getEventQueue( 'incoming_tracking_events' );
            \OWA\Core\CoreAPI::debug('Queuing '.$event->getEventType().' event with properties: '.print_r($event->getProperties(), true ) );
            $q->sendMessage( $event );

        } else {

            // lookup which event processor to use to process this event type
            $processor_action = \OWA\Core\CoreAPI::getEventProcessor( $event->getEventType() );
           
			\OWA\Core\CoreAPI::debug('About to perform action: '.$processor_action);
			\OWA\Core\CoreAPI::debug($event);
			
			return \OWA\Core\CoreAPI::performAction( $processor_action, array( 'event' => $event ) );
        }
    }

    public static function getInstance( $class, $path ) {

        if ( ! class_exists( $class ) ) {

            require_once( $path );
        }

        return $class::getInstance();
    }

    public static function displayImage($data) {

        header('Content-type: image/gif');
        header('P3P: CP="'.\OWA\Core\CoreAPI::getSetting('base', 'p3p_policy').'"');
        header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo \OWA\Core\CoreAPI::displayView($data);
    }


    /**
     * Displays a View without user authentication. Takes array of data as input
     *
     * @param array $data
     * @param string $viewfile a specific view file to use
     * @return string
     *
     */
    public static function displayView($data, $viewfile = '') {

        if (empty($viewfile)):
            $viewfile = $data['view'];
        endif;

        $view = \OWA\Core\CoreAPI::moduleFactory($viewfile, 'View');
      
        $view->setData($data);
        return $view->assembleView($data);

    }

    public static function displaySubView($data, $viewfile = '') {

        if (empty($viewfile)):
            $viewfile = $data['view'];
        endif;

        $view =  \OWA\Core\CoreAPI::subViewFactory($viewfile);

        return $view->assembleView($data);

    }

    /**
     * Strip a URL of certain GET params
     * @depricated
     * @return string
     * @todo REMOVE
     */
    function stripDocumentUrl($url) {

        if (\OWA\Core\CoreAPI::getSetting('base', 'clean_query_string')):

            if (\OWA\Core\CoreAPI::getSetting('base', 'query_string_filters')):
                $filters = str_replace(' ', '', (string) \OWA\Core\CoreAPI::getSetting('base', 'query_string_filters'));
                $filters = explode(',', $filters);
            else:
                $filters = array();
            endif;

            // OWA specific params to filter
            array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'source_param'));
            array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').\OWA\Core\CoreAPI::getSetting('base', 'feed_subscription_param'));

            //print_r($filters);

            foreach ($filters as $filter => $value) {

              $url = preg_replace(
                '#\?' .
                $value .
                '=.*$|&' .
                $value .
                '=.*$|' .
                $value .
                '=.*&#msiU',
                '',
                $url
              );

            }

        endif;
         //print $url;

         return $url;

    }

    public static function getRequestParam($name) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        return $service->request->getParam($name);

    }

    public static function getRequest() {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request;
    }

    public static function setRequestParam($name, $value) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->setParam($name, $value);

    }

    public static function makeTimePeriod($time_period, $params = array()) {

        $period = \OWA\Core\CoreAPI::supportClassFactory('base', 'timePeriod');

        if ( ! array_key_exists('period', $params)) {
            $params['period'] = $time_period;
        }
        $period->setFromMap( $params );
        return $period;
    }

    /**
     * Factory method for producing validation objects
     *
     * @return Object
     */
    public static function validationFactory($class_file, $conf = array()) {


        return \OWA\Core\Lib::factory(OWA_PLUGIN_DIR.'validations', 'owa_', $class_file, $conf, 'Validation');

    }

    public static function debug($msg) {

        $e = \OWA\Core\CoreAPI::errorSingleton();
        $e->debug($msg);
        return;
    }

    public static function error($msg) {

        $e = \OWA\Core\CoreAPI::errorSingleton();
        $e->err($msg);
        return;
    }

    public static function notice($msg) {

        $e = \OWA\Core\CoreAPI::errorSingleton();
        $e->notice($msg);
    }

    public static function createCookie($cookie_name, $cookie_value, $expires = 0, $path = '/; samesite=Lax', $domain = '', $secure = false) {

        if ( $domain ) {
            // sanitizes the domain
            $domain = \OWA\Core\Lib::sanitizeCookieDomain( $domain );
            
        } else {
	        
            $domain = \OWA\Core\CoreAPI::getSetting('base', 'cookie_domain');
        }
        if (is_array($cookie_value)) {

            $cookie_value = \OWA\Core\Lib::implode_assoc('=>', '|||', $cookie_value);
        }

        // add namespace
        $cookie_name = sprintf('%s%s', \OWA\Core\CoreAPI::getSetting('base', 'ns'), $cookie_name);

        // debug
        \OWA\Core\CoreAPI::debug(sprintf('Setting cookie %s with values: %s under domain: %s', $cookie_name, $cookie_value, $domain));

        // makes cookie to session cookie only
        if (!\OWA\Core\CoreAPI::getSetting('base', 'cookie_persistence')) {
	        
            $expires = 0;
        }
		
		$secure = \OWA\Core\Lib::isHttps();
	
        // PHP 7.3 has a different function signature.
        // @todo refactor usage to clean up once php 7.3 is min requirment.
        if (PHP_VERSION_ID < 70300) {
	        
	        setcookie($cookie_name, $cookie_value, $expires, $path, $domain, $secure);
	        
	    } else {
		    	
			$options = [
		        
		        'expires' 	=> $expires,
                'path' 		=> '/',
                'samesite' 	=> 'Lax',
                'domain' 	=> $domain,
                'secure' 	=> $secure
	        ];
	        
	        setcookie($cookie_name, $cookie_value, $options);
	    }
    }

    public static function deleteCookie($cookie_name, $path = '/', $domain = '') {

        return \OWA\Core\CoreAPI::createCookie($cookie_name, false, time()-3600*25, $path, $domain);
    }

    public static function registerStateStore($name, $expiration, $length = '', $format = '', $type = 'cookie', $cdh_required = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->state->registerStore( $name, $expiration, $length, $format, $type, $cdh_required );
    }

    public static function setState($store, $name, $value, $store_type = '', $is_perminent = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->state->set($store, $name, $value, $store_type, $is_perminent);
    }

    public static function getState($store, $name = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->state->get($store, $name);
    }

    // depricated
    public static function getStateParam($store, $name = '') {

        return \OWA\Core\CoreAPI::getState($store, $name);
    }

    public static function getServerParam($name = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->getServerParam($name);
    }

    public static function clearState($store, $name = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $service->request->state->clear($store, $name);

    }

    public static function getEventProcessor($event_type) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $processor = $service->getMapValue('event_processors', $event_type);

        if ( $processor ) {

            return $processor;
        
        } else {
            
            \OWA\Core\CoreAPI::debug("no event processor found for $event_type");
        }
    }

    /**
     * Handles OWA internal page/action requests
     *
     * @return mixed
     */
    public static function handleRequest($caller_params = null, $action = '') {

        static $init;

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        // Override request parsms with those passed by caller
        if (!empty($caller_params)) {
            $service->request->mergeParams($caller_params);
        };

        $params = $service->request->getAllOwaParams();

        if ($init != true) {
            \OWA\Core\CoreAPI::debug('Handling request with params: '. print_r($params, true));
        }

        /*
         * The old-style 'view' request scheme was removed here.
         *
         * It let a request name a view to render directly. Nothing in the
         * codebase ever constructed such a URL -- this branch was its only
         * consumer, with no writer in Core/, modules/, templates or JS -- so it
         * was dead weight carried since the early releases.
         *
         * displayView() itself is unchanged and stays: it is still used
         * internally to render an already-resolved view, and by View\Mail for
         * alternate bodies.
         */

        if (empty($action)) {
            $action = \OWA\Core\CoreAPI::getRequestParam('action');
            if (empty($action)) {
                $action = \OWA\Core\CoreAPI::getRequestParam('do');

                if (empty($action)) {
                    \OWA\Core\CoreAPI::debug('no action specified on request params');
                    return; 
                }
            }
        }
		
        $init = true;
        \OWA\Core\CoreAPI::debug('About to perform action: '.$action);
        return \OWA\Core\CoreAPI::performAction($action, $params);

    }    
    
    /**
     * Handles REST endpoint requests
     *
     * @return mixed
     */
    public static function handleRestRequest() {
        
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        
        $params = $service->request->getAllOwaParams();
        
        \OWA\Core\CoreAPI::debug('Handling REST request with params: '. print_r($params, true));
        
        $action = \OWA\Core\CoreAPI::getRequestParam('do');

        // REST API Requests
        // Lookup controller for REST API route.
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'request_mode' ) === 'rest_api' ) {

            // get request method
            $request_method = $service->request->getRequestType();

            // check to see if this is a CORS pre-flight Request
            if ($request_method == 'OPTIONS') {

                $controller = \OWA\Core\Lib::simpleFactory( 'owa_corsPreflightController', 'controllers/corsPreflightController.php', [] );
                return \OWA\Core\CoreAPI::runController( $controller );
            }

            // check for rewriten rest params and set module, version, and do params from that.
            //
            // This has to happen BEFORE the request is required to name an action:
            // the documented route form is /api/<module>/<version>/<route>, which
            // .htaccess rewrites to owa_rest_params alone, carrying no 'do' of its own.
            $rest_params = self::getRequestParam('rest_params');

            if ( $rest_params ) {

                $rest_params = explode('/', $rest_params);
                self::debug( 'exploding raw REST params:');
                self::debug( $rest_params );

                if ( count( $rest_params ) >= 3 ) {

                    $params['module'] = $rest_params[0];
                    $params['version'] = $rest_params[1];
                    $params['do'] = $rest_params[2];
                    $action = $params['do'];
                }
            }

            // A REST request must name its route. There is deliberately no default
            // action here -- the admin endpoint falls back to the start_page setting
            // when 'do' is absent, but a client that omits the route has made a
            // malformed request, and choosing one for it would hide the mistake.
            if ( ! $action ) {

                \OWA\Core\CoreAPI::debug('no action specified on REST request params');
                return self::restError( 400 );
            }

            
            \OWA\Core\CoreAPI::debug('Generating REST API route controller...');
            
            if ( \OWA\Core\Lib::keyExistsNotEmpty( 'module', $params ) && \OWA\Core\Lib::keyExistsNotEmpty( 'version', $params ) ) {
            
                $route = self::lookupRestRoute( $request_method, $params['module'], $params['version'], $action );
                
                if ( $route ) {
                    
                    // set the remainer of the rewritten rest params
                    
                    if ( $rest_params ) {
                        
                        // slice off the first three params which have already been set
                        $rest_params = array_slice($rest_params, 3);
                        
                        foreach ( $rest_params as $k => $v) {
                            
                            $params[ $route['conf'][ 'params_order' ][$k] ] = $rest_params[ $k ];
                        }
                    }
                    
                    $params['rest_route'] = $route;
                    $controller = \OWA\Core\Lib::simpleFactory( $route['class_name'], $route['file'], $params );					
                    return \OWA\Core\CoreAPI::runController( $controller );
                
                } else {

                    // Answered exactly as an unauthenticated request is, and for the
                    // same reason: this runs before the controller authenticates, so
                    // a distinct "no such route" reply would let an anonymous caller
                    // enumerate the API by watching which names answer differently.
                    \OWA\Core\CoreAPI::debug('No REST API route found');
                    return self::restError( 401 );
                }

            } else {

                \OWA\Core\CoreAPI::debug('Could not generate controller because no version param was on request.');
                return self::restError( 400 );
            }
            
        } else {
            
            \OWA\Core\CoreAPI::debug('This is not a REST API request.');
        }
    }
    
    /**
     * Renders a REST error through the standard response envelope.
     *
     * Returned instead of the empty 200 these paths used to produce, which was
     * indistinguishable from a successful call that carried no data.
     *
     * These run BEFORE the controller authenticates, so the reply is readable by
     * anyone and says nothing a caller could not already supply: no route names,
     * no hint of whether a route exists, no suggestions. 401 reuses the wording
     * of a genuine auth failure so the two cannot be told apart.
     *
     * @param  int    $code  400 for a malformed request, 401 otherwise.
     * @return string        The rendered response body.
     */
    private static function restError( $code ) {

        $messages = array(
            400 => array(
                'headline'  => 'Bad request.',
                'msg'       => 'The request did not name a route to call.'
            ),
            401 => array(
                'headline'  => 'Not authenticated.',
                'msg'       => 'Check API credentials or permissions for this user.'
            ),
        );

        $error_msg = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages[ 400 ];

        http_response_code( $code );

        return self::displayView( array( 'error_msg' => $error_msg ), 'base.restApi' );
    }

    public static function lookupRestRoute( $request_method, $module, $version, $do ) {
	    
	    if ( ! empty( $request_method )
	    	&& ! empty( $version )
	    	&& ! empty( $do )
	    	&& ! empty( $module )
	    ){
		    
		    $service = \OWA\Core\CoreAPI::serviceSingleton();
		    $route = $service->getRestApiRoute($module, $version, $do, $request_method );
			\OWA\Core\CoreAPI::debug($route);
		    return $route;
	    }
    }

    public static function isUpdateRequired() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->isUpdateRequired();
    }

    /**
     * @return array
     */
    public static function getSitesList() {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_site');
        $db->selectColumn('*');
        $sites = $db->getAllRows();

        if ( ! $sites ) {
            $sites = array();
        }
        return $sites;
    }
	
	
    public static function profile($that = '', $function = '', $line = '', $msg = '') {

        return;
    }

    public static function profileDisplay() {
        
		return;
    }

    public static function getEventDispatch() {


        return \OWA\Module\Base\Classes\EventDispatch::get_instance();

    }

    public static function getEventQueue( $name ) {

        static $queues;

        // make queue if needed
        if ( ! isset( $queues[ $name ] ) ) {

            // get queue config
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            $map = $s->getMapValue('event_queues', $name);

            if ( $map ) {

                $implementation = $s->getMapValue( 'event_queue_types', $map['queue_type'] );

                if ( $implementation
                     && isset( $implementation[0] )
                     && isset( $implementation[1] )
                ) {
                    \OWA\Core\CoreAPI::debug(print_r($implementation, true));
                    $queues[ $name ] = \OWA\Core\Lib::simpleFactory( $implementation[0], $implementation[1], $map );

                } else {

                    throw new \Exception("No event queue by that type found.");
                }

            } else {

                throw new \Exception("No configuration found for event queue $name.");
            }
        }
            // return queue
        return $queues[ $name ];
    }

    public static function getCliCommandClass($command) {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        return $s->getCliCommandClass($command);
    }

    public static function getGeolocationFromIpAddress($ip_address) {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->geolocation->getGeolocationFromIp($ip_address);
        return $s->geolocation;
    }

    public static function getNonceTimeInterval() {

        return  ceil( time() / \OWA\Core\CoreAPI::getSetting( 'base', 'nonce_expiration_period') );
    }

    public static function createNonce($action) {

        $time = \OWA\Core\CoreAPI::getNonceTimeInterval();
        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $user_id = $cu->getUserData( 'user_id' );

        $full_nonce = $time . $action . $user_id . 'owa_nonce';

        $nonce = substr( \OWA\Core\CoreAPI::saltedHash($full_nonce, 'nonce'), -12, 10);

        return $nonce;
    }
    
    public static function createRestApiNonce( $version, $module, $do ) {
        
        return self::createNonce( $version . $module . $do );
    }

    public static function saltedHash( $data, $scheme, $hash_type = 'md5' ) {

        $salt = \OWA\Core\CoreAPI::getSalt( $scheme );
        return \OWA\Core\Lib::hash( $hash_type, $data, $salt );
    }



    public static function getSalt( $scheme ) {

        static $cached_salts;

        $scheme = strtoupper($scheme);

        if ( ! $cached_salts ) {

            $cached_salts = array();
            $ns = strtoupper( (string) \OWA\Core\CoreAPI::getSetting('base', 'ns') );

            foreach (array('NONCE', 'SECRET', 'AUTH') as $f ) {

                foreach (array('KEY', 'SALT') as $s ) {

                    $const = sprintf("%s%s_%s", $ns, $f, $s);

                    if ( ! defined ( "$const" ) ) {
                        continue;
                    } else {

                        $cached_salts[ $f.'_'.$s ] = constant("$const");
                    }
                }
            }
        }


        $key = '';
        $salt = '';

        if (array_key_exists( $scheme.'_KEY', $cached_salts ) ) {

            $key = $cached_salts[ $scheme.'_KEY' ];
        }

        if (array_key_exists( $scheme.'_SALT', $cached_salts ) ) {

            $salt = $cached_salts[ $scheme.'_SALT' ];
        }

        return $key . $salt;
    }

    public static function secureRandomString( $length, $special_chars = true, $more_special_chars = true ) {

        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ( $special_chars )
            $chars .= '!@#$%^&*()';
        if ( $more_special_chars )
            $chars .= '-_ []{}<>~`+=,.;:/?|';

        $password = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= substr($chars, \OWA\Core\CoreAPI::random(0, strlen($chars) - 1), 1);
        }

        return $password;
    }

    public static function random($min, $max) {

        static $rnd_value;

        if ( strlen($rnd_value) < 8 ) {

            $notrandom = false;

            if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {

                $rnd_value = bin2hex(openssl_random_pseudo_bytes(32, $cstrong));

                if ( ! $cstrong ) {

                    $notrandom = true;
                }

            } else {

                $notrandom = true;
            }

            if ( $notrandom ) {

                $seed = microtime();
                $rnd_value = md5( uniqid(microtime() . mt_rand(), true ) . $seed );
                $rnd_value .= sha1($rnd_value);
                $rnd_value .= sha1($rnd_value . $seed);

            }

            //$seed = md5($seed . $rnd_value);
        }
        // Take the first 8 digits for our value
        $value = substr($rnd_value, 0, 8);

        // Strip the first eight, leaving the remainder for the next call to random.
        $rnd_value = substr($rnd_value, 8);

        $value = abs(hexdec($value));

        // Some misconfigured 32bit environments (Entropy PHP, for example) truncate integers larger than PHP_INT_MAX to PHP_INT_MAX rather than overflowing them to floats.
        $max_random_number = 3000000000 === 2147483647 ? (float) "4294967295" : 4294967295; // 4294967295 = 0xffffffff

        // Reduce the value to be within the min - max range
        if ( $max != 0 )
            $value = $min + ( $max - $min + 1 ) * $value / ( $max_random_number + 1 );

        return abs(intval($value));
    }

    public static function summarize($map) {

        $entity = \OWA\Core\CoreAPI::entityFactory($map['entity']);
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom($entity->getTableName(), $entity->getTableAlias());

        foreach ($map['columns'] as $col => $action) {

            switch ($action) {

                case 'sum':
                    $col_def = sprintf("SUM(%s)", $col);
                    $name = $col.'_sum';
                    break;
                case 'count':
                    $col_def = sprintf("COUNT(%s)", $col);
                    $name = $col.'_count';
                    break;
                case 'count_distinct':
                    $col_def = sprintf("COUNT(distinct %s)", $col);
                    $name = $col.'_dcount';
                    break;
                case 'max':
                    $col_def = sprintf("MAX(%s)", $col);
                    $name = $col.'_max';
                    break;
            }

            $db->selectColumn($col_def, $name);
        }

        foreach ($map['constraints'] as $con_col => $con_value) {

            if ( is_array( $con_value ) ) {
                $db->where($con_col, $con_value['value'], $con_value['operator']);
            } else {
                $db->where($con_col, $con_value);
            }
        }

        $ret = $db->getOneRow();
        return $ret;
    }

    public static function getJsTrackerTag( $site_id, $options = array() ) {


        $t = new \OWA\Core\Template();

        $t->set( 'site_id', $site_id );
        $cmds = \OWA\Core\CoreAPI::filter( 'tracker_tag_cmds', array() );
        $t->set( 'cmds', $cmds );
        $t->set('options', $options);
        $t->set_template('js_log_tag.php');
        return $t->fetch();
    }

    public static function activateModule( $module_name ) {

        if ( $module_name ) {

            $m = \OWA\Core\CoreAPI::moduleClassFactory($module_name);
            return $m->activate();
        }
    }

    public static function deactivateModule( $module_name ) {

        if ( $module_name ) {

            // Load a fresh module instance, symmetric with activateModule() and
            // installModule(). deactivate() only flips the persisted is_active
            // setting on $this->name, so a boot-loaded instance is not needed --
            // and getModule() returned false (then fataled on false->deactivate())
            // for any module that was not already in the active/boot-loaded set.
            $m = \OWA\Core\CoreAPI::moduleClassFactory($module_name);
            return $m->deactivate();
        }
    }

    public static function installModule( $module_name ) {

        if ($module_name) {

            $m = \OWA\Core\CoreAPI::moduleClassFactory($module_name);
            $status = $m->install();
            return $status;
        }
    }

    public static function generateInstanceSpecificHash() {

        if ( defined( 'OWA_SECRET' ) ) {
            $salt = OWA_SECRET;
        } else {
            $salt = '';
        }

        if ( defined( 'OWA_DB_USER' ) ) {
            $salt .= OWA_DB_USER;
        }

        if ( defined( 'OWA_DB_PASSWORD' ) ) {
            $salt .= OWA_DB_PASSWORD;
        }

        return md5( $salt );
    }

    public static function getAllDimensions() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $dims = $s->dimensions;

        foreach ( $s->denormalizedDimensions as $k => $entity_dims ) {
            foreach ($entity_dims as $entity => $dedim) {
                $dims[$k] = $dedim;
            }
        }

        return $dims;
    }

    public static function getAllMetrics() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        return $s->metrics;
    }

    public static function getGoalManager( $siteId ) {

        static $gm;

        if ( ! $gm ) {

            $gm = array();
        }

        if ( ! isset( $gm[$siteId] ) )  {
            $gm[ $siteId ] = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);
        }

        return $gm[$siteId];
    }

    public static function getRequestTimestamp() {

        $r = \OWA\Core\CoreAPI::requestContainerSingleton();
        return $r->getTimestamp();
    }

    public static function isEveryoneCapable( $capability ) {

        $caps = \OWA\Core\CoreAPI::getCapabilities('everyone');

        if ( in_array( $capability, $caps ) ) {
            return true;
        } else {
            return false;
        }
    }

    public static function getCurrentUrl() {
	    
        $r = \OWA\Core\CoreAPI::requestContainerSingleton();
        return $r->getCurrentUrl();
    }

    public static function isIpAddressExcluded( $ip_address ) {

        // do not log if ip address is on the do not log list
        $ips = \OWA\Core\CoreAPI::getSetting( 'base', 'excluded_ips' );
        
        if ( $ips ) {
	        
	        \OWA\Core\CoreAPI::debug('Excluded ip address list: '.$ips);

            $ips = trim( $ips );

            if ( strpos( $ips, ',' ) ) {
                $ips = explode( ',', $ips );
            } else {
                $ips = array( $ips );
            }

            foreach( $ips as $ip ) {
                $ip = trim( $ip );
                if ( $ip_address === $ip ) {
                    \OWA\Core\CoreAPI::debug("Request is from excluded ip address: $ip.");
                    return true;
                }
            }
        }
    }
    
    static function loadConf( $file_name, $filter_name = '' ) {
	    
	    $conf_file = OWA_CONF_DIR . $file_name;
	    
	    if ( file_exists( $conf_file ) ) {
	    
	    	$conf = include( $conf_file);
	    }
	    
	    $sup_file = OWA_DATA_DIR .  $file_name;
	    
	    if ( file_exists( $sup_file ) ) {
		    
		    $sup_conf = include( $sup_file );
		    
		    if ( is_array( $sup_conf) ) {
		    
		    	$conf = array_merge( $conf, $sup_conf );
		    }
	    }
	    
	    // see generic filter name for filtering the final conf array
	    if ( ! $filter_name ) {
		    
		    $filter_name = 'conf.' . $file_name;
	    }
	    
	    return \OWA\Core\CoreAPI::filter( $filter_name, $conf );
    }

    /**
     * Attaches an event handler to the event queue
     *
     * @param string $filter_name
     * @param mixed $callback
     * @param int $priority
     * @return void
     */
    public static function registerFilter( $filter_name, $callback, $priority = 10 ) {

        $ed = \OWA\Core\CoreAPI::getEventDispatch();
        $ed->attachFilter($filter_name, $callback, $priority);
    }

    public static function filter( $filter_name, $value ) {

        $ed = \OWA\Core\CoreAPI::getEventDispatch();
        return $ed->filter( $filter_name, $value );
    }
    
    public static function loadEntitiesFromArray( $items, $entity_name ) {
	    
	    $set = [];
	    
	    if ( $items ) {
		    
		    foreach ($items as $item ) {
			    
			    $entity = \OWA\Core\CoreAPI::entityFactory( $entity_name );
			    $entity->setProperties( $item );
			    $set[] = $entity;
			    
		    }
	    }
	    
	    return $set;
    }
    
    public static function signRequestUrl( $url, $apiKey ) {
	    
	    $auth = \OWA\Core\Auth::get_instance();
	    
	    $signature = $auth->generateSignature( $url, $apiKey );
	    
	    $url .= '&owa_signature=' . $signature;
	    
	    return $url;
    }

}

?>
