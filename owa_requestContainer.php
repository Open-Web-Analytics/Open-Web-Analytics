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
 * OWA Request Params
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_requestContainer {
	
	var $cli_args;
	var $is_https;
	var $owa_params;
	var $cookies;
	var $request;
	var $server;
	var $guid;
	
	/**
	 * Singleton returns request params
	 *
	 * @return array
	 * @todo DEPRICATED
	 */
	function &getInstance() {
		
		static $params;
		
		if(empty($params)):
			
			$params = owa_lib::getRequestParams();
			// translate certain request variables that are reserved in javascript
			$params = owa_lib::rekeyArray($params, array_flip(owa_coreAPI::getSetting('base', 'reserved_words')));
			
			$params['guid'] = $this->guid;
			
			return $params;
			
		else:
		
			return $params;
		
		endif;
		
	}
	
	function owa_requestContainer() {
	
		return $this->__construct();
	}
	
	function __construct() {
		
		$this->guid = crc32(microtime().getmypid());
		
		// CLI args
		if (array_key_exists('argv', $_SERVER)) {
			
			$this->cli_args = $_SERVER['argv'];
		}
		
		// php's server variables
		$this->server = $_SERVER;
		
		// files
		if (!empty($_FILES)) {
			$this->files = $_FILES;	
		}
		
		// cookies
		if (!empty($_COOKIE)) {
			$this->cookies = $_COOKIE;
		}
		
		// create request params from GET or POST or CLI args
		$params = array();
		
		if (!empty($_POST)):
			// get params from _POST
			$params = $_POST;
		elseif (!empty($_GET)):
			// get params from _GET
			$params = $_GET;
		elseif (!empty($this->cli_args)):
			// get params from the command line args
			// $argv is a php super global variable
			
			   for ($i=1; $i<count($this->cli_args);$i++)
			   {
				   $it = split("=",$this->cli_args[$i]);
				   $params[$it[0]] = $it[1];
			   }
		endif;
		
		// merge in cookies into the request params
		if (!empty($_COOKIE)) {
			$params = array_merge($params, $_COOKIE);
		}
		
		// Clean Input arrays
		$this->request = owa_lib::inputFilter($params);	
		// strip owa namespace
		$this->owa_params = owa_lib::stripParams($this->request);
		// translate certain request variables that are reserved in javascript
		$this->owa_params = owa_lib::rekeyArray($this->owa_params, array_flip(owa_coreAPI::getSetting('base', 'reserved_words')));
		
		if(isset($_SERVER['HTTPS'])):
			$this->is_https = true;
		endif;
			
		return;
	
	}
		
	function getParam($name) {
	
		return $this->owa_params[$name];
	}
	
	function setParam($name, $value) {
		
		$this->owa_params[$name] = $value;
		return true;
	}
	
	function getCookie($name) {
	
		return $this->cookies[$name];
	}
	
	function getRequestParam($name) {
	
		return $this->request[$name];
	}
	
	
}

?>