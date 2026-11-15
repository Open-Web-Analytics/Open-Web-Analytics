<?php
namespace OWA\Module\Base\Classes;


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
 * The object cache: rows looked up during one request, kept for the rest of it.
 *
 * MEMORY ONLY. There was a persistent tier behind it -- the File Cache and
 * Memcached modules -- that saved about a third of a millisecond a lookup
 * against the database, was off by default, and brought stale rows served for
 * a week, long processes writing old rows back over newer ones, a file per
 * user agent anyone cared to send, and unserialize() of whatever a cache file
 * named. It is removed; a request reads each row it needs from the database
 * once.
 */
class Cache {

    var $statistics = array( 'hits' => 0, 'miss' => 0, 'added' => 0, 'removed' => 0 );

    /** @var MemoryCache */
    var $warm;

    function __construct() {

        $this->warm = new MemoryCache();
    }

    function set( $collection, $key, $value ) {

        $this->warm->set( $collection, $this->hash( $key ), $value );
        $this->statistics['added']++;
    }

    function get( $collection, $key ) {

        $value = $this->warm->get( $collection, $this->hash( $key ) );

        if ( $value ) {

            $this->statistics['hits']++;

            return $value;
        }

        $this->statistics['miss']++;
    }

    function remove( $collection, $key ) {

        $this->statistics['removed']++;

        return $this->warm->remove( $collection, $this->hash( $key ) );
    }

    function flush() {

        $this->warm->flush();

        return true;
    }

    function getStats() {

        return sprintf( 'Cache: %d hits, %d misses, %d added, %d removed.',
            $this->statistics['hits'], $this->statistics['miss'], $this->statistics['added'], $this->statistics['removed'] );
    }

    function hash( $id ) {

        return md5( $id );
    }
}
