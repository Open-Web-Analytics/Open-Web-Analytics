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

include_once('owa_env.php');
require_once(OWA_BASE_DIR.'/owa_base.php');
require_once(OWA_BASE_DIR.'/owa_requestContainer.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');
require_once(OWA_BASE_DIR.'/owa_coreAPI.php');

/**
 * Abstract Caller class used to build application specific invocation classes
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.0.0
 */
class owa_caller extends owa_base {
    
    /**
     * Request Params from get or post
     *
     * @var array
     */
    var $params;
        
    var $start_time;
    
    var $end_time;
    
    var $update_required;
    
    var $service;
    
    var $site_id;
            
    /**
     * Constructor
     *
     * @param array $config
     * @return owa_caller
     */
    function __construct($config = array()) {
        
        if (empty($config)) {
            $config = array();
        }
        
        // Start time
        $this->start_time = owa_lib::microtime_float();
        
        /* SETUP CONFIGURATION AND ERROR LOGGER */
       
        // Parent Constructor. Sets default config entity and error logger
        parent::__construct();
        
        // Log version debug
        $this->e->debug(sprintf('*** Starting Open Web Analytics v%s. Running under PHP v%s (%s) ***', OWA_VERSION, PHP_VERSION, PHP_OS));
        if ( array_key_exists('REQUEST_URI', $_SERVER ) ) {
            owa_coreAPI::debug( 'Request URL:' . $_SERVER['REQUEST_METHOD'] .' '.$_SERVER['REQUEST_URI'] );
        }
        
        if ( array_key_exists('HTTP_USER_AGENT', $_SERVER ) ) {
            owa_coreAPI::debug( 'User Agent: '.$_SERVER['HTTP_USER_AGENT'] );
        }
        
        if ( array_key_exists('HTTP_HOST', $_SERVER ) ) {
            owa_coreAPI::debug( 'Host: '.$_SERVER['HTTP_HOST'] );
        }
             
        // load config values from DB
        // Applies config from db or cache
        // check here is needed for installs when the configuration table does not exist.
               
        if ($this->c->isConfigFilePresent())  {
            $this->c->load( $this->c->get( 'base', 'configuration_id' ) );
        }   
        
        // set timezone once config is loaded from DB.
        $this->c->setTimezone();
             

        /* APPLY CALLER CONFIGURATION OVERRIDES */
        
        // overrides all default and user config values except defined in the config file
        // must come after user overides are applied 
        // This will apply configuration overirdes that are specified by the calling application.
        // This is usually used by plugins to setup integration specific configuration values.
        
        $this->c->applyModuleOverrides('base', $config);
        
        $this->e->debug('Caller configuration overrides applied.');
        
        /* SET ERROR HANDLER */

        // Sets the correct mode of the error logger now that final config values are in place
        // This will flush buffered msgs that were thrown up untill this point
        $this->e->setHandler($this->c->get('base', 'error_handler'));
        
        /* PHP ERROR LOGGING */
        
        /*
        if (defined('OWA_LOG_PHP_ERRORS')) {
            
            $this->e->logPhpErrors();
        }
        
        set_exception_handler( array($this->e, 'logException') );
        */
            
        /* LOAD SERVICE LAYER */
        $this->service = owa_coreAPI::serviceSingleton();
        // initialize framework
        $this->service->initializeFramework();    
        // notify handlers of 'init' action
        $dispatch = owa_coreAPI::getEventDispatch();
        $dispatch->notify($dispatch->makeEvent('init'));
        
        /* SET SITE ID */
        // needed in standalone installs where site_id is not set in config file.
        // still needed??????
        if (!empty($this->params['site_id'])) {
            $this->c->set('base', 'site_id', $this->params['site_id']);
        }
        
        // re-fetch the array now that overrides have been applied.
        // needed for backwards compatability 
        $this->config = $this->c->fetch('base');
        
        /* SETUP REQUEST Params */
        // still needed?????
        $this->params = $this->service->request->getAllOwaParams();
    }
    
    function handleRequestFromUrl()  {
        
        //$this->params = owa_lib::getRequestParams();
        return $this->handleRequest();
        
    }
    
    
    /**
     * Returns a configured javascript tracker for inclusion in your web page.
     * You can pass an options array to control what the tracker will log.
     * The options array is a key/value pair format like:
     *
     * $options = array('do_not_log_pageview' => true);
     *
     * Option keys include: 'do_not_log_pageview', 'do_not_log_clicks', 'do_not_log_domstream'
     *
     * @param     $echo        bool     if true the function will echo. if false the tracker is returned asa string.
     * @param    $options    array    an key value pair option array 
     * @return     $tag         string    the tracker javascript.
     */
    function placeHelperPageTags($echo = true, $options = array()) {
        
        if( ! owa_coreAPI::getRequestParam( 'is_robot' ) ) {
        
            if ( ! class_exists( 'owa_template' ) ) {
            
                require_once(OWA_BASE_CLASSES_DIR.'owa_template.php');
            }
        
            $t = new owa_template();
            $t->set_template('js_helper_tags.tpl');
                
            $tracking_code = owa_coreAPI::getJsTrackerTag( $this->getSiteId(), $options );
            $t->set('tracking_code', $tracking_code);
            $tag = $t->fetch();
            
            if ($echo == false) {
                return $tag;
            } else {
                echo $tag;
            }
        }
    }
    
    // needed?
    function handleHelperPageTagsRequest() {
    
        $params = array();
        $params['do'] = 'base.helperPageTags';
        return $this->handleRequest($params);
    
    }
    
    /**
     * Handles OWA internal page/action requests
     *
     * @return unknown
     */
    function handleRequest($caller_params = null, $action = '') {
        
        return owa_coreAPI::handleRequest($caller_params, $action);
                        
    }
    
    function __destruct() {
        
        $this->end_time = owa_lib::microtime_float();
        $total_time = $this->end_time - $this->start_time;
        $this->e->debug(sprintf('Total session time: %s',$total_time));
        $this->e->debug("Goodbye from OWA");
        owa_coreAPI::profileDisplay();
    }
        
    function setSetting($module, $name, $value) {
        
        return owa_coreAPI::setSetting($module, $name, $value);
    }
    
    function getSetting($module, $name) {
        
        return owa_coreAPI::getSetting($module, $name);
    }
        
    function setCurrentUser($role, $login_name = '') {
        $cu = owa_coreAPI::getCurrentUser();
        $cu->setRole($role);
        $cu->setAuthStatus(true);
    }
    
    function makeEvent($type = '') {
    
        $event = owa_coreAPI::supportClassFactory('base', 'event');
        
        if ($type) {
            $event->setEventType($type);
        }
        
        return $event;
    }
    
    function setSiteId($site_id) {
        
        $this->site_id = $site_id;
    }
    
    function getSiteId() {
        
        return $this->site_id;
    }
    
    function setErrorHandler($mode) {
        $this->e->setHandler($mode);
    }
    
    function isOwaInstalled() {
        
        $version = owa_coreAPI::getSetting('base', 'schema_version');
        
        if ($version > 0) {
            return true;
        } else {
            return false;
        }
    }
    
    function isEndpointEnabled($file_name) {
        
        if ( ! $this->getSetting('base', 'disableAllEndpoints') ) {
            $disabled_endpoints = $this->getSetting('base', 'disabledEndpoints');
            
            if ( ! in_array( $file_name, $disabled_endpoints ) ) {
                return true;
            }
        }
    }
    
    function restInPeace() {
    
        return false;
    }
    
}

?>