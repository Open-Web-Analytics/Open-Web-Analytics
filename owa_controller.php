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

require_once(OWA_BASE_DIR.'/owa_auth.php');


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
	 * Controller Type
	 *
	 * @var array
	 */
	var $type;
	
	/**
	 * Is the controller for an admin function
	 *
	 * @var boolean
	 */
	var $is_admin;
	
	/**
	 * The priviledge level required to access this controller
	 *
	 * @var string
	 */
	var $priviledge_level;
	
	/**
	 * The auth module to use for this controller
	 * This can be overriden by concrete controller classes
	 * otherwise value will be pulled from the base module's configuration
	 *
	 * @var string
	 */
	var $auth_module;
	
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
		
		// sets the auth module. requires a configuration object.
		$this->_setAuthModule();
		
		return;
		
	}
	
	/**
	 * Handles request from caller
	 *
	 */
	function doAction() {
		
		$this->e->debug('Performing Action: '.get_class($this));
		
		// check if the schema needs to be updated and force the update
		// not sure this should go here...
		if ($this->is_admin == true):
			// do not intercept if its the updatesApply action or else updates will never apply
			if ($this->params['do'] != 'base.updatesApply'):
				$api = &owa_coreAPI::singleton();
				
				if ($api->update_required == true):
					$this->e->debug('Updates Required. Redirecting action.');
					$data = array();
					$data['view_method'] = 'redirect';
					$data['action'] = 'base.updates';
					return $data;
				endif;
			endif;
		endif;		
		
		//perfrom authentication
		// TODO: make the auth module configurable by the controller
		$auth = &owa_auth::get_instance();
		
		$data = $auth->authenticateUser($this->priviledge_level);
		
		// if auth was success then procead
		if ($data['auth_status'] == true):
					
			// set status msg
			if (!empty($this->params['status_code'])):
				$this->data['status_msg'] = $this->getMsg($this->params['status_code']);
			endif;
			
			// get error msg from error code passed on the query string from a redirect.
			if (!empty($this->params['error_code'])):
				$this->data['error_msg'] = $this->getMsg($this->params['error_code']);
			endif;
			
			// check to see if the controlelr has created a validator
			if (!empty($this->v)):
				// if so do the validations required
				$this->v->doValidations();
				//check for erros
				if ($this->v->hasErrors == true):
					// if errors, do the errorAction instead of the normal action
					return $this->errorAction();
				else:
					return $this->action();
				endif;
				
			endif;
			
			return $this->action();
		else:
			 // return the not priviledged error view set by owa_auth.
			 // TODO: owa_auth should probably not know anything about a view
			return $data;

		endif;
		
		
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
	
	function isAdmin() {
		
		if ($this->is_admin == true):
			return true;
		else:
			return false;
		endif;
	
	}
	
	function _setAuthModule() {
	
		if (empty($this->auth_module)):
			$this->auth_module = $this->c->get('base', 'authentication');
		endif;
		
		return;
	
	}
	
}

?>