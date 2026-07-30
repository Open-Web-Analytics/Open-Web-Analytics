<?php
namespace OWA\Module\Base\Update;


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

class Update011 extends \OWA\Core\Update {

    var $schema_version = 11;

    function up($force = true) {
		
		$s = \OWA\Core\CoreAPI::serviceSingleton();
		$file = OWA_MODULES_DIR . 'FileCache/Classes/FileCache.php'; // PSR-4 on-disk path
        $class_info = array( 'owa_fileCache', $file, [] );
        $s->setMapValue( 'object_cache_types', 'file', $class_info);
        
        \OWA\Core\CoreAPI::setSetting('base', 'cache_objects', true);
	    \OWA\Core\CoreAPI::setSetting('base', 'cacheType', 'file');
        
        $cache = \OWA\Core\CoreAPI::cacheSingleton();
        
        if ( $cache->flush() ) {

            \OWA\Core\CoreAPI::notice('Cache Flushed');
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