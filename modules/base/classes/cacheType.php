<?php

// Open Web Analytics - An Open Source Web Analytics Framework

/**
 * Abstract Cache Type Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

class owa_cacheType {
	
	var $collection_expiration_periods = [];
	var $cache_id = 1;
	
	/**
     * Store specific implementation of getting an object from the cold cache store
     */
    function getItemFromCacheStore( $collection, $id ) {
        
        return $this->get( $collection, $id );
    }
    
    function get( $collection, $id ) {
        
        return false;
    }
    
    /**
     * Store specific implementation of putting an object to the cold cache store
     */
    function putItemToCacheStore($collection, $id, $value = '') {
        
        return $this->set( $collection, $id, $value );
    }
    
    function set( $collection, $id, $value ) {
        
        return false;
    }
    
    /**
     * Store specific implementation of removing an object to the cold cache store
     */
    function removeItemFromCacheStore( $collection, $id ) {
        
        return $this->remove( $collection, $id );
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