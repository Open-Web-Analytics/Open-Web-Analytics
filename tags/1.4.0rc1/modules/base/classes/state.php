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
			if (array_key_exists($store, $this->stores) && !is_array($this->stores[$store])) {
				$this->stores[$store] = array($name => $value);
			} else {
				$this->stores[$store][$name] = $value;
			}
			
		}
		
		$this->dirty_stores[] = $store;
		//owa_coreAPI::debug(print_r($this->stores, true));
	}

	
	function set($store, $name = '', $value, $store_type = '', $is_perminent = false) {
		
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
			
			//owa_coreAPI::debug('perm: '.$this->stores_meta[$store]['is_perminent']);	
			owa_coreAPI::createCookie($store, $this->stores[$store], $time, "/", owa_coreAPI::getSetting('base', 'cookie_domain'));
		}	
	}
	
	function loadState($store, $name = '', $value, $store_type) {
		
		return $this->setState($store, $name, $value, $store_type);
		
	}
		
	function clear($store) {
		
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
}


?>