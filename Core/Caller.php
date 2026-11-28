<?php
namespace OWA\Core;

// __DIR__ is required: a bare relative path is resolved against include_path,
// which begins with '.', i.e. the CURRENT WORKING DIRECTORY -- not this file's
// directory -- so another installation's owa_env.php could load.
include_once( __DIR__ . '/../owa_env.php' );

/**
 * Abstract Caller class used to build application specific invocation classes
 */
class Caller extends \OWA\Core\Base {
    
    /**
     * Request Params from get or post
     *
     * @var array<mixed>
     */
    public $params;

    /** @var float */
    public $start_time;

    /** @var float|null set as the caller is destroyed */
    public $end_time;

    /** @var mixed nothing sets it */
    public $update_required;

    /** @var \OWA\Module\Base\Classes\Service */
    public $service;
            
    /**
     * Constructor
     *
     * @param array<mixed>|null $config module => overrides, or base's overrides alone
     * @return void
     */
    public function __construct( $config = [] ) {
      
        /* PHP ERROR LOGGING */
        if ( \OWA\Core\Lib::inDebug() ) {
            
            \OWA\Module\Base\Classes\Error::phpErrorSettings();
        }
        
        // Start time
        $this->start_time = microtime(true);
        
        /* SETUP CONFIGURATION AND ERROR LOGGER */
       
        // Parent Constructor. Sets default config entity and error logger
        parent::__construct();
        
        // Log version debug
        \OWA\Core\CoreAPI::debug(sprintf('*** Starting Open Web Analytics v%s. Running under PHP v%s (%s) ***', \OWA\Core\Env::version(), PHP_VERSION, PHP_OS));
        
        if ( array_key_exists('REQUEST_URI', $_SERVER ) ) {
            \OWA\Core\CoreAPI::debug( 'Request URL:' . ( $_SERVER['REQUEST_METHOD'] ?? '' ) .' '. \OWA\Core\Lib::redactUrlForLog( $_SERVER['REQUEST_URI'] ) );
        }
        
        if ( array_key_exists('HTTP_USER_AGENT', $_SERVER ) ) {
            \OWA\Core\CoreAPI::debug( 'User Agent: '.$_SERVER['HTTP_USER_AGENT'] );
        }
        
        if ( array_key_exists('HTTP_HOST', $_SERVER ) ) {
            \OWA\Core\CoreAPI::debug( 'Host: '.$_SERVER['HTTP_HOST'] );
        }
             
        /*
         * THE REQUEST AND THE SERVER'S ROLE, before anything is read from the
         * database: a request the role does not serve, or a configuration it
         * cannot run on, is refused here (ServerRole::bootProblem()), and a
         * role without a database reads no stored settings.
         */
        $request_type = self::requestTypeFrom( $config );
        $this->c->set( 'base', 'request_type', $request_type );

        $problem = \OWA\Core\ServerRole::bootProblem( $request_type );

        if ( $problem ) {

            $this->e->setHandler();
            \OWA\Core\ServerRole::refuse( $request_type, $problem );
        }

        // load config values from DB
        // Applies config from db or cache
        // check here is needed for installs when the configuration table does not exist.
               
        if ( $this->c->isConfigFilePresent() && \OWA\Core\ServerRole::readsStoredSettings() && ! defined( 'OWA_INSTALLING' ) )  {
            
            // The boot load itself: read from the config object it is loading into.
            $this->c->load( $this->c->get( 'base', 'configuration_id' ) );
        }
        
        // set timezone once config is loaded from DB.
        $this->c->setTimezone();

        /* APPLY CALLER CONFIGURATION OVERRIDES */
        
        // The calling application's values, usually an integration's own. They
        // win over everything already in place: the defaults, the stored
        // settings and the config-file constants (Settings::applyModuleOverrides()
        // merges them over all three). So this comes after the stored settings
        // are loaded.
        $this->overloadConfig( $config );

        /* SET ERROR HANDLER */

        // Sets the correct mode of the error logger now that final config values are in place
        // This will flush buffered msgs that were thrown up untill this point
        $this->e->setHandler();
            
        /* LOAD SERVICE LAYER */
        $this->service = \OWA\Core\CoreAPI::serviceSingleton();
        // initialize framework
        $this->service->initializeFramework();
        // notify handlers of 'init' action
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $dispatch->notify($dispatch->makeEvent('init'));
        
        // re-fetch the array now that overrides have been applied.
        // needed for backwards compatability
        $this->config = $this->c->fetch('base');
        
        /* SETUP REQUEST Params */
        // still needed?????
        $this->params = $this->service->request->getAllOwaParams();
    }
    
