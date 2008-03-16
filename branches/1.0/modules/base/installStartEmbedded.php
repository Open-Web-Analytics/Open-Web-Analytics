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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Installation Start Controller for Embedded Configurations
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installStartEmbeddedController extends owa_controller {


	function __construct($params) {
	
		$this->owa_controller($params);
		$this->priviledge_level = 'admin';

		return;
	}

	
	function owa_installEmbeddedController($params) {
	
		return $this->__construct($params);
	}
	
	function action() {
		
	    $api = &owa_coreAPI::singleton();
	
		$data['site_id'] = $this->params['site_id'];
		$data['name'] = $this->params['name'];
		$data['domain'] = $this->params['domain'];
		$data['description'] = $this->params['description'];
		$data['view'] = 'base.installStartEmbedded';
		
		return $data;
	}




}
/**
 * Installation Start View for Embedded Configurations
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installStartEmbeddedView extends owa_view {
	
	function owa_installStartEmbeddedView() {
		
		$this->owa_view();
		$this->priviledge_level = 'admin';
		
		return;
	}
	
	function construct() {
		
		
		// check for schema
		//$api = &owa_coreAPI::singleton();
		//$installer = $api->modules['base']->installerFactory();
		
		$this->t->set_template('wrapper_blank.tpl');
		
		
		if (!empty($this->config['install_complete'])):
			// load body template
			$this->body->set_template('install_schema_detected.tpl');
		else:
			// load body template
			$this->body->set_template('install_start_embedded.tpl');
		endif;
		
		//page title
		$this->t->set('page_title', 'Open Web Analytics Installation');
		
		// assign data		
		$this->body->set('headline', 'Shall we install Open Web Analytics?');
		$this->body->set('site_id', $data['site_id']);
		$this->body->set('domain', $data['domain']);
		$this->body->set('name', $data['name']);
		$this->body->set('description', $data['description']);
		
		return;
	}
	
	
}


?>