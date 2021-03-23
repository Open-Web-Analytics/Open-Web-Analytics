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
 * Reset Password Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.0.0
 */

class owa_usersResetPasswordController extends owa_controller {
    
    function __construct($params) {
    
        return parent::__construct($params);
    }
    
    function action() {
    
        $event = $this->getParam('event');
        
        $auth = owa_auth::get_instance();
        $u = owa_coreAPI::entityFactory('base.user');
        $u->getByColumn('email_address', $event->get('email_address'));
        $u->set('temp_passkey', $auth->generateTempPasskey($u->get('user_id')));
        $status = $u->update();
        $this->e->debug('status: '.$status);
        
        if ($status === true) {
    
            $this->setView( 'base.usersResetPassword' );
            $this->set( 'key', $u->get('temp_passkey' ) );
            $this->set( 'email_address', $u->get('email_address' ) );
            
        } else {
        
            $this->e->debug( "could not update password in db." );    
        }
    }
    
}

/**
 * Reset Password Notification View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.0.0
 */

class owa_usersResetPasswordView extends owa_mailView {
    
    function render($data) {
        
        $this->t->set_template('wrapper_email.tpl');
        $this->body->set_template('users_reset_password_email.tpl');
        $this->body->set('key', $this->get('key'));
        $this->setMailSubject('Your New OWA Password');    
        $this->addMailToAddress($this->get('email_address'));     
    }
}


?>