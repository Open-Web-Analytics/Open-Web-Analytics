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
 * Sub String Position Validation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
 
 class owa_subStringPositionValidation extends owa_validation {
 	
 	var $position;
 	
 	var $subString;
 	
 	var $operator;
 	
 	function owa_subStringPositionValidation($conf) {
 		
 		$this->subString = $conf['substring'];
 		$this->position = $conf['position'];
 		$this->operator = $conf['operator'];
 		$this->setErrorMsgTemplate('The string "%s" was found within the value at position %d');
 		$this->owa_validation($conf);
 		return;
 	}
 	
 	function validate($value) {
 		
 		$pos = strpos($value, $this->subString);
 		
 		//print $pos;
 		//print_r($this);
 		switch ($this->operator) {
 			
 			case "=":
 				
 				if ($pos === $this->position):
 					$valid = true;
 				endif;
 				
 			break;
 			
 			case "!=":
 				
 				if ($pos === $this->position):
 					$valid = false;
 				endif;
 			
 			break;
 		}
 		
 		if ($valid === false):
 			$this->setErrorMsg(sprintf($this->errorMsgTemplate, $this->subString, $pos));
 			return false;
 		else:
 			return true;
 		endif;
 		
 		
 	}
 	
 }
 
 
?>
 