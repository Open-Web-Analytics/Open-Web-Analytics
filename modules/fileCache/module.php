<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Remote Queue Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2021 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa

 */

class owa_fileCacheModule extends owa_module {

    function __construct() {

        $this->name = 'fileCache';
        $this->display_name = 'File Based Object Cache';
        $this->group = 'performance';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Implements a file based object cache to improve performance.';
        $this->config_required = false;
        $this->required_schema_version = 1;
        //owa_coreAPI::setSetting('base', 'cache_objects', true);
        return parent::__construct();
    }
    
    function init() {
	    
	    $this->registerImplementation('object_cache_types', 'file', 'owa_fileCache', 'classes/fileCache.php');
	    owa_coreAPI::setSetting('base', 'cache_objects', true);
	    owa_coreAPI::setSetting('base', 'cacheType', 'file');
    }
}

?>