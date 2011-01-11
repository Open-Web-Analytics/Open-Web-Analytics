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
	var $params = array();
	
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
	 * Capability
	 * 
	 * @var string
	 */
	var $capability;
	
	/**
	 * Available Views
	 * 
	 * @var Array
	 */
	var $available_views = array();
	
	/**
	 * Time period
	 * 
	 * @var Object
	 */
	var $period;
	
	/**
	 * Dom id
	 * 
	 * @var String
	 */
	var $dom_id;
	
	/**
	 * Flag for requiring authenciation before performing actions
	 * 
	 * @var Bool
	 */
	var $authenticate_user;
	
	var $state;
	
	/**
	 * Flag for requiring nonce before performing write actions
	 * 
	 * @var Bool
	 */
	var $is_nonce_required = false;
		
	/**
	 * Constructor
	 *
	 * @param array $params
	 */
	function __construct($params) {
	
		// call parent constructor to setup objects.
		parent::__construct();
		
		// set request params
		$this->params = $params;
		
		// set the default view method
		$this->setViewMethod('delegate');	
	}
	
	/**
	 * Handles request from caller
	 *
	 */
	function doAction() {
		
		owa_coreAPI::debug('Performing Action: '.get_class($this));
		
		// check if the schema needs to be updated and force the update
		// not sure this should go here...
		if ($this->is_admin === true) {
			// do not intercept if its the updatesApply action or a re-install else updates will never apply
			$do = $this->getParam('do');
			if ($do != 'base.updatesApply' && !defined('OWA_INSTALLING') && !defined('OWA_UPDATING')) {
				
				if (owa_coreAPI::isUpdateRequired()) {
					$this->e->debug('Updates Required. Redirecting action.');
					$data = array();
					$data['view_method'] = 'redirect';
					$data['action'] = 'base.updates';
					return $data;
				}
			}
		}
		
		
		/* Check validity of nonce */
		 
		if ($this->is_nonce_required == true) {
			$nonce = $this->getParam('nonce');
			
			if ($nonce) {
				$is_nonce_valid = $this->verifyNonce($nonce);
			}
			
			if (!$nonce || !$is_nonce_valid) {
				$this->e->debug('Nonce is not valid.');
				$ret = $this->notAuthenticatedAction();
				if (!empty($ret)) {
					$this->post();
					return $ret;
				} else {
					$this->post();
					return $this->data;
				}
			}
		}				
		
		/* CHECK USER FOR CAPABILITIES */
		if (!owa_coreAPI::isCurrentUserCapable($this->getRequiredCapability())) {
		
			owa_coreAPI::debug('User does not have capability required by this controller.');
			
			// check to see if the user has already been authenticated 
			if (owa_coreAPI::isCurrentUserAuthenticated()) {
				$this->authenticatedButNotCapableAction();
				return $this->data;
			}
			
			/* PERFORM AUTHENTICATION */	
			$auth = &owa_auth::get_instance();
			$status = $auth->authenticateUser();
			// if auth was not successful then return login view.
			if ($status['auth_status'] != true) {
				$this->notAuthenticatedAction();
				return $this->data;
			} else {
				//check for needed capability again now that they are authenticated
				if (!owa_coreAPI::isCurrentUserCapable($this->getRequiredCapability())) {
					$this->authenticatedButNotCapableAction();
					//needed?
					$this->set('go', urlencode(owa_lib::get_current_url()));
					// needed? -- set auth status for downstream views
					$this->set('auth_status', true);
					return $this->data;	
				}
			}
		}
		// TODO: These sets need to be removed and added to pre(), action() or post() methods 
		// in various concrete controller classes as they screw up things when 
		// redirecting from one controller to another.
		
		// set auth status for downstream views
		//$this->set('auth_status', true);
		//set request params
		$this->set('params', $this->params);
		// set site_id
		$this->set('site_id', $this->get('site_id'));
				
		// set status msg - NEEDED HERE? doesnt owa_ view handle this?
		if (array_key_exists('status_code', $this->params)) {
			$this->set('status_code', $this->getParam('status_code'));
		}
		
		// get error msg from error code passed on the query string from a redirect.
		if (array_key_exists('error_code', $this->params)) {
			$this->set('error_code', $this->getParam('error_code'));
		}
		 
		// check to see if the controller has created a validator
		if (!empty($this->v)) {
			// if so do the validations required
			$this->v->doValidations();
			//check for errors
			if ($this->v->hasErrors === true) {
				//print_r($this->v);
				// if errors, do the errorAction instead of the normal action
				$this->set('validation_errors', $this->getValidationErrorMsgs());
				$ret = $this->errorAction();
				if (!empty($ret)) {
					$this->post();
					return $ret;
				} else {
					$this->post();
					return $this->data;
				}
			}
		}
		
		
		/* PERFORM PRE ACTION */
		// often used by abstract descendant controllers to set various things
		$this->pre();
		
		/* PERFORM MAIN ACTION */
		// need to check ret for backwards compatability with older 
		// controllers that donot use $this->data
		$ret = $this->action();

		if (!empty($ret)) {
			$this->post();
			return $ret;
		} else {
			$this->post();
			return $this->data;
		}
		
	}
	
	function logEvent($event_type, $properties) {
		
		if (!class_exists('eventQueue')):
			require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'eventQueue.php');
		endif;
		
		$eq = &eventQueue::get_instance();
		
		if (!is_a($properties, 'owa_event')) {
	
			$event = owa_coreAPI::supportClassFactory('base', 'event');
			$event->setProperties($properties);
			$event->setEventType($event_type);
		} else {
			$event = $properties;
		}
		
		return $eq->log($event, $event->getEventType());
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
	
	function setValidation($name, $obj) {
	
		if (empty($this->v)):
			$this->createValidator();
		endif;
	
		return $this->v->setValidation($name, $obj);
		
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
	
	// depricated
	function _setCapability($capability) {
	
		$this->setRequiredCapability($capability);
		
		return;
	}
	
	function setRequiredCapability($capability) {
	
		$this->capability = $capability;
		return;
	}
		
	function getRequiredCapability() {
		
		return $this->capability;
	}
	
	function getParam($name) {
	
		if (array_key_exists($name, $this->params)) {
			return $this->params[$name];
		} else {
			return false;
		}
	}
	
	function setParam($name, $value) {
	
		$this->params[$name] = $value;
	}
	
	function isParam($name) {
	
		if (array_key_exists($name, $this->params)) {
			return true;
		} else {
			return false;
		}	
	}
	
	function get($name) {
		
		return $this->getParam($name);
	}
	
	function getAllParams() {
		
		return $this->params;
	}
	
	function pre() {
	
		return false;
	}
	
	function post() {
		return false;
	}
	
	function getPeriod() {
		
		return $this->period;
	}
	
	function setPeriod() {
	
	// set period 
	
		$period = $this->makeTimePeriod($this->getParam('period'), $this->params);
		
		$this->period = $period;
		$this->set('period', $this->getPeriod());	
		$this->data['params'] = array_merge($this->data['params'], $period->getPeriodProperties());
		return;
	}
	
	function makeTimePeriod($time_period, $params = array()) {
		
		return owa_coreAPI::makeTimePeriod($time_period, $params);
	}
	
	function setTimePeriod($period) {
		
		$this->period = $period;
		$this->set('period', $this->getPeriod());	
		//$this->data['params'] = array_merge($this->data['params'], $period->getPeriodProperties());
	}
	
		
	function setView($view) {
		$this->data['view'] = $view;
		return;
	}
	
	function setSubview($subview) {
		$this->data['subview'] = $subview;
		return;
	}
	
	function setViewMethod($method = 'delegate') {
		$this->data['view_method'] = $method;
		return;
	}
	
	function setRedirectAction($do) {
		$this->set('view_method', 'redirect');
		$this->set('do', $do);
		
		// need to remove these unsets once they are no longer set in the main doAction method
		if (array_key_exists('params', $this->data)) {
			unset($this->data['params']);
		}
		if (array_key_exists('site_id', $this->data)) {
		//	unset($this->data['site_id']);
		}
	}
	
	function setPagination($pagination, $name = 'pagination') {
		$this->data[$name] = $pagination;
		return;
	}
	
	function set($name, $value) {
	
		$this->data[$name] = $value;
		return;
	}
	
	function setControllerType($string) {
	
		$this->type = $string;
		return;
	}
	
	function mergeParams($array) {
	
		$this->params = array_merge($this->params, $array);
		return;
	}
	
	/**
	 * redirects borwser to a particular view
	 *
	 * @param unknown_type $data
	 */
	function redirectBrowser($action, $pass_params = true) {
		
		$control_params = array('view_method', 'auth_status');
		
		$get = '';
		
		$get .= owa_coreAPI::getSetting('base', 'ns').'do'.'='.$action.'&';
		
		if ($pass_params === true) {

			foreach ($this->data as $n => $v) {
				
				if (!in_array($n, $control_params)) {		
				
					$get .= owa_coreAPI::getSetting('base', 'ns').$n.'='.$v.'&';
				
				}
			}
		}
				
		$new_url = sprintf(owa_coreAPI::getSetting('base', 'link_template'), owa_coreAPI::getSetting('base', 'main_url'), $get);
		
		return owa_lib::redirectBrowser($new_url);
		
	}
	
	function redirectBrowserToUrl($url) {
		
		return owa_lib::redirectBrowser($url);
	}
	
	function setStatusCode($code) {
		
		$this->data['status_code'] = $code;
	}
	
	function setStatusMsg($msg) {
		
		$this->data['status_message'] = $msg;
	}
	
	function authenticatedButNotCapableAction() {
		
		$this->setView('base.error');
		$this->set('error_msg', $this->getMsg(2003));
	}
	
	function notAuthenticatedAction() {

		$this->setRedirectAction('base.loginForm');
		$this->set('go', urlencode(owa_lib::get_current_url()));
	}
	
	function verifyNonce($nonce) {
		
		$action = $this->getParam('do');
		
		if (!$action) {
			$action = $this->getParam('action');	
		}
		
		$matching_nonce = owa_coreAPI::createNonce($action);
		//owa_coreAPI::debug("passed nonce: $nonce | matching nonce: $matching_nonce");
		if ($nonce === $matching_nonce) {
			return true;
		}
	}
	
	/**
	 * Sets nonce flag for the controller.
	 */
	function setNonceRequired() {
		
		$this->is_nonce_required = true;
	}
	
	function getSetting($module, $name) {
		return owa_coreAPI::getSetting($module, $name);
	}
	
}

?>