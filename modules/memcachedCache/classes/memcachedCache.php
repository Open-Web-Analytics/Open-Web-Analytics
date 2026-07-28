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

/**
 * Memcached Based Cache
 *
 * Backed by the PECL memcached extension (http://php.net/memcached). The
 * extension is not always installed, so the constructor guards on
 * extension_loaded('memcached') and disables itself (no servers) rather than
 * fataling when it is absent.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

class owa_memcachedCache extends owa_cacheType {

    /**
     * PECL Memcached client, or null when the extension is unavailable.
     *
     * @var Memcached|null
     */
    var $mc;

    /**
     * Constructor
     *
     * @param array $conf
     */
    function __construct( $conf = [] ) {

		if ( array_key_exists( 'cache_id', $conf ) ) {

			$this->cache_id = $conf['cache_id'];
		}

        if ( ! extension_loaded( 'memcached' ) ) {

            owa_coreAPI::notice( "PECL memcached extension not loaded; memcached object cache disabled." );
            $this->mc = null;
            return parent::__construct();
        }

        $servers = owa_coreAPI::getSetting( 'memcachedCache', 'memcachedServers' );

        $persistant = owa_coreAPI::getSetting( 'memcachedCache', 'memcachedPersistantConnections' );

        // A persistent_id pools/reuses the connection across requests. Only
        // pass one when persistent connections are enabled.
        $this->mc = $persistant ? new Memcached( 'owa' ) : new Memcached();

        $this->mc->setOption( Memcached::OPT_COMPRESSION, true );

        // With a persistent connection the server list survives across
        // requests, so only add servers the first time (avoids piling up
        // duplicate entries on every construct).
        if ( ! count( $this->mc->getServerList() ) ) {

            foreach ( (array) $servers as $server ) {

                // Servers are configured as 'host:port' strings.
                $parts = explode( ':', $server );
                $host  = $parts[0];
                $port  = isset( $parts[1] ) ? (int) $parts[1] : 11211;

                $this->mc->addServer( $host, $port );
            }
        }

        return parent::__construct();
    }

    function makeKey( $values ) {

        $key  = 'owa-';
        $key .= $this->cache_id . '-';
        $key .= implode('-', $values);
        return $key;
    }

    function get( $collection, $id ) {

        if ( ! $this->mc ) {
            return;
        }

        $key = $this->makeKey( array( $collection, $id ) );
        $item = $this->mc->get( $key );

        // PECL returns false + RES_NOTFOUND on a miss; distinguish that from a
        // stored falsey value via the result code.
        if ( $this->mc->getResultCode() === Memcached::RES_SUCCESS ) {
            owa_coreAPI::debug("$key retrieved from memcache.");
            return $item;
        } else {
            owa_coreAPI::debug("$key was not found in memcache.");
        }

    }

    function set( $collection, $id, $value ) {

        if ( ! $this->mc ) {
            return false;
        }

        $key = $this->makeKey( array( $collection, $id ) );
        $expiration = $this->getCollectionExpirationPeriod( $collection );

        // PECL set() is an upsert (add-or-replace) and auto-serializes the
        // value, collapsing the old replace-then-add dance into one call.
        $ret = $this->mc->set( $key, $value, (int) $expiration );

        if ( $ret ) {
            owa_coreAPI::debug( "$key successfully set in memcache." );
            return true;
        } else {
            owa_coreAPI::debug( "$key not set in memcache." );
            return false;
        }
    }

    function remove( $collection, $id ) {

        if ( ! $this->mc ) {
            return;
        }

        $key = $this->makeKey( array( $collection, $id ) );

        $ret = $this->mc->delete($key);

        if ($ret) {
            owa_coreAPI::debug( "$key successfully deleted from memcache." );
        } else {
            owa_coreAPI::debug( "$key not deleted from memcache.");
        }
    }

    function flush() {

        if ( ! $this->mc ) {
            return true;
        }

        return $this->mc->flush();
    }
}

?>