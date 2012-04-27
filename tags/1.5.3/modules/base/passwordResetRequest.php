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
	
	function __construct($params) {
		
		parent::__construct($params);
		
		$v1 = owa_coreAPI::validationFactory('entityExists');
		$v1->setConfig('entity', 'base.user');
		$v1->setConfig('column', 'email_address');
		$v1->setValues(trim($this->getParam('email_address')));
		$v1->setErrorMessage($this->getMsg(3010));
		$this->setValidation('email_address', $v1);
	}
	
	function action() {
				
		// Log password reset request to event queue
		$eq = &eventQueue::get_instance();
		
		$eq->log(array('email_address' => $this->getParam('email_address')), 'base.reset_password');
	
		// return view
		$this->setView('base.passwordResetForm');
		$email_address = trim($this->getParam('email_address'));
		$msg = $this->getMsg(2000, $email_address);
		$this->set('status_msg', $msg);	
							
		return;
	}
	
	function errorAction() {
	
		$this->setView('base.passwordResetForm');
		$this->set('error_msg', $this->getMsg(2001, $this->getParam('email_address')));
		return;
	}
	
	
	
}



?>