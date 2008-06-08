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
	
	var $php_self;
	var $cli_args;
	var $gateway_interface;
	var $server_ip_address;
	var $server_name;
	var $server_software;
	var $server_protocol;
	var $server_document_root;
	var $request_method;
	var $request_time;
	var $query_string;
	var $is_https;
	var $remote_ip_address;
	var $remote_host;
	var $remote_port;
	var $path_translated;
	var $script_filename;
	var $request_uri;
	var $server_auth_user;
	var $server_auth_password;
	var $auth_type;
	var $owa_params;
	var $cookies;
	var $request;
	var $guid;
	
	/**
	 * Request Params
	 * @depricated
	 * @var array
	 */
	var $params;
	

	
	/**
	 * Singleton returns request params
	 *
	 * @return array
	 * @todo DEPRICATED
	 */
	function & getInstance() {
		
		static $params;
		
		if(!isset($params)):
			
			$params = owa_lib::getRequestParams();
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
	
		$this->php_self = $_SERVER['PHP_SELF'];
		$this->cli_args = $_SERVER['argv'];
		$this->gateway_interface = $_SERVER['GATEWAY_INTERFACE'];
		$this->server_ip_address = $_SERVER['SERVER_ADDR'];
		$this->server_name = $_SERVER['SERVER_NAME'];
		$this->server_software = $_SERVER['SERVER_SOFTWARE'];
		$this->server_protocol = $_SERVER['SERVER_PROTOCOL'];
		$this->server_document_root = $_SERVER['DOCUMENT_ROOT'];
		$this->server_auth_user = $_SERVER['PHP_AUTH_USER'];
		$this->server_auth_password = $_SERVER['PHP_AUTH_PW'];
		$this->server_auth_type = $_SERVER['AUTH_TYPE'];
		$this->server_path_translated = $_SERVER['PATH_TRANSLATED'];
		$this->request_method = $_SERVER['REQUEST_METHOD'];
		$this->request_time = $_SERVER['REQUEST_TIME'];
		$this->query_string = $_SERVER['QUERY_STRING'];
		$this->http_accept = $_SERVER['HTTP_ACCEPT'];
		$this->http_accept_charset = $_SERVER['HTTP_ACCEPT_CHARSET'];
		$this->http_accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'];
		$this->http_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
		$this->http_connection = $_SERVER['HTTP_CONNECTION'];
		$this->http_host = $_SERVER['HTTP_HOST'];
		$this->http_referer = $_SERVER['HTTP_REFERER'];
		$this->http_user_agent = $_SERVER['HTTP_USER_AGENT'];
		$this->remote_ip_address = $_SERVER['REMOTE_ADDR'];
		$this->remote_host = $_SERVER['REMOTE_HOST'];
		$this->remote_port = $_SERVER['REMOTE_PORT'];
		$this->script_filename = $_SERVER['SCRIPT_FILENAME'];
		$this->request_uri = $_SERVER['REQUEST_URI'];
	
		$this->cookies = $_COOKIE;
		$this->request = owa_lib::inputFilter($_REQUEST);
		$this->owa_params = owa_lib::stripParams($this->request);
		$this->guid = crc32(microtime().getmypid());
		
		if(isset($_SERVER['HTTPS'])):
			$this->is_https = true;
		endif;
			
		return;
	
	}
	
	function get($name) {
	
		return $this->$name;
	
	}
	
	function set($name, $value) {
		
		$this->$name = $value;
		return true;
	}
	
	function getOwaParam($name) {
	
		return $this->owa_params[$name];
	}
	
	function setOwaParam($name, $value) {
		
		$this->owa_params[$name] = $value;
		return true;
	}
	
	function getCookie($name) {
	
		return $this->cookies[$name];
	}
	
	function getHeader($name) {
	
		return $this->headers['name'];
	}
	
	function getRequestParam($name) {
	
		return $this->request[$name];
	}
	
	
}

?>