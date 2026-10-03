<?php
namespace OWA\Core;


// Open Web Analytics - An Open Source Web Analytics Framework

/**
 * Abstract Cache Type Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

class CacheType {
	
	var $collection_expiration_periods = [];
	var $cache_id = 1;
	
    function get( $collection, $id ) {
        
        return false;
    }
    
    function set( $collection, $id, $value ) {
        
        return false;
    }
    
    function remove( $collection, $id ) {
        
        return false;
    }
    
    /**
     * Store specific implementation of flushing the cold cache store
     */
    function flush() {
    
        return false;
    }	
    
    function setCollectionExpirationPeriod($collection_name, $seconds) {
    
        $this->collection_expiration_periods[$collection_name] = $seconds;
    }
    
    function getCollectionExpirationPeriod($collection_name) {
        
        // for some reason an 'array_key_exists' check does not work here. using isset instead.
        if (isset($this->collection_expiration_periods[$collection_name])) {
            return $this->collection_expiration_periods[$collection_name];
        } else {
            return false;
        }
    }
}

?>