<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * 011 Schema Update Class
 * 
 */

class owa_base_011_update extends owa_update {

    var $schema_version = 11;

    function up($force = true) {
		
		$s = owa_coreAPI::serviceSingleton();
		$file = OWA_MODULES_DIR . 'fileCache/classes/fileCache.php';
        $class_info = array( 'owa_fileCache', $file, [] );
        $s->setMapValue( 'object_cache_types', 'file', $class_info);
        
        owa_coreAPI::setSetting('base', 'cache_objects', true);
	    owa_coreAPI::setSetting('base', 'cacheType', 'file');
        
        $cache = owa_coreAPI::cacheSingleton();
        
        if ( $cache->flush() ) {

            owa_coreAPI::notice('Cache Flushed');
            return true;

        } else {
            $this->e->notice('Could not flush cache.');
            return false;
        }

    }

    function down() {

        return true;
    }
}

?>