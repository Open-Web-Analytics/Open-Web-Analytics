<?php
namespace OWA\Module\Base\Classes;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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
 * Service Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class Service extends \OWA\Core\Base {

    var $init = false;
    var $request;
    var $state;
    var $current_user;
    var $settings;
    var $maps = array();
    var $update_required = false;
    var $install_required = false;
    var $modules_needing_updates = array();
    var $modules = array();
    var $entities = array();
    var $metrics = array();
    var $dimensions = array();
    var $denormalizedDimensions = array();
    var $browscap;
    var $geolocation;
    var $formatters = array();
    var $restApiRoutes = array();

    function __construct() {
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);

    }

    function __destruct() {
        \OWA\Core\CoreAPI::profile($this, __FUNCTION__, __LINE__);
    }

    function initializeFramework() {

        if (!$this->isInit()) {

            // setup request container
            $this->request = \OWA\Core\CoreAPI::requestContainerSingleton();

            $this->_loadModules();
            $this->_loadFilters();
            $this->_loadEntities();
            $this->_loadMetrics();
            $this->_loadDimensions();
            $this->_loadFormatters();
            $this->_loadApiMethods();
            $this->_loadEventProcessors();
            $this->setInit();

            // setup current user
            $this->current_user = \OWA\Core\CoreAPI::supportClassFactory('base', 'serviceUser');
            // the 'log_users' config directive relies on this being populated
            $this->current_user->setUserData( 'user_id' ,  $this->request->state->get('u') );
            // load geolocation obj.
            $this->geolocation = \OWA\Module\Base\Classes\Geolocation::getInstance();
        }

    }

    function setBrowscap($b) {

        $this->browscap = $b;
    }

    function getBrowscap( $ua = '') {

        if (empty($this->browscap)) {
	        
	        if ( ! $ua ) {
		        
		        $ua = $this->request->getServerParam('HTTP_USER_AGENT');
	        }
	        
            $this->browscap = \OWA\Core\CoreAPI::supportClassFactory('base', 'browscap', $ua);
        }

        return $this->browscap;
    }

    function _loadModules() {

        $present_modules = \OWA\Core\CoreAPI::getPresentModules();
        $am = \OWA\Core\CoreAPI::getActiveModules();

        foreach ($am as $k => $v) {
			
			// active-module names are lowercase runtime names; getPresentModules()
			// returns on-disk dir names, which are PascalCase (PSR-4). Translate the
			// runtime name to its dir name for the presence check.
			if ( in_array( \OWA\Core\Lib::moduleDirName( $v ), $present_modules ) ) {
	            $m = \OWA\Core\CoreAPI::moduleClassFactory($v);
	
	            $this->addModule($m);
	
	            // check for schema updates
	            $check = $m->isSchemaCurrent();
	
	            if ($check != true) {
	                $this->markModuleAsNeedingUpdate($m->name);
	            }
			}
        }

        // set schema update flag
        if (!empty($this->modules_needing_updates)) {
            $this->setUpdateRequired();
        }
    }
    
    function checkForRequiredUpdates() {
	    \OWA\Core\CoreAPI::debug( \OWA\Core\CoreAPI::configSingleton() );
	    $am = \OWA\Core\CoreAPI::getActiveModules();
	    
	    foreach ($am as $k => $v) {
		    
            // check for schema updates
            $check = $this->modules[ $v ]->isSchemaCurrent();

            if ($check != true) {
                $this->markModuleAsNeedingUpdate($this->modules[ $v ]->name);
            }
        }
        
        // set schema update flag
        if (!empty($this->modules_needing_updates)) {
            $this->setUpdateRequired();
        }
    }


    function _loadEntities() {

        foreach ($this->modules as $k => $module) {

            foreach ($module->entities as $entity_k => $entity_v) {
                // TODO: remove this to make API stateless
                //$this->entities[] = $module->name.$entity_v;
                // proper call
                $this->addEntity($entity_v, $module->name.'.'.$entity_v);
            }
        }

        return;
    }

    function _loadFilters() {

        foreach ($this->modules as $k => $module) {

            $module->registerFilters();
        }
    }

    function _loadMetrics() {

        foreach ($this->modules as $k => $module) {

            if (is_array($module->metrics)) {

                $this->metrics = array_merge_recursive( $this->metrics, $module->metrics);
            }
        }

        $metricsByEntityMap = array();

        foreach ( $this->metrics as $metric => $implementations ) {

            foreach ( $implementations as $implementation ) {

                $m = \OWA\Core\CoreAPI::metricFactory( $implementation['class'], $implementation['params']);

                if ( ! $m->isCalculated() ) {
                    $metricsByEntityMap[ $m->getEntityName() ][ $implementation['name'] ] = $implementation;
                }
            }
        }

        $this->setMap('metricsByEntity', $metricsByEntityMap);
    }

    function getAllMetrics() {

        return $this->metrics;
    }

    function loadCliCommands() {

        $command_map = array();

        foreach ($this->modules as $k => $module) {

            if (is_array($module->cli_commands)) {
                $command_map = array_merge($command_map, $module->cli_commands);
            }
        }

        $this->setMap('cli_commands', $command_map);
    }

    /**
     * Commands that must never be scheduled, however they are named.
     *
     * install and update re-run schema installation and mutate the schema out
     * from under the running process; reset-secrets rewrites owa-config.php.
     * Checked against the COMMAND rather than the job name, so a friendly label
     * cannot smuggle one past.
     */
    const NEVER_SCHEDULE = array( 'install', 'update', 'reset-secrets' );

    /**
     * Build the job registry: what modules registered, overlaid with what
     * OWA_SCHEDULED_JOBS says.
     *
     * Idempotent, and called only by the scheduler commands and tests -- there
     * is no reason for every CLI invocation to build this map.
     *
     * A malformed entry disables THAT job and nothing else. A typo in one line
     * of config must never stop the other jobs running, so every rejection here
     * is per-entry and noticed by name.
     *
     * @return void
     */
    function loadJobs() {

        // The merge validates commands, so the command map must be built first.
        // Both are idempotent.
        $this->loadCliCommands();

        $jobs = array();

        foreach ($this->modules as $k => $module) {

            if (is_array($module->scheduled_jobs)) {
                $jobs = array_merge($jobs, $module->scheduled_jobs);
            }
        }

        $jobs = $this->applyConfiguredJobs( $jobs );

        $this->setMap('scheduled_jobs', $jobs);
    }

    /**
     * Overlay OWA_SCHEDULED_JOBS onto the registered jobs.
     *
     * Keyed by job name rather than a list of arrays each carrying a 'name', so
     * a name cannot be missing and PHP itself rejects two entries claiming the
     * same job -- a whole class of merge rule deleted rather than documented.
     *
     * Overrides are PER KEY: giving only 'params' keeps the registered schedule,
     * giving only 'schedule' keeps the registered params. Forcing an operator to
     * restate values they did not mean to change is how config drifts away from
     * code.
     *
     * @param array $jobs  registered jobs, keyed by name
     * @return array
     */
    protected function applyConfiguredJobs( $jobs ) {

        $configured = \OWA\Core\CoreAPI::getSetting( 'base', 'scheduled_jobs' );

        if ( ! is_array( $configured ) ) {

            return $jobs;
        }

        foreach ( $configured as $name => $spec ) {

            $name = trim( (string) $name );

            if ( $name === '' || ! is_array( $spec ) ) {

                \OWA\Core\CoreAPI::notice(
                    'OWA_SCHEDULED_JOBS: entries must be keyed by job name with an array value. Skipping one.'
                );

                continue;
            }

            $known = isset( $jobs[ $name ] );

            // A new job has nothing to inherit, so it must say what it runs and
            // when. Guessing the command from the key is exactly the ambiguity
            // separating name from command removes.
            if ( ! $known ) {

                foreach ( array( 'command', 'schedule' ) as $required ) {

                    if ( empty( $spec[ $required ] ) ) {

                        \OWA\Core\CoreAPI::notice( sprintf(
                            'OWA_SCHEDULED_JOBS: "%s" is not a registered job, so it needs a "%s". Skipping it.',
                            $name, $required
                        ) );

                        continue 2;
                    }
                }
            }

            $job = $known ? $jobs[ $name ] : array(
                'name'   => $name,
                'module' => 'config',
                'params' => array(),
            );

            $job['source'] = $known ? 'config-override' : 'config';

            if ( isset( $spec['command'] ) )  { $job['command']  = (string) $spec['command']; }
            if ( isset( $spec['schedule'] ) ) { $job['schedule'] = (string) $spec['schedule']; }
            if ( isset( $spec['params'] ) )   { $job['params']   = (array) $spec['params']; }

            // A command nothing answers to would otherwise be listed as a
            // healthy job with a next-due time, and only fail silently at
            // dispatch. Refusing it here keeps the registry honest.
            if ( ! $this->getCliCommandClass( $job['command'] ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'OWA_SCHEDULED_JOBS: "%s" names command "%s", which is not registered. Skipping it.',
                    $name, $job['command']
                ) );

                continue;
            }

            if ( in_array( $job['command'], self::NEVER_SCHEDULE, true ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'OWA_SCHEDULED_JOBS: "%s" runs %s, which must never be scheduled. Skipping it.',
                    $name, $job['command']
                ) );

                continue;
            }

            $jobs[ $name ] = $job;
        }

        return $jobs;
    }

    /**
     * @param string $name
     * @return array|false
     */
    function getJob( $name ) {

        return $this->getMapValue('scheduled_jobs', $name);
    }

    /**
     * @return array  every job, keyed by name
     */
    function getJobs() {

        $jobs = $this->getMap('scheduled_jobs');

        return is_array( $jobs ) ? $jobs : array();
    }

    function _loadApiMethods() {

        $method_map = array();

        foreach ($this->modules as $k => $module) {

            if (is_array($module->api_methods)) {
                $method_map = array_merge($method_map, $module->api_methods);
            }
        }

        $this->setMap('api_methods', $method_map);
    }

    function _loadDimensions() {

        foreach ($this->modules as $k => $module) {

            if (is_array($module->dimensions)) {
                /*
                 * Recursive, because dimensions are now keyed name => entity =>
                 * registration. A flat array_merge would replace a whole name's
                 * entity map with whichever module was loaded last, reproducing
                 * inside the merge exactly the loss that registerDimension() no
                 * longer commits.
                 */
                $this->dimensions = array_merge_recursive($this->dimensions, $module->dimensions);
            }

            if (is_array($module->denormalizedDimensions)) {

                $this->denormalizedDimensions = array_merge_recursive($this->denormalizedDimensions, $module->denormalizedDimensions);
            }

            //print_r($this->denormalizedDimensions);
        }
    }

    function _loadFormatters() {

        foreach ($this->modules as $k => $module) {

            if (is_array($module->formatters)) {
                $this->formatters = array_merge($this->formatters, $module->formatters);
            }
        }
    }

    function _loadEventProcessors() {

        $processors = array();

        foreach ($this->modules as $k => $module) {

            $processors = array_merge($processors, $module->event_processors);
        }

        $this->setMap('event_processors', $processors);

    }

    /**
     * @return \owa_serviceUser
     */
    function getCurrentUser() {
        if (!$this->isInit()) {
            throw new \Exception('Current User Object could only be get if framework is initialized');
        }
        return $this->current_user;
    }

    function getRequest() {

        return $this->request;
    }
    
    function getRestApiRoute( $module, $version, $route_name, $request_method ) {
	    
	    if ( array_key_exists( $module, $this->restApiRoutes ) ) {

	    	if ( array_key_exists( $version, $this->restApiRoutes[$module] ) ) {
		    
			    if ( array_key_exists( $route_name, $this->restApiRoutes[$module][ $version ] ) ) {
			    
			    	if ( array_key_exists( $request_method, $this->restApiRoutes[$module][ $version ][ $route_name ] ) ) {
		    
		    			return $this->restApiRoutes[$module][ $version ][ $route_name ][ $request_method ] ;
		    		}
		    	}	
			}
		}
    }
    
    function setRestApiRoute( $module, $version, $route_name, $request_method, $value ) {
	    
	    $this->restApiRoutes[$module][$version][ $route_name ][ $request_method ] = $value;
    }
    
    function getAllRestApiRoutes() {
	    
	    return $this->restApiRoutes;
    }

    function getState() {

        return $this->request->state;
    }

    function getMapValue($map_name, $name) {

        if (array_key_exists($map_name, $this->maps)) {

            if ( $name && array_key_exists($name, $this->maps[$map_name])) {

                return $this->maps[$map_name][$name];
            } else {

                return false;
            }
        } else {

            return false;
        }
    }

    function getMap($name) {

        if (array_key_exists($name, $this->maps)) {

            return $this->maps[$name];
        }

    }

    function setMap($name, $map) {

        $this->maps[$name] = $map;
    }

    function setMapValue($map_name, $name, $value) {

        $this->maps[$map_name][$name] = $value;
    }

    function setUpdateRequired() {

        $this->update_required = true;
        return;
    }

    function isUpdateRequired() {

        return $this->update_required;
    }

    function addModule($module) {

        $this->modules[$module->name] = $module;
    }

    function markModuleAsNeedingUpdate($name) {

        $this->modules_needing_updates[] = $name;
    }

    function getModulesNeedingUpdates() {

        return $this->modules_needing_updates;
    }


    function setInstallRequired() {
        $this->install_required = true;
    }

    function isInstallRequired() {

        return $this->install_required;
    }

    function addEntity($entity_name, $class) {

        $this->entities[$entity_name] = $class;
    }

    function setInit() {
        $this->init = true;
    }

    function isInit() {

        return $this->init;
    }

    function getModule($name) {

        if (array_key_exists($name, $this->modules)) {
            return $this->modules[$name];
        } else {
            return false;
        }

    }

    function getAllModules() {
        return $this->modules;
    }

    function getMetricClasses($name) {

        if (array_key_exists($name, $this->metrics)) {

            return $this->metrics[$name];
        }
    }

    /**
     * A normalized dimension, optionally the one defined for a given entity.
     *
     * Called without an entity this answers the LAST registration for the name,
     * which is what the previously flat registry held after overwriting itself.
     * That equivalence is deliberate: this change is about retaining the other
     * registrations, not about changing which one an existing caller resolves.
     *
     * Called with an entity it answers that entity's registration, or nothing.
     * This is the path a second schema generation will use -- one name, several
     * entities, the caller saying which it means rather than hoping.
     */
    function getDimension($name, $entity = null) {

        if (! array_key_exists($name, $this->dimensions)) {
            return null;
        }

        $byEntity = $this->dimensions[$name];

        if ($entity !== null) {

            return array_key_exists($entity, $byEntity) ? $byEntity[$entity] : null;
        }

        return end($byEntity) ?: null;
    }

    /**
     * Every entity a normalized dimension is defined for.
     *
     * Nothing consumes this yet. It exists so that scoping can be written and
     * tested against a real second registration before there is a second schema
     * generation to depend on it.
     */
    function getDimensionEntities($name) {

        if (! array_key_exists($name, $this->dimensions)) {
            return array();
        }

        return array_keys($this->dimensions[$name]);
    }

    function getDenormalizedDimension($name, $entity) {

        //print_r($this->denormalizedDimensions);
        if (array_key_exists($name, $this->denormalizedDimensions)) {
            if (array_key_exists($entity, $this->denormalizedDimensions[$name])) {
                return $this->denormalizedDimensions[$name][$entity];
            }
        }
    }

    function getFormatter($name) {

        if (array_key_exists($name, $this->formatters)) {
            return $this->formatters[$name];
        }
    }

    function getCliCommandClass($command) {

        return $this->getMapValue('cli_commands', $command);
    }

    function setCliCommandClass($command, $class) {

        $this->setMapValue('cli_commands', $command, $class);
    }

    function getApiMethodClass($method_name) {

        return $this->getMapValue('api_methods', $method_name);
    }

    function setApiMethodClass($method_name, $class) {

        $this->setMapValue('api_methods', $method_name, $class);
    }
}


?>