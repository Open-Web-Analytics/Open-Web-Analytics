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

		$this->setRequiredCapability('edit_modules');
		return parent::__construct($params);
	}
	
	function action() {
		
		$this->set('site_id', $this->getParam('site_id'));
		$this->set('name', $this->getParam('name'));
		$this->set('domain', $this->getParam('domain'));
		$this->set('description', $this->getParam('description'));
		
		$this->set('db_type', $this->getParam('db_type'));
		$this->set('db_user', $this->getParam('db_user'));
		$this->set('db_password', $this->getParam('db_password'));
		$this->set('db_host', $this->getParam('db_host'));
		$this->set('db_name', $this->getParam('db_name'));
		$this->set('public_url', $this->getParam('public_url'));
		
		$this->setView('base.installStartEmbedded');
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
		
	function render() {
		
		$this->body->set_template('install_start_embedded.tpl');
		
		//page title
		$this->t->set_template('wrapper_public.tpl');
		$this->t->set('page_title', 'Open Web Analytics Installation');
		
		// assign data		
		$this->body->set('headline', 'Shall we install Open Web Analytics?');
		$this->body->set('site_id', $this->get('site_id'));
		$this->body->set('domain', $this->get('domain'));
		$this->body->set('name', $this->get('name'));
		$this->body->set('description', $this->get('description'));
		
		$this->body->set('db_type', $this->get('db_type'));
		$this->body->set('db_user', $this->get('db_user'));
		$this->body->set('db_password', $this->get('db_password'));
		$this->body->set('db_host', $this->get('db_host'));
		$this->body->set('db_name', $this->get('db_name'));
		$this->body->set('public_url', $this->get('public_url'));
	}
}

?>