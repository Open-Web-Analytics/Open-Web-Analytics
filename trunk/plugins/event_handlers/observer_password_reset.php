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

require_once(OWA_BASE_DIR.'/owa_user.php');
require_once(OWA_BASE_DIR.'/owa_template.php');

/**
 * OWA user password reset Event handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class Log_observer_password_reset extends owa_observer {

	/**
	 * Email that mail should go to
	 *
	 * @var string
	 */
    var $_to;
    
    /**
     * Subject of email
     *
     * @var string
     */
    var $_subject;
    
	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @return 	Log_observer_announce
	 */
    function Log_observer_password_reset($priority, $conf) {
        
    	// Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer to listen for event types
		$this->_event_type = array('user.set_temp_passkey', 'user.reset_password', 'user.set_initial_passkey');
		
		return;
    }
	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
    	$this->m = $event['message'];

    	switch ($event['event_type']) {
    		case "user.set_temp_passkey":
    			$this->setTempPasskey();
    			break;
    		case "user.reset_password":
    			$this->resetPassword();
    			break;
    		case "user.set_initial_passkey":
    			$this->setInitialPasskey();
    			break;	
    	}
		
		return;
    }
    
    function sendMail($address, $subject, $msg) {
    	
    	mail($address, $subject, $msg);
					
		$this->e->debug('sending e-mail with subject of "'.$subject.'" to: '.$u->email_address);
    	
		return;
    }
    
    function setInitialPasskey() {
    	
    	$u = new owa_user;
		$u->getUserByPK($this->m['user_id']);
		$u->temp_passkey = md5($u->user_id.time().rand());
		$status = $u->update();
		
    	if ($status == true):
	
			$msg = new owa_template();
			
			$msg->set_template('email_new_account.tpl');
			$msg->set('user_id', $u->user_id);
			$msg->set('key', $u->temp_passkey);
			$email = $msg->fetch();
			
			//send mail
			$this->sendMail($u->email_address,
					"OWA Account Setup",
					$email);
					
		endif;
    	
    	return;
    }
    
	function setTempPasskey() {
		
		$u = new owa_user;
		$u->getUserByEmail($this->m['email_address']);
		$u->temp_passkey = md5($u->user_id.time().rand());
		$status = $u->update();
		
		// Create mail msg template
		if ($status == true):
	
			$msg = new owa_template();
			
			$msg->set_template('password_reset_request_email.tpl');
			$msg->set('key', $u->temp_passkey);
			$email = $msg->fetch();
			
			//send mail
			$this->sendMail($u->email_address,
					"Request for Password Reset",
					$email);
					
		endif;
		
		return;
		
	}
    
	function resetPassword() {
		
		$u = new owa_user;
		$u->getUserByTempPasskey($this->m['key']);
		$u->temp_passkey = '';
		$u->password = $this->m['password'];
		$status = $u->update();
		
		if ($status == true):
	
			$msg = new owa_template();
			
			$msg->set_template('password_reset_email.tpl');
			$msg->set('ip', $this->m['ip']);
			$email = $msg->fetch();
			
			//send mail
			
			mail($u->email_address,
					"Password Reset",
					$email);
					
			$this->e->debug('sending password reset mail to: '.$u->email_address);
		endif;
		
		
		return;
	}
}

?>
