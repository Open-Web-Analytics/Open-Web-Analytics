<?php
namespace OWA\Module\MemcachedCache;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//


/**
 * Remote Queue Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2021 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa

 */

class Module extends \OWA\Core\Module {

    function __construct() {

        $this->name = 'memcachedCache';
        $this->display_name = 'Memcached Based Object Cache';
        $this->group = 'performance';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Implements a Memcached based object cache to improve performance.';
        $this->config_required = false;
        $this->required_schema_version = 1;
        
        return parent::__construct();
    }
    
    function init() {
	    
/*
	    $this->registerImplementation('object_cache_types', 'memcached', 'owa_memcachedCache', 'classes/memcachedCache.php');
	    
	    if ( \OWA\Core\CoreAPI::getSetting( 'memcachedCache', 'memcachedServers' ) ) {
		    
			\OWA\Core\CoreAPI::setSetting('base', 'cache_objects', true);
			\OWA\Core\CoreAPI::setSetting('base', 'cacheType', 'memcached');
			   
	    } else {
		    
		    \OWA\Core\CoreAPI::notice('No memcached servers found in configuration settings.');
	    }
*/
    }
}

?>