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

/**
 * Abstract Cache Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_cache {

    var $cache;
    var $statistics = array('warm' => 0, 'cold' => 0, 'miss' => 0, 'replaced' => 0, 'added' => 0, 'removed' => 0, 'dirty' => 0, 'persisted' => 0);
    var $cache_id = 1; // default cache id
    var $collections;
    var $dirty_collections;
    var $dirty_objs = array();
    var $global_collections = array();
    var $collection_expiration_periods = [];
    var $e;
    var $warm;
    var $cold;
    var $cache_conf;

    /**
     * Constructor
     * 
     * Takes cache directory as param
     *
     * @param $cache_dir string
     */
    function __construct( $cache_dir = '') {
	    
	    
	    // this is here before this class seems to load before modules can register implementations...
	    $s = owa_coreAPI::serviceSingleton();
	    $s->setMapValue('object_cache_types', 'memory', ['owa_memoryCache', OWA_BASE_CLASS_DIR.'memoryCache.php', [] ] );
	    
	    $this->cache_conf = [
		    
		    'cache_id' => $this->cache_id
	    ];
	    
	    
        $this->warm = owa_coreAPI::implementationFactory( 'object_cache_types', 'memory', $this->cache_conf );
        
        //$this->createColdCache();
        
        //$this->e = owa_coreAPI::errorSingleton();
    }
    
    function createColdCache() {
	    
	    if ( owa_coreAPI::getSetting( 'base', 'cache_objects' ) ) {
	    
	    	$cache_type = owa_coreAPI::getSetting('base', 'cacheType');
	    	
	    	if ( $cache_type ) {
	    	
				$this->cold = owa_coreAPI::implementationFactory( 'object_cache_types', $cache_type, $this->cache_conf );
				owa_coreAPI::debug( 'CACHE: created cold cache of type: ' . $cache_type );
			}
		}
    }
      
    function getColdCache() {
	    
	    if (! $this->cold ) {
		    
		    $this->createColdCache();
	    }
	    
	    return $this->cold;
    }
            
    function set( $collection, $key, $value, $expires = '' ) {
    
        $hkey = $this->hash($key);
        owa_coreAPI::debug('set key: '.$key);
        owa_coreAPI::debug('set hkey: '.$hkey);
        //$this->cache[$collection][$hkey] = $value;
        $this->warm->set( $collection, $hkey, $value );
        
        $this->debug(sprintf('Added Object to Cache - Collection: %s, id: %s', $collection, $hkey));
        $this->statistics['added']++;
        $this->dirty_objs[$collection][$hkey] = $hkey;
        $this->dirty_collections[$collection] = true;
        $this->debug(sprintf('Added Object to Dirty List - Collection: %s, id: %s', $collection, $hkey));
        $this->statistics['dirty']++;
            
    }
    
    function replace( $collection, $key, $value ) {
    
        $hkey = $this->hash($key);
        //$this->cache[$collection][$hkey] = $value;
        $this->warm->set( $collection, $hkey, $value );
        $this->debug(sprintf('Replacing Object in Cache - Collection: %s, id: %s', $collection, $hkey));
        $this->statistics['replaced']++;
        
        // check to make sure the dirty collection exists and object is not already in there.
        if (!empty($this->dirty_objs[$collection])) {
            if(!in_array($hkey, $this->dirty_objs[$collection])) {
                $this->dirty_objs[$collection][] = $hkey;
                $this->dirty_collections[$collection] = true;
                $this->debug(sprintf('Added Object to Dirty List - Collection: %s, id: %s', $collection, $hkey));
                $this->statistics['dirty']++;
            }
        } else {
            $this->dirty_objs[$collection][] = $hkey;
            $this->dirty_collections[$collection] = true;
            $this->debug(sprintf('Added Object to Dirty List - Collection: %s, id: %s', $collection, $hkey));
            $this->statistics['dirty']++;
        }
            
        
    }
    
    function get( $collection, $key ) {
        
        $id = $this->hash($key);
        
        // check warm cache
        $obj = $this->warm->get( $collection, $id );
        
        // if in warm cahce n=increment stats
        if ( $obj ) {
	        
            $this->debug(sprintf('CACHE: Hit (Warm) - Retrieved Object from Cache - Collection: %s, id: %s', $collection, $id));
            $this->statistics['warm']++;
            return $obj;
               
        } else {
        
        	// else load from cold cache
        	$cold = $this->getColdCache();
        	
        	if ( $cold ) {
	        	
            	$item = $cold->get( $collection, $id );
            
	            if ($item) {
		            //put in warm cache
	                //$this->cache[$collection][$id] = $item;
	                $this->warm->set( $collection, $id, $item );
	                $this->debug(sprintf('CACHE: Hit (Cold) - Retrieved Object from Cache File - Collection: %s, id: %s', $collection, $id));
	                $this->statistics['cold']++;
	                return $this->warm->get( $collection, $id );
	            }
	        }
	    }
	    
	    $this->debug( sprintf( 'CACHE: Miss - object not found for Collection: %s, id: %s', $collection, $id ) );
	    $this->statistics['miss']++;
    }
    
    function remove($collection, $key) {
    
        $id = $this->hash($key);
        //unset($this->cache[$collection][$id]);
        $this->warm->remove( $collection, $id );
        
        $cold = $this->getColdCache();
        
        if ( $cold ) {
	        $this->statistics['removed']++;
	    	return $cold->remove( $collection, $id );
        }
    }
    
    function persistCache() {
        
        $this->debug("starting to persist cache...");
        
        $cold = $this->getColdCache();
        
        if ( $cold ) {
        
	        // check for dirty objects
	        if (!empty($this->dirty_objs)) {
	            
	            $this->debug('Dirty Objects: '.print_r($this->dirty_objs, true));
	            
	            // persist dirty objects
	            foreach ($this->dirty_objs as $collection => $ids) {
	                
	                foreach ($ids as $id) {
		                
	                    $cold->set( $collection, $id, $this->warm->get( $collection, $id ) );
	                    $this->statistics['persisted']++;
	                }
	            }
	            
	        } else {
	            $this->debug("There seem to be no dirty objects in the cache to persist.");
	        }
	    } else {
		    
		    $this->debug("There is no cold cache to persist to.");
	    }
    }
    

    
    /**
     * Store specific implementation of flushing the cold cache store
     */
    function flush() {
    	
    	$cold = $this->getColdCache();
        
        if ( $cold ) {
    	
 	       return $cold->flush();
 	    }
    }
    
    function getStats() {
    
        return sprintf("Cache Statistics:
                          Total Hits: %s (Warm/Cold: %s/%s)
                          Total Miss: %s
                          Total Added to Cache: %s
                          Total Replaced: %s
                          Total Dirty: %s
                          Total Persisted: %s
                          Total Removed: %s",
                          $this->statistics['warm'] + $this->statistics['cold'],
                          $this->statistics['warm'],
                          $this->statistics['cold'],
                          $this->statistics['miss'],
                          $this->statistics['added'],
                          $this->statistics['replaced'],
                          $this->statistics['dirty'],
                          $this->statistics['persisted'],
                          $this->statistics['removed']);
    }
    
    function prepare($obj) {
    
        return $obj;
    }
    
    function __destruct() {
        
        $this->persistCache();
        $this->debug( $this->getStats() );
        $this->persistStats();
    }
    
    function persistStats() {
    
        return false;
    }
    
    function hash($id) {
    	
        return md5( $id . OWA_AUTH_KEY );
    }
    
    function debug($msg) {
        
        return owa_coreAPI::debug($msg);
    }
    
    function error($msg) {
    
        return false;
    }
        
    function setCollectionExpirationPeriod($collection_name, $seconds) {
    	
    	$cold = $this->getColdCache();
        
        if ( $cold ) {
    		
    		$cold->setCollectionExpirationPeriod($collection_name, $seconds);
        }
    }
    
    function getCollectionExpirationPeriod($collection_name) {
        
        $cold = $this->getColdCache();
        
        if ( $cold ) {
	        
        	$cold->getCollectionExpirationPeriod($collection_name);
        }
    }
    
    function setGlobalCollection($collection) {
    
        return $this->global_collections[] = $collection;
    
    }
}

?>