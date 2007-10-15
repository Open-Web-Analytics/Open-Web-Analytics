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

class owa_cacheFacade extends owa_cache {

	var $e;

	function __construct($cache_dir) {
	
		$this->cache_dir = $cache_dir;
		$this->e = &owa_error::get_instance();
		
	
		return;
	
	}

	function owa_cacheFacade($cache_dir) {
		
		register_shutdown_function(array(&$this, "__destruct"));
		return $this->__construct($cache_dir);
	
	}
	
	function debug($msg) {
		
		return $this->e->debug($msg);
		
	}
	
	function error($msg) {
	
		return $this->e->debug($msg);
	}
	
	
	
}

?>