    /**
     * The request type a caller names in its configuration (request_type, at
     * the top or under 'base'); one that names none keeps the current one.
     *
     * @param array<mixed>|null $config
     * @return string
     */
    private static function requestTypeFrom( $config ) {

        $config = (array) $config;
        $type   = $config['base']['request_type'] ?? $config['request_type'] ?? null;

        return $type !== null && $type !== '' ? (string) $type : \OWA\Core\RequestType::current();
    }

    /**
     * Handles OWA internal page/action requests
     *
     * @param  array<mixed>|null $caller_params
     * @param  string            $action
     * @return mixed
     */
    public function handleRequest($caller_params = null, $action = '') {
        
        return \OWA\Core\CoreAPI::handleRequest($caller_params, $action);
                        
    }
    
    /**
     * Handles OWA REST requests
     *
     * @return mixed
     */
    public function handleRestRequest() {
        
        return \OWA\Core\CoreAPI::handleRestRequest();
    }
    
    /**
     * @return void
     */
    public function __destruct() {
        
        $this->end_time = microtime(true);
        $total_time = $this->end_time - $this->start_time;
        \OWA\Core\CoreAPI::debug(sprintf('Total session time: %s',$total_time));
        \OWA\Core\CoreAPI::debug("Goodbye from OWA");
    }
        
    /**
     * @param  string $module
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function setSetting($module, $name, $value) {
        
        \OWA\Core\CoreAPI::setSetting($module, $name, $value);
    }
    
    /**
     * @param  string $module
     * @param  string $name
     * @return mixed
     */
    public function getSetting($module, $name) {
        
        return \OWA\Core\CoreAPI::getSetting($module, $name);
    }
        
    /**
     * Make the current user an authenticated user of $role.
     *
     * @param  string $role
     * @param  string $login_name the current user's user_id; '' leaves it as it is
     * @return void
     */
    public function setCurrentUser($role, $login_name = '') {
        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $cu->setRole($role);

        if ( (string) $login_name !== '' ) {

            $cu->setUserData( 'user_id', (string) $login_name );
        }

        $cu->setAuthStatus(true);
    }
    
    /**
     * @return bool
     */
    public function isOwaInstalled() {
        
        $version = \OWA\Core\CoreAPI::getSetting('base', 'schema_version');
        
        if ($version > 0) {
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * @param  string $file_name
     * @return true|void nothing when the endpoint is disabled
     */
    public function isEndpointEnabled($file_name) {
        
        if ( ! $this->getSetting('base', 'disableAllEndpoints') ) {
            $disabled_endpoints = self::endpointList( $this->getSetting('base', 'disabledEndpoints') );
            if ( ! in_array( $file_name, $disabled_endpoints, true ) ) {
                return true;
            } else {
                \OWA\Core\CoreAPI::debug('This endpoint is disabled.');
            }
        }
    }
    
    /**
     * disabledEndpoints (OWA_DISABLED_ENDPOINTS) as a list: an array as it
     * is, a string as one entry or a comma-separated list, anything else as
     * none (endpointListProblem()).
     *
     * @param  mixed $value
     * @return array<mixed>
     */
    public static function endpointList( $value ) {

        if ( is_array( $value ) ) {

            return $value;
        }

        if ( is_string( $value ) ) {

            return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn ( $name ) => $name !== '' ) );
        }

        return [];
    }

    /**
     * Why a disabledEndpoints value is ignored, or null: one that is neither
     * an array nor a string (nor unset).
     *
     * @param  mixed $value
     * @return string|null
     */
    public static function endpointListProblem( $value ) {

        if ( $value === null || is_array( $value ) || is_string( $value ) ) {

            return null;
        }

        return sprintf( \OWA\Core\CoreAPI::t( 'OWA_DISABLED_ENDPOINTS is of type %s, not a list or a comma-separated string of entry points, so it is ignored and no entry point is turned off.' ),
            get_debug_type( $value ) );
    }

    /**
     * Used by owa_caller to override default config with values based to the caller.
     *
     * @param  array<mixed>|null $config module => overrides, or base's overrides alone
     * @return void
     */
    private function overloadConfig( $config ) {
        
        if ( ! empty( $config ) ) {
         
            if ( array_key_exists( 'base', $config ) ) {
             
                 foreach ($config as $module => $values ) {
                     
                     $this->c->applyModuleOverrides( $module, $values );
                 }
                 
            } else {
                 
                 $this->c->applyModuleOverrides('base', $config);
            }
            
            \OWA\Core\CoreAPI::debug('Configuration overrides applied.');
        }
    }

}
