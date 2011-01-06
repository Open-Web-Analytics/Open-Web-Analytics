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
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_state {

	var $stores = array();
	var $stores_meta = array();
	var $is_dirty;
	var $dirty_stores;
	var $default_store_type = 'cookie';
	var $stores_with_cdh = array('c','v','s');
	var $store_formats = array ('v' => 'assoc', 's' => 'assoc');
	var $initial_state = array();
	
	function __construct() {
	
	}
	
	function __destruct() {
	
		$this->persistState();
	}
		
	function persistState() {
	
		return false;
	
	}
	
	function get($store, $name = '') {
		owa_coreAPI::debug("Getting state - store: ".$store.' key: '.$name);
		
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
		
		// first call to set for a store sets the meta
		if (!array_key_exists($store, $this->stores)) {
		
			if (empty($store_type)) {
				$store_type = $this->default_store_type;
			}
			
			$this->stores_meta[$store]['type'] = $store_type;
			
			if ($is_perminent === true) {
				$this->stores_meta[$store]['is_perminent'] = true;
			}
			
		}
		
		// set values
		if (empty($name)) {
			$this->stores[$store] = $value;
			//owa_coreAPI::debug(print_r($this->stores, true));
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
			// if the store does not exist then	maybe add a cdh and the value
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
		
		return in_array( $store_name, $this->stores_with_cdh );
	}

	function set($store, $name = '', $value, $store_type = '', $is_perminent = false) {
	
		if ( ! isset($this->stores[$store] ) ) {
			$this->loadState($store);
		}
		
		$this->setState($store, $name, $value, $store_type, $is_perminent);
		
		// persist immeadiately if the store type is cookie
		if ($this->stores_meta[$store]['type'] === 'cookie') {
			
			$time = 0;
			
			// needed? i dont think so.
			if (isset($this->stores_meta[$store]['is_perminent']) && $this->stores_meta[$store]['is_perminent'] === true) {
				$time = $this->getPermExpiration();
			} elseif (isset($this->stores_meta[$store]['is_perminent']) && $this->stores_meta[$store]['is_perminent'] > 0) {
				$time = $this->stores_meta[$store]['is_perminent'] * 3600 * 24;
			}
			
			if ($is_perminent === true) {
				$time = $this->getPermExpiration();
			}
			
			// transform state array into a string using proper format
			if ( is_array( $this->stores[$store] ) ) {
				
				// check for old style assoc format
				if (isset($this->store_formats[$store]) && $this->store_formats[$store] === 'assoc') {
					$cookie_value = owa_lib::implode_assoc('=>', '|||', $this->stores[$store] );
				} else {
					$cookie_value = json_encode( $this->stores[$store] );
				}
			}
			
			
			owa_coreAPI::createCookie($store, $this->stores[$store], $time, "/", owa_coreAPI::getSetting('base', 'cookie_domain'));
		}	
	}
	
	function setInitialState($store, $value, $store_type) {
		
		if ($value) {
			$this->initial_state[$store] = $value;
		}
	}
	
	function loadState($store, $name = '', $value = '', $store_type = 'cookie') {
	
		if ( ! $value && isset( $this->initial_state[$store] ) ) {
			$value = $this->initial_state[$store];
		} else {
			return;
		}
	
		// check format of value
		if (strpos($value, "|||")) {
			$value = owa_lib::assocFromString($value);
		} else if (strpos($value, '{')) {
			$value = json_decode($value);
		} else {
			$value = $value;
		}
		
		if ( in_array( $store, $this->stores_with_cdh ) ) {
			
			if ( is_array( $value ) && isset( $value['cdh'] ) ) {
				
				$runtime_cdh = $this->getCookieDomainHash();
				$cdh_from_state = $value['cdh'];
				
				// return as the cdh's do not match
				if ( $cdh_from_state != $runtime_cdh ) {
					// cookie domains do not match so we need to delete the cookie in the offending domain
					// which is always likely to be a sub.domain.com and thus HTTP_HOST.
					// if ccokie is not deleted then new cookies set on .domain.com will never be seen by PHP
					// as only the sub domain cookies are available.
					owa_coreAPI::debug("Not loading state store: $store. Domain hashes do not match - runtime: $runtime_cdh, cookie: $cdh_from_state");
					owa_coreAPI::debug("deleting cookie: owa_$store");
					owa_coreAPI::deleteCookie($store,'/', $_SERVER['HTTP_HOST']);
					unset($this->initial_state[$store]);
					return;
				}
			} else {
				
				owa_coreAPI::debug("Not loading state store: $store. No domain hash found.");
				return;
				
			}
		}
	
		return $this->setState($store, $name, $value, $store_type);
		
	}
		
	function clear($store) {
	
		if ( ! isset($this->stores[$store] ) ) {
			$this->loadState($store);
		}
		
		if (array_key_exists($store, $this->stores)) {
			
			unset($this->stores[$store]);
			
			if ($this->stores_meta[$store]['type'] === 'cookie') {
			
				return owa_coreAPI::deleteCookie($store);	
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