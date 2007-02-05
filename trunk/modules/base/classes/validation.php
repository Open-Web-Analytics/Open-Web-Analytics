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
 
 class owa_validation extends owa_base {
 	
 	var $hasError;
 	
 	var $errorMsg;
 	
 	var $errorMsgTemplate;
 	
 	function owa_validation($conf) {
 		
 		$this->owa_base();
 		
 		if (!empty($conf['errorMsgTemplate'])):
 			$this->errorMsgTemplate = $conf['errorMsgTemplate'];
 		endif;
 		
 		return;
 	}
 	
 	function validate($value) {
 		
 		return false;
 	}
 	
 	function getErrorMsg() {
 		
 		return $this->errorMsg;
 	}
 	
 	function setErrorMsgTemplate($string) {
 		
 		$this->errorMsgTemplate = $string;
 		
 		return;
 	}
 	
 	function setErrorMsg($msg) {
 		
 		$this->errorMsg = $msg;
 		$this->hasError = true;
 		
 		return;
 		
 	}
 	
 	function isValid() {
 		
 		if ($this->hasError == true):
 			return false;
 		else:
 			return true;
 		endif;
 	}
 	
 	
 	
 	
 }
 
?>
 