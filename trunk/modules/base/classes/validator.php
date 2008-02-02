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
 * Data Validator Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
 
 class owa_validator extends owa_base {
 	
 	/**
 	 * Flag for whether or not a validation run produces errors
 	 * 
 	 * @var boolean
 	 */
 	var $hasErrors;
 	
 	/**
 	 * Error Msgs produced by Validations
 	 * 
 	 * @var array
 	 */
 	var $errorMsgs;
 	
 	/**
 	 * Validations to be performed in next validation run
 	 * 
 	 * @var array
 	 */
 	var $validations;
 	
 	/**
 	 * Validation objects
 	 * 
 	 * @var array
 	 */
 	var $validators;
 	
 	function owa_validator() {
 		
 		$this->owa_base;
 		
 		return;
 		
 	}
 	
 	/**
 	 * Adds a validation to be performed in next run
 	 * 
 	 * @param string	$name 		the name to be given to the validation and its results
 	 * @param unknown	$value		the data value that is to be validated
 	 * @param string 	$validation the name of the validation to run
 	 * @param array 	$conf 		configuration array for the object being created
 	 */
 	function addValidation($name, $value, $validation, $conf) {
		
		$this->validations[$name] = array('value' => $value, 'validation' => $validation, 'conf' => $conf);
		
		return;
		
	}
	
	/**
	 * Factory method for producing validation objects
	 * 
	 * @return Object
	 */
	function validationFactory($class_file, $conf) {
		
		if (!class_exists('owa_validation')):
			require_once(OWA_BASE_CLASS_DIR.'validation.php');
		endif;
		
		return owa_lib::factory(OWA_PLUGINS_DIR.'/validations', 'owa_', $class_file, $conf, 'Validation');
		
	}
	
	/**
	 * Performs a validation run
	 * 
	 */
	function doValidations() {
		
		foreach ($this->validations as $k => $v) {
			
			// Construct validatation obj
			$this->validators[$k] = $this->validationFactory($v['validation'], $v['conf']);
			
			$this->validators[$k]->validate($v['value']);
			
			if ($this->validators[$k]->hasError == true):
			
				$this->hasErrors = true;
				$this->errorMsgs[$k] = $this->validators[$k]->errorMsg;
				
				if ($this->validators[$k]->conf['stopOnError'] == true):
					break;
				endif;
				
			endif;
		}
	}
	
	/**
	 * Check to see if the validation run was successful.
	 * 
	 * @return boolean
	 */
	function isValid() {
		
		if ($this->hasErrors == true):
			return false;
		else:
			return true;
		endif;
	}
	
	/**
	 * Accessor method for retrieving the error msgs produced by a validation run
	 * 
	 * @return array
	 */
	function getErrorMsgs() {
		
		return $this->errorMsgs;
	}
 	
	
 }
 
?>