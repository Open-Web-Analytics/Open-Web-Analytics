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
 * The object cache: values kept for the rest of one request, by collection
 * and key. Nothing outlives the request.
 */
class Cache {

    /** @var array<string, array<string, mixed>> values by collection, then key */
    private $items = [];

    function set( $collection, $key, $value ) {

        $this->items[ $collection ][ $key ] = $value;
    }

    function get( $collection, $key ) {

        return $this->items[ $collection ][ $key ] ?? null;
    }

    function remove( $collection, $key ) {

        if ( ! isset( $this->items[ $collection ] ) || ! array_key_exists( $key, $this->items[ $collection ] ) ) {

            return false;
        }

        unset( $this->items[ $collection ][ $key ] );

        return true;
    }

    function flush() {

        $this->items = [];

        return true;
    }
}
