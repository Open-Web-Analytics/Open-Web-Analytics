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

require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Notify New Session Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_notifyNewSessionController extends owa_controller {
	
	function __construct($params) {
		
		$this->priviledge_level = 'guest';
		return parent::__construct($params);
	}
	
	function action() {
		
		// Control logic
		
		$s = owa_coreAPI::entityFactory('base.site');
		
		$s->getByPk('site_id', $this->params['site_id']);
		
		$data['site'] = $s->_getProperties();

		$data['email_address']= $this->config['notice_email'];
		$data['session'] = $this->params;
		$data['subject'] = sprintf('OWA: New Visit to %s', $s->get('domain'));
		$data['view'] = 'base.notifyNewSession';
		$data['plainTextView'] = 'base.notifyNewSessionPlainText';
		$data['view_method'] = 'email-html';
			
		return $data;
			
	}
	
	
}


/**
 * New Session Notification View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_notifyNewSessionView extends owa_view {
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		$this->t->set_template('wrapper_email.tpl');
		$this->body->set_template('new_session_email.tpl');
		$this->body->set('site', $data['site']);
		$this->body->set('session', $data['session']);
	}
}

?>