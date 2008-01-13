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
 * Password Reset Request View 
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_passwordResetRequestView extends owa_view {
	
	function owa_passwordResetRequest($params) {
		
		$this->owa_view($params);
		
		return;
	}
	
	function construct($data) {
		
		$this->body->set_template('users_password_reset_request.tpl');// This is the inner template
		$this->body->set('headline', 'Type in the email address that is associated with your user account.');
		$this->body->set('u', $this->params['u']);
		
		return;
	}
	
}


/**
 * Password Reset Request Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_passwordResetRequestController extends owa_controller {
	
	function owa_passwordResetRequestController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	
		return;
	}
	
	function doAction() {
		
		// Check to see if this email exists in the db
		$u = new owa_user;
		$u->getUserByEmail($this->params['email_address']);
		
		$data = array();
			
		// If user exists then fire event and return view
		if (!empty($u->user_id)):
			
			// Log password reset request to event queue
			$eq = &eventQueue::get_instance();
			$eq->log(array('user_id' => $u->user_id), 'base.reset_password');
		
			// return view
			$data['view'] = 'base.passwordResetRequest';
			$data['view_method'] = 'delegate';
			$data['status_msg'] = $this->getMsg(2000, $this->params['email_address']);	
			
		// if user does not exists just return view with error
		else:
			$data['view'] = 'base.passwordResetRequest';
			$data['view_method'] = 'delegate';
			$data['error_msg'] = $this->getMsg(2001, $this->params['email_address']);
		endif;
		
		return $data;
	}
}



?>