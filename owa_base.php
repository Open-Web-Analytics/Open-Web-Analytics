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

require_once('owa_env.php');

/**
 * OWA Base Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_base {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Error Logger
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Configuration Entity
	 * 
	 * @var owa_settings  Object global configuration object
	 */
	var $c;
	
	/**
	 * Module that this class belongs to
	 *
	 * @var unknown_type
	 */
	var $module;
	
	/**
	 * Request Params
	 *
	 * @var array
	 */
	var $params;
	
	/**
	 * Base Constructor
	 *
	 * @return owa_base
	 */	
	function __construct() {
		owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
		$this->e = owa_coreAPI::errorSingleton();
		$this->c = owa_coreAPI::configSingleton();
		$this->config = $this->c->fetch('base');
	}
	
	/**
	 * Retrieves string message from mesage file
	 *
	 * @param integer $code
	 * @param string $s1
	 * @param string $s2
	 * @param string $s3
	 * @param string $s4
	 * @return string
	 */
	function getMsg($code, $s1 = null, $s2 = null, $s3 = null, $s4 = null) {
		
		static $_owa_messages;
		
		if (empty($_owa_messages)) {
			
			require_once(OWA_DIR.'conf/messages.php');
		}
		
		switch ($_owa_messages[$code][1]) {
			
			case 0:
				$msg = $_owa_messages[$code][0];
				break;
			case 1:
				$msg = sprintf($_owa_messages[$code][0], $s1);
				break;
			case 2:
				$msg = sprintf($_owa_messages[$code][0], $s1, $s2);
				break;
			case 3:
				$msg = sprintf($_owa_messages[$code][0], $s1, $s2, $s3);
				break;
			case 4:
				$msg = sprintf($_owa_messages[$code][0], $s1, $s2, $s3, $s4);
				break;
		}
		
		return $msg;
		
	}

	/**
	 * Sets object attributes
	 *
	 * @param unknown_type $array
	 */
	function _setObjectValues($array) {
		
		foreach ($array as $n => $v) {
				
				$this->$n = $v;
		
			}
		
		return;
	}
	
	/**
	 * Sets array attributes
	 *
	 * @param unknown_type $array
	 */
	function _setArrayValues($array) {
		
		foreach ($array as $n => $v) {
				
				$this->params['$n'] = $v;
		
			}
		
		return;
	}
	
	function __destruct() {
		owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
	}
	
}

?>