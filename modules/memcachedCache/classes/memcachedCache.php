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

require_once(OWA_BASE_CLASS_DIR.'cacheType.php');

if ( ! class_exists( 'memcached' ) ) {
	
    require_once( OWA_INCLUDE_DIR . 'memcached-client.php' );
}

/**
 * Memcached Based Cache
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

class owa_memcachedCache extends owa_cacheType {

    var $mc;
   
    /**
     * Constructor
     *
     * Takes cache directory as param
     *
     * @param $cache_dir string
     */
    function __construct( $conf = [] ) {
		
		if ( array_key_exists( 'cache_id', $conf ) ) {
			
			$this->cache_id = $conf['cache_id'];
		}
		
        $servers = owa_coreAPI::getSetting( 'memcachedCache', 'memcachedServers' );
        
        $persistant = owa_coreAPI::getSetting( 'memcachedCache', 'memcachedPersistantConnections' );
       
        $debug = owa_lib::inDebug();

        $this->mc = new owa_memcachedClient(array(
                'servers' => $servers,
                'debug'   => $debug,
                'compress_threshold' => 10240,
                'persistant' => $persistant
           ));

        return parent::__construct();
    }

    function makeKey( $values ) {
	    
        $key  = 'owa-';
        $key .= $this->cache_id . '-';
        $key .= implode('-', $values);
        return $key;
    }

    function get( $collection, $id ) {
	    
        $key = $this->makeKey( array( $collection, $id ) );
        $item = $this->mc->get( $key );

        if ($item) {
            owa_coreAPI::debug("$key retrieved from memcache.");
            return $item;
        } else {
            owa_coreAPI::debug("$key was not found in memcache.");
        }

    }

    function set( $collection, $id, $value ) {

        $key = $this->makeKey( array( $collection, $id ) );
        $item = $value;
        $expiration = $this->getCollectionExpirationPeriod( $collection );
        $ret = $this->mc->replace( $key, $item, $expiration );

        if ( $ret ) {
            owa_coreAPI::debug( "$key successfully replaced in memcache." );
            return true;

        } else {
            $ret = $this->mc->add( $key, $item );
            if ( $ret ) {
                owa_coreAPI::debug( "$key successfully added to memcache." );
                return true;
            } else {
                owa_coreAPI::debug( "$key not added/replaced in memcache." );
                return false;
            }
        }
    }

    function remove( $collection, $id ) {

        $key = $this->makeKey( array( $collection, $id ) );
        
        $ret = $this->mc->delete($key);

        if ($ret) {
            owa_coreAPI::debug( "$key successfully deleted from memcache." );
        } else {
            owa_coreAPI::debug( "$key not deleted from memcache.");
        }
    }

    function flush() {

        owa_coreAPI::notice("Cannot flush Memcache from client.");
        return true;
    }
}

class owa_memcachedClient extends memcached {

    function _debugprint( $text ) {
        owa_coreAPI::debug( "memcached: $text" );
    }
}

?>