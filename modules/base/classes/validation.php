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
 * Abstract Validation Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
 
 class owa_validation {
 	
 	// hold config
 	var $conf;
 	
 	// hold values to validate
 	var $values;
 	
 	var $hasError;
 	
 	var $errorMsg;
 	
 	var $errorMsgTemplate;
 	
 	function __construct($conf = array()) {
 	
 		if (array_key_exists('errorMsgTemplate', $conf)):
 			$this->errorMsgTemplate = $conf['errorMsgTemplate'];
 		endif;
 	
 	}
 	
 	function validate() {
 		
 		return false;
 	}
 	
 	function getErrorMsg() {
 		
 		return $this->errorMsg;
 	}
 	
 	function setErrorMsgTemplate($string) {
 		
 		$this->errorMsgTemplate = $string;
 		
 		return;
 	}
 	
 	// depricated
 	function setErrorMsg($msg) {
 		
 		$this->errorMsg = $msg;
 		$this->hasError = true;
 		
 		return;
 		
 	}
 	
 	function setErrorMessage($msg) {
 		$this->errorMsg = $msg;	
 	}
 	
 	function isValid() {
 		
 		if ($this->hasError == true):
 			return false;
 		else:
 			return true;
 		endif;
 	}
 	
 	function setConfig($name, $value) {
 		
 		$this->conf[$name] = $value;
 		return;
 	}
 	
 	function setConfigArray($array) {
 		
 		$this->conf = $array;
 		return;
 	}
 	
 	function getConfig($name) {
 		
 		if (isset( $this->conf[$name] ) ) {
 			return $this->conf[$name];
 		}
 	}
 	
 	function setValues($values) {
 		
 		$this->values = $values;
 		return;
 	}
 	
 	function getValues() {
 	
 		return $this->values;
 		
 	}
 	
 	function hasError() {
 		
 		$this->hasError = true;
 		return;
 	}
 	
 	
 }
 
?>