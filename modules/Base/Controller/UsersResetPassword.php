<?php
namespace OWA\Module\Base\Controller;


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

class UsersResetPassword extends \OWA\Core\Controller {
    
    function __construct($params) {
    
        return parent::__construct($params);
    }
    
    function action() {
    
        $event = $this->getParam('event');
        
        $auth = \OWA\Core\Auth::get_instance();
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('email_address', $event->get('email_address'));

        /*
         * An address with no account reaches here now, because the request form
         * deliberately no longer reports whether one exists. Stop here rather
         * than mint a passkey for nobody and run an UPDATE keyed on an empty id.
         *
         * Nothing is reported back either: the caller shows the same message
         * whether or not this found anyone, which is the point.
         */
        if ( ! $u->get('id') ) {

            $this->e->debug( 'Password reset requested for an address with no account.' );

            return;
        }

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




?>
