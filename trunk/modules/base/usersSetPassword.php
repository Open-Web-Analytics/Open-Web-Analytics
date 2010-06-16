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
require_once(OWA_BASE_DIR.'/owa_auth.php');

/**
 * New user Account Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersSetPasswordController extends owa_controller {
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
		
		$event = $this->getParam('event');
		
		$u = owa_coreAPI::entityFactory('base.user');
		$u->getByColumn('temp_passkey', $event->get('key'));
		$u->set('temp_passkey', '');
		$u->set('password', $event->get('password'));
		$status = $u->update();
		
		if ($status == true):
	
			$data['view'] = 'base.usersSetPassword';
			$data['view_method'] = 'email';
			$data['ip'] = $event->get('ip');
			$data['subject'] = 'Password Change Complete';
			$data['email_address'] = $u->get('email_address');
			
		endif;
		
		return $data;
	}
	
}

/**
 * Set Password Notification View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersSetPasswordView extends owa_view {
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		$this->t->set_template('wrapper_email.tpl');
		$this->body->set_template('users_set_password_email.tpl');
		$this->body->set('ip', $data['ip']);
	}
}

?>