<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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
 * Service User Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_state {

    var $stores = array();
    var $stores_meta = array();
    var $is_dirty;
    var $dirty_stores;
    var $default_store_type = 'cookie';
    var $stores_with_cdh = array();
    var $initial_state = array();

    function __construct() {

    }

    function __destruct() {

        return false;
    }

    function registerStore( $name, $expiration, $length = '', $format = 'json', $type = 'cookie', $cdh = null ) {

        $this->stores_meta[$name] = array(
            'expiration'     => $expiration,
            'length'         => $length,
            'format'         => $format,
            'type'             => $type,
            'cdh_required'     => $cdh
        );

        if ( $cdh ) {
            $this->stores_with_cdh[] = $name;
        }
    }



    public function get($store, $name = '') {

        owa_coreAPI::debug("Getting state - store: ".$store.' key: '.$name);
        //owa_coreAPI::debug("existing stores: ".print_r($this->stores, true));
        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        if (array_key_exists($store, $this->stores)) {

            if (!empty($name)) {
                // check to ensure this is an array, could be a string.
                if (is_array($this->stores[$store]) && array_key_exists($name, $this->stores[$store])) {

                    return $this->stores[$store][$name];
                } else {
                    return false;
                }
            } else {

                return $this->stores[$store];
            }
        } else {

            return false;
        }
    }

    function setState($store, $name = '', $value, $store_type = '', $is_perminent = false) {

        owa_coreAPI::debug(sprintf('populating state for store: %s, name: %s, value: %s, store type: %s, is_perm: %s', $store, $name, print_r($value, true), $store_type, $is_perminent));

        // set values
        if (empty($name)) {
            $this->stores[$store] = $value;
            //owa_coreAPI::debug('setstate: '.print_r($this->stores, true));
        } else {
            //just in case the store was set first as a string instead of as an array.
            if ( array_key_exists($store, $this->stores)) {

                if ( ! is_array( $this->stores[$store] ) ) {
                    $new_store = array();
                    // check to see if we need ot ad a cdh
                    if ( $this->isCdhRequired($store) ) {
                        $new_store['cdh'] = $this->getCookieDomainHash();
                    }

                    $new_store[$name] = $value;
                    $this->stores[$store] = $new_store;

                } else {
                    $this->stores[$store][$name] = $value;
                }
            // if the store does not exist then    maybe add a cdh and the value
            } else {

                if ( $this->isCdhRequired($store) ) {
                    $this->stores[$store]['cdh'] = $this->getCookieDomainHash();
                }

                $this->stores[$store][$name] = $value;
            }

        }

        $this->dirty_stores[] = $store;
        //owa_coreAPI::debug(print_r($this->stores, true));
    }

    function isCdhRequired($store_name) {

        if ( isset( $this->stores_meta[$store_name] ) ) {
            return $this->stores_meta[$store_name]['cdh_required'];
        }
    }

    function set($store, $name = '', $value, $store_type = '', $is_perminent = false) {

        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        $this->setState($store, $name, $value, $store_type, $is_perminent);

        // persist immeadiately if the store type is cookie
        if ($this->stores_meta[$store]['type'] === 'cookie') {

            $this->persistState($store);
        }
    }

    function persistState( $store ) {

        //check to see that store exists.
        if ( isset( $this->stores[ $store ] ) ) {
            owa_coreAPI::debug('Persisting state store: '. $store . ' with: '. print_r($this->stores[ $store ], true));
            // transform state array into a string using proper format
            if ( is_array( $this->stores[$store] ) ) {
                switch ( $this->stores_meta[$store]['type'] ) {

                    case 'cookie':

                        // check for old style assoc format
                        if ( $this->stores_meta[$store]['format'] === 'assoc' ) {
                            $cookie_value = owa_lib::implode_assoc('=>', '|||', $this->stores[ $store ] );
                        } else {
                            $cookie_value = json_encode( $this->stores[ $store ] );
                        }

                        break;

                    default:

                }
            } else {
                $cookie_value = $this->stores[ $store ];
            }
            // get expiration time
            $time = $this->stores_meta[$store]['expiration'];
            //set cookie
            owa_coreAPI::createCookie( $store, $cookie_value, $time, "/", owa_coreAPI::getSetting( 'base', 'cookie_domain' ) );

        } else {

            owa_coreAPI::debug("Cannot persist state. No store registered with name $store");
        }
    }

    function setInitialState($store, $value, $store_type = '') {

        if ($value) {
            $this->initial_state[$store] = $value;
        }
    }

    function loadState($store, $name = '', $value = '', $store_type = 'cookie') {

        //get possible values
        if ( ! $value && isset( $this->initial_state[$store] ) ) {
            $possible_values = $this->initial_state[$store];
        } else {
	        //owa_coreAPI::debug( $this->initial_state );
	        owa_coreAPI::debug("NO state store: $store found");
            return;
        }


        //count values
        $count = count($possible_values);
        // loop throught values looking for a domain hash match or just using the last value.
        foreach ($possible_values as $k => $value) {
            // check format of value

            if ( strpos( $value, "|||" ) ) {
                $value = owa_lib::assocFromString($value);
            } elseif ( strpos( $value, ":" ) ) {
                $value = json_decode($value);
                $value = (array) $value;
            } else {
                $value = $value;
            }

            if ( in_array( $store, $this->stores_with_cdh ) ) {

                if ( is_array( $value ) && isset( $value['cdh'] ) ) {

                    $runtime_cdh = $this->getCookieDomainHash();
                    $cdh_from_state = $value['cdh'];

                    // return as the cdh's do not match
                    if ( $cdh_from_state === $runtime_cdh ) {
                        owa_coreAPI::debug("cdh match:  $cdh_from_state and $runtime_cdh");
                        return $this->setState($store, $name, $value, $store_type);
                    } else {
                        // cookie domains do not match so we need to delete the cookie in the offending domain
                        // which is always likely to be a sub.domain.com and thus HTTP_HOST.
                        // if cookie is not deleted then new cookies set on .domain.com will never be seen by PHP
                        // as only the sub domain cookies are available.
                        owa_coreAPI::debug("Not loading state store: $store. Domain hashes do not match - runtime: $runtime_cdh, cookie: $cdh_from_state");
                        //owa_coreAPI::debug("deleting cookie: owa_$store");
                        //owa_coreAPI::deleteCookie($store,'/', $_SERVER['HTTP_HOST']);
                        //unset($this->initial_state[$store]);
                        //return;
                    }
                } else {

                    owa_coreAPI::debug("Not loading state store: $store. No domain hash found.");
                    return;
                }

            } else {
                // just set the state with the last value
                if ( $k === $count - 1 ) {
                    owa_coreAPI::debug("loading last value in initial state container for store: $store");
                    return $this->setState($store, $name, $value, $store_type);
                }
            }
        }
    }

    function clear($store, $name = '') {

        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        if ( array_key_exists( $store, $this->stores ) ) {

            if ( ! $name ) {

                unset( $this->stores[ $store ] );

                if ($this->stores_meta[$store]['type'] === 'cookie') {

                    return owa_coreAPI::deleteCookie($store);
                }

            } else {

                if ( array_key_exists( $name, $this->stores[ $store ] ) ) {
                    unset( $this->stores[ $store ][ $name ] );

                    if ($this->stores_meta[$store]['type'] === 'cookie') {

                        return $this->persistState( $store );
                    }
                }
            }
        }
    }

    function getPermExpiration() {

        $time = time()+3600*24*365*15;
        return $time;
    }

    function addStores($array) {

        $this->stores = array_merge($this->stores, $array);
        return;
    }

    function getCookieDomainHash($domain = '') {

        if ( ! $domain ) {
            $domain = owa_coreAPI::getSetting( 'base', 'cookie_domain' );
        }

        return owa_lib::crc32AsHex($domain);
    }
}

?>