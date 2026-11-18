<?php
namespace OWA\Module\Base\Classes;

/**
 * Service User Class
 * 
 *
 * @phpstan-type StoreMeta array{expiration: mixed, length: mixed, format: mixed, type: mixed, cdh_required: mixed}
 */


class State {

    /** @var array<mixed> store name => its values (key => value), or a single value */
    public $stores = [];
    /** @var array<string, StoreMeta> store name => how it is kept, as registerStore() was told */
    public $stores_meta = [];
    /** @var mixed nothing sets it */
    public $is_dirty;
    /** @var list<string>|null the stores set, once per set */
    public $dirty_stores;
    /** @var list<string> the stores that carry a cookie domain hash */
    public $stores_with_cdh = [];
    /** @var array<list<string>> store name => every value the request's cookies carried for it */
    public $initial_state = [];

    public function __construct() {

    }

    public function __destruct() {

    }

    /**
     * @param  string $name
     * @param  mixed  $expiration the cookie's, as createCookie() takes it
     * @param  mixed  $length
     * @param  mixed  $format     json, or assoc for the old a=>b|||c=>d format
     * @param  mixed  $type       cookie for a store persisted as one
     * @param  mixed  $cdh        truthy when the store carries a cookie domain hash
     * @return null
     */
    public function registerStore( $name, $expiration, $length = '', $format = 'json', $type = 'cookie', $cdh = null ) {

        $this->stores_meta[$name] = [
            'expiration'     => $expiration,
            'length'         => $length,
            'format'         => $format,
            'type'             => $type,
            'cdh_required'     => $cdh
        ];

        if ( $cdh ) {
            $this->stores_with_cdh[] = $name;
        }

        return null;
    }



    /**
     * @param  string $store
     * @param  string $name  '' for the whole store
     * @return mixed the value, or the store; false when there is none
     */
    public function get($store, $name = '') {

        \OWA\Core\CoreAPI::debug("Getting state - store: ".$store.' key: '.$name);
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

    /**
     * @param  string $store
     * @param  string $name         '' to replace the whole store with $value
     * @param  mixed  $value
     * @param  string $store_type   only logged
     * @param  bool   $is_perminent only logged
     * @return void
     */
    public function setState($store, $name, $value, $store_type = '', $is_perminent = false) {

        // Not the value: state holds visitor and session identifiers.
        \OWA\Core\CoreAPI::debug(sprintf('populating state for store: %s, name: %s, store type: %s, is_perm: %s', $store, $name, $store_type, $is_perminent));

        // set values
        if (empty($name)) {
            $this->stores[$store] = $value;
            //owa_coreAPI::debug('setstate: '.print_r($this->stores, true));
        } else {
            //just in case the store was set first as a string instead of as an array.
            if ( array_key_exists($store, $this->stores)) {

                if ( ! is_array( $this->stores[$store] ) ) {
                    $new_store = [];
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

    /**
     * @param  string $store_name
     * @return mixed|void the store's cdh_required, as registered; nothing for a store not registered
     */
    public function isCdhRequired($store_name) {

        if ( isset( $this->stores_meta[$store_name] ) ) {
            return $this->stores_meta[$store_name]['cdh_required'];
        }
    }

    /**
     * @param  string $store
     * @param  string $name         '' to replace the whole store with $value
     * @param  mixed  $value
     * @param  string $store_type   only logged
     * @param  bool   $is_perminent only logged
     * @return null
     */
    public function set($store, $name, $value, $store_type = '', $is_perminent = false) {

        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        $this->setState($store, $name, $value, $store_type, $is_perminent);

        // persist immeadiately if the store type is cookie
        if ($this->stores_meta[$store]['type'] === 'cookie') {

            $this->persistState($store);
        }

        return null;
    }

    /**
     * @param  string $store
     * @return void
     */
    public function persistState( $store ) {

        //check to see that store exists.
        if ( isset( $this->stores[ $store ] ) ) {
            \OWA\Core\CoreAPI::debug( 'Persisting state store: ' . $store );
            // transform state array into a string using proper format
            // Only cookie stores are persisted, so the store's type is not read.
            if ( is_array( $this->stores[$store] ) ) {

                // check for old style assoc format
                if ( $this->stores_meta[$store]['format'] === 'assoc' ) {
                    $cookie_value = \OWA\Core\Lib::implode_assoc('=>', '|||', $this->stores[ $store ] );
                } else {
                    $cookie_value = json_encode( $this->stores[ $store ] );
                }
            } else {
                $cookie_value = $this->stores[ $store ];
            }
            // get expiration time
            $time = $this->stores_meta[$store]['expiration'];
            //set cookie
            \OWA\Core\CoreAPI::createCookie( $store, $cookie_value, $time, "/", \OWA\Core\CoreAPI::getSetting( 'base', 'cookie_domain' ) );

        } else {

            \OWA\Core\CoreAPI::debug("Cannot persist state. No store registered with name $store");
        }
    }

    /**
     * @param  string       $store
     * @param  list<string> $value      every value the request's cookies carried for it
     * @param  string       $store_type unused
     * @return void
     */
    public function setInitialState($store, $value, $store_type = '') {

        if ($value) {
            $this->initial_state[$store] = $value;
        }
    }

    /**
     * @param  string $store
     * @param  string $name
     * @param  mixed  $value      truthy loads nothing
     * @param  string $store_type
     * @return void
     */
    public function loadState($store, $name = '', $value = '', $store_type = 'cookie') {

        //get possible values
        if ( ! $value && isset( $this->initial_state[$store] ) ) {
            $possible_values = $this->initial_state[$store];
        } else {
            //owa_coreAPI::debug( $this->initial_state );
            \OWA\Core\CoreAPI::debug("NO state store: $store found");
            return;
        }


        //count values
        $count = count($possible_values);
        // loop throught values looking for a domain hash match or just using the last value.
        foreach ($possible_values as $k => $value) {
            // check format of value

            if ( strpos( $value, "|||" ) ) {
                $value = \OWA\Core\Lib::assocFromString($value);
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
                        \OWA\Core\CoreAPI::debug("cdh match:  $cdh_from_state and $runtime_cdh");
                        $this->setState($store, $name, $value, $store_type);
                        return;
                    } else {
                        // cookie domains do not match so we need to delete the cookie in the offending domain
                        // which is always likely to be a sub.domain.com and thus HTTP_HOST.
                        // if cookie is not deleted then new cookies set on .domain.com will never be seen by PHP
                        // as only the sub domain cookies are available.
                        \OWA\Core\CoreAPI::debug("Not loading state store: $store. Domain hashes do not match - runtime: $runtime_cdh, cookie: $cdh_from_state");
                        //owa_coreAPI::debug("deleting cookie: owa_$store");
                        //owa_coreAPI::deleteCookie($store,'/', $_SERVER['HTTP_HOST']);
                        //unset($this->initial_state[$store]);
                        //return;
                    }
                } else {

                    \OWA\Core\CoreAPI::debug("Not loading state store: $store. No domain hash found.");
                    return;
                }

            } else {
                // just set the state with the last value
                if ( $k === $count - 1 ) {
                    \OWA\Core\CoreAPI::debug("loading last value in initial state container for store: $store");
                    $this->setState($store, $name, $value, $store_type);
                    return;
                }
            }
        }
    }

    /**
     * @param  string $store
     * @param  string $name  '' for the whole store
     * @return mixed|void deleteCookie()'s answer when a cookie store is cleared whole
     */
    public function clear($store, $name = '') {

        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        if ( array_key_exists( $store, $this->stores ) ) {

            if ( ! $name ) {

                unset( $this->stores[ $store ] );

                if ($this->stores_meta[$store]['type'] === 'cookie') {

                    return \OWA\Core\CoreAPI::deleteCookie($store);
                }

            } else {

                if ( array_key_exists( $name, $this->stores[ $store ] ) ) {
                    unset( $this->stores[ $store ][ $name ] );

                    if ($this->stores_meta[$store]['type'] === 'cookie') {

                        $this->persistState( $store );
                        return;
                    }
                }
            }
        }
    }

    /**
     * @param  array<mixed> $array store name => values
     * @return void
     */
    public function addStores($array) {

        $this->stores = array_merge($this->stores, $array);
        return;
    }

    /**
     * @param  string $domain '' for the cookie_domain setting
     * @return string
     */
    public function getCookieDomainHash($domain = '') {

        if ( ! $domain ) {
            $domain = \OWA\Core\CoreAPI::getSetting( 'base', 'cookie_domain' );
        }

        return \OWA\Core\Lib::crc32AsHex($domain);
    }
}
