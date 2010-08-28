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
 
 class owa_entityExistsValidation extends owa_validation {
 	
 	function __construct() {
 		
 		return parent::__construct();
 	}
 	
 	function validate() {
 		
 		$entity = owa_coreAPI::entityFactory($this->getConfig('entity'));
 		$entity->getByColumn($this->getConfig('column'), $this->getValues());
 		 		
 		$error = $this->getErrorMsg();
 		
 		if (empty($error)) {
 			$this->setErrorMessage('An entity with that value does not exist.');
 		}

		$id = $entity->get('id');
		
		// validation logic 
 		if (empty($id)) {
 			$this->hasError();
 		}	
					
 		return;
 		
 	}
 	 	
 }
 
 
?>