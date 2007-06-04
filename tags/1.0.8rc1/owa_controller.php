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

require_once('owa_base.php');


/**
 * Abstract Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_controller extends owa_base {
	
	/**
	 * Request Parameters passed in from caller
	 *
	 * @var array
	 */
	var $params;
	
	/**
	 * The priviledge level required to access this controller
	 *
	 * @var string
	 */
	var $priviledge_level;
	
	/**
	 * data validation control object
	 * 
	 * @var Object
	 */
	var $v;
	
	/**
	 * Data container
	 * 
	 * @var Array
	 */
	var $data = array();
	
	/**
	 * Constructor
	 *
	 * @param array $params
	 * @return owa_controller
	 */
	function owa_controller($params) {
		
		$this->owa_base();
		$this->params = $params;
		
		return;
		
	}
	
	/**
	 * Handles request from caller
	 *
	 */
	function doAction() {
		
		$this->e->debug('Performing Action: '.get_class($this));
		
		// set status msg
		if (!empty($this->params['status_code'])):
			$this->data['status_msg'] = $this->getMsg($this->params['status_code']);
		endif;
		
		// get error msg from error code passed on the query string from a redirect.
		if (!empty($this->params['error_code'])):
			$this->data['error_msg'] = $this->getMsg($this->params['error_code']);
		endif;
		
		if (!empty($this->v)):
		
			$this->v->doValidations();
			
			if ($this->v->hasErrors == true):
				
				return $this->errorAction();
			
			else:
				
				return $this->action();
				
			endif;
			
		endif;
		
		return $this->action();
		
	}
	
	function logEvent($event_type, $properties) {
		
		if (!class_exists('eventQueue')):
			require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'eventQueue.php');
		endif;
		
		$eq = &eventQueue::get_instance();
		return $eq->log($properties, $event_type);
	}
	
	function createValidator() {
		
		$this->v = owa_coreAPI::supportClassFactory('base', 'validator');
		
		return;
		
	}
	
	function addValidation($name, $value, $validation, $conf = array()) {
	
		if (empty($this->v)):
			$this->createValidator();
		endif;
	
		return $this->v->addValidation($name, $value, $validation, $conf);
		
	}
	
	function getValidationErrorMsgs() {
		
		return $this->v->getErrorMsgs();
		
	}
	
}

?>