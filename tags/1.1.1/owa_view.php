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

require_once('owa_template.php');
require_once('owa_base.php');
require_once('owa_requestContainer.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_coreAPI.php');

/**
 * Abstract View Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_view extends owa_base {

	/**
	 * Main view template object
	 *
	 * @var object
	 */
	var $t;
	
	/**
	 * Body content template object
	 *
	 * @var object
	 */
	var $body;
	
	/**
	 * Sub View object
	 *
	 * @var object
	 */
	var $subview;
	
	/**
	 * Rednered subview
	 *
	 * @var string
	 */
	var $subview_rendered;
	
	/**
	 * CSS file for main template
	 *
	 * @var unknown_type
	 */
	var $css_file;
	
	/**
	 * The priviledge level required to access this view
	 *
	 * @var string
	 */
	var $priviledge_level;
	
	/**
	 * Type of page
	 *
	 * @var unknown_type
	 */
	var $page_type;
	
	/**
	 * Request Params
	 *
	 * @var unknown_type
	 */
	var $params;
	
	/**
	 * Authorization object
	 *
	 * @var object
	 */
	var $auth;
	
	var $module; // set by factory.
	
	var $data;
	
	var $default_subview;
	
	var $is_subview;
	
	/**
	 * Constructor
	 *
	 * @return owa_view
	 */
	function owa_view($params = null) {
		
		$this->owa_base();
		$this->auth = & owa_auth::get_instance();
		$this->t = new owa_template();
		$this->body = new owa_template($this->module);
		$this->setTheme();
		return;
	}
	
	/**
	 * Assembles the view using passed model objects
	 *
	 * @param unknown_type $data
	 * @return unknown
	 */
	function assembleView($data) {
		
		$this->data = $data;
		
		// set view name in template class. used for navigation.
		$this->body->caller_params['view'] = $this->data['view'];
		$this->body->caller_params['subview'] = $this->data['subview'];
		
		if (!empty($this->data['nav_tab'])):
			$this->body->caller_params['nav_tab'] = $this->data['nav_tab'];
		endif;
		
		$this->e->debug('Assembling view: '.get_class($this));
		
		// auth user
		$auth_data = $this->auth->authenticateUser($this->priviledge_level);		
		
		// Assign status msg
		if (!empty($data['status_msg'])):
			$this->t->set('status_msg', $data['status_msg']);
		endif;
		
		// get status msg from code passed on the query string from a redirect.
		if (!empty($data['status_code'])):
			$this->t->set('status_msg', $this->getMsg($data['status_code']));
		endif;
		
		// set error msg directly if passed from constructor
		$this->t->set('error_msg', $data['error_msg']);
		
		//print_r($this->data);
		// authentication status
		if ($auth_data['auth_status'] == true):
			$this->t->set('authStatus', true);
		endif;
		
		// get error msg from error code passed on the query string from a redirect.
		if (!empty($data['error_code'])):
			$this->t->set('error_msg', $this->getMsg($data['error_code']));
		endif;
		
		// load subview
		if (!empty($this->data['subview']) || !empty($this->default_subview)):
			// Load subview
			$this->loadSubView($this->data['subview']);
		endif;
		
		// construct main view.  This might set some properties of the subview.
		$this->construct($this->data);
		
		//array of errors usually used for field validations
		$this->body->set('validation_errors', $data['validation_errors']);
			
			
		// assemble subview
		if (!empty($this->data['subview'])):
		
			// set view name in template. used for navigation.
			$this->subview->body->caller_params['view'] = $this->data['subview'];
			
			// Set validation errors
			$this->subview->body->set('validation_errors', $data['validation_errors']);
			
			// Load subview 
			$this->renderSubView($this->data);
			
			// assign subview to body template
			$this->body->set('subview', $this->subview_rendered);
		endif;
		
		if (!empty($data['validation_errors'])):
			$ves = new owa_template('base');
			$ves->set_template('error_validation_summary.tpl');
			$ves->set('validation_errors', $data['validation_errors']);
			$validation_errors_summary = $ves->fetch();
			$this->t->set('error_msg', $validation_errors_summary);
		endif;		
		
		//Assign body to main template
		$this->t->set('body', $this->body);
		
		// Return fully asembled View
		return $this->t->fetch();
		
	}
	
	/**
	 * Sets the theme to be used by a view
	 *
	 */
	function setTheme() {
		
		$this->t->set_template($this->config['report_wrapper']);
		
		return;
	}
	
	/**
	 * Abstract method for assembling a view
	 *
	 * @param array $data
	 */
	function construct($data) {
		
		return;
		
	}
	
	/**
	 * Assembles subview
	 *
	 * @param array $data
	 */
	function loadSubView($subview) {
		
		if (empty($subview)):
			if (!empty($this->default_subview)):
				$subview = $this->default_subview;
				$this->data['subview'] = $this->default_subview;
			else:
				return $this->e->debug("No Subview was specified by caller.");
			endif;
		endif;
	
		$this->subview = owa_coreAPI::subViewFactory($subview);
		
		return;
		
	}
	
	/**
	 * Assembles subview
	 *
	 * @param array $data
	 */
	function renderSubView($data) {
		
		// Stores subview as string into $this->subview
		$this->subview_rendered = $this->subview->assembleSubView($data);
	
		return;
		
	}
	
	/**
	 * Assembles the view using passed model objects
	 *
	 * @param unknown_type $data
	 * @return unknown
	 */
	function assembleSubView($data) {
		
		// auth user
		$auth_data = $this->auth->authenticateUser($this->priviledge_level);		
		
		// if auth was success then procead to assemble view.
		if ($auth_data['auth_status'] == true):
	
			// construct view
			$this->construct($data);
			
			$this->t->set_template('wrapper_subview.tpl');
			
			//Assign body to main template
			$this->t->set('body', $this->body);
	
			// Return fully asembled View
			$page =  $this->t->fetch();
		
			return $page;
			
		else: 
			//$this->e->debug('RenderView: '.print_r($data, true));
			$api = &owa_coreAPI::singleton();
			
			$subview = $api->displaySubView($auth_data);
			
			return $subview;
		endif;
		
		
	}
	
	
	/**
	 * Sets the Priviledge Level required to access this view
	 *
	 * @param string $level
	 */
	function _setPriviledgeLevel($level) {
		
		$this->priviledge_level = $level;
		
		return;
	}
	
	/**
	 * Sets the page type of this view. Used for tracking.
	 *
	 * @param string $page_type
	 */
	function _setPageType($page_type) {
		
		$this->page_type = $page_type;
		
		return;
	}
	
	function _setLinkState() {
		
		// create state params for all links
		$link_params = array(
								'period'	=> $this->data['params']['period'], // could be set by setPeriod
								'day'		=> $this->data['params']['day'],
								'month'		=> $this->data['params']['month'],
								'year'		=> $this->data['params']['year'],
								'day2'		=> $this->data['params']['day2'],
								'month2'	=> $this->data['params']['month2'],
								'year2'		=> $This->data['params']['year2'],
								'site_id'	=> $this->data['params']['site_id']								
							);		
							
		$this->body->caller_params['link_state'] =  $link_params;
		
		if(!empty($this->subview)):
			$this->subview->body->caller_params['link_state'] =  $link_params;
		endif;
		
		return;
	}
	
}

?>