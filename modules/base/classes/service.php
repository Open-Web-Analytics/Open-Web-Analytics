<?php 

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

require_once(OWA_BASE_CLASS_DIR.'geolocation.php');

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


class owa_service extends owa_base {

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
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);

    }

    function __destruct() {
        owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
    }

    function initializeFramework() {

        if (!$this->isInit()) {

            // setup request container
            $this->request = owa_coreAPI::requestContainerSingleton();

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
            $this->current_user = owa_coreAPI::supportClassFactory('base', 'serviceUser');
            // the 'log_users' config directive relies on this being populated
            $this->current_user->setUserData( 'user_id' ,  $this->request->state->get('u') );
            // load geolocation obj.
            $this->geolocation = owa_geolocation::getInstance();
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
	        
            $this->browscap = owa_coreAPI::supportClassFactory('base', 'browscap', $ua);
        }

        return $this->browscap;
    }

    function _loadModules() {

        $present_modules = owa_coreAPI::getPresentModules();
        $am = owa_coreAPI::getActiveModules();

        foreach ($am as $k => $v) {
			
			if ( in_array( $v, $present_modules ) ) {
	            $m = owa_coreAPI::moduleClassFactory($v);
	
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
	    owa_coreAPI::debug( owa_coreAPI::configSingleton() );
	    $am = owa_coreAPI::getActiveModules();
	    
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

                $m = owa_coreAPI::metricFactory( $implementation['class'], $implementation['params']);

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
                $this->dimensions = array_merge($this->dimensions, $module->dimensions);
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
     * @return owa_serviceUser
     */
    function getCurrentUser() {
        if (!$this->isInit()) {
            throw new Exception('Current User Object could only be get if framework is initialized');
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

    function getDimension($name) {

        if (array_key_exists($name, $this->dimensions)) {
            return $this->dimensions[$name];
        }
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