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
 * Cache Facade Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


require_once(OWA_BASE_CLASS_DIR.'cache.php');

class owa_cacheFacade extends owa_base {

	
	var $cache;
	
	function __construct($cache_dir) {
		
		// make this plugable soon
		$this->cache = &owa_coreAPI::supportClassFactory('base', 'cache');
		
		if (!empty($cache_dir)):
			$this->setCacheDir($cache_dir);
		endif;
		
		return;
	}
	
	function owa_cacheFacade($cache_dir) {
		
		return $this->__construct($cache_dir);
	
	}
	
	function get($collection, $key) {
	
		return $this->cache->get($collection, $key);
	
	}
	
	function set($collection, $key, $value) {
	
		return $this->cache->set($collection, $key, $value);
	
	}

	function remove($collection, $key) {
		
		return $this->cache->remove($collection, $key);
	}
	
	function replace($collection, $key, $value) {
		
		return $this->cache->replace($collection, $key, $value);
		
	}
	
	function setCacheDir($dir) {
	
		return $this->cache->setCacheDir($dir);
	}
	
	function flush() {
	
		return $this->cache->flush();
	}
	
	function debug($msg) {
		
		return $this->e->debug($msg);
		
	}
	
	function setNonPersistantCollection($collection) {
	
		return $this->cache->setNonPersistantCollection($collection);
	
	}
	
	function setGlobalCollection($collection) {
		
		return $this->cache->setGloballCollection($collection);

	}
	
	
	function error($msg) {
	
		return $this->e->debug($msg);
	}
	
	
	
}

?>