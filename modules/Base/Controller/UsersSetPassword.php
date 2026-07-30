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
 * New user Account Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UsersSetPassword extends \OWA\Core\Controller {

    function __construct($params) {

        return parent::__construct($params);
    }

    function action() {

        $event = $this->getParam('event');

        /**
         * @var $userManager \owa_userManager
         */
        $userManager = \OWA\Core\CoreAPI::supportClassFactory('base', 'userManager');
        $u = $userManager->updateUserPassword([
            'temp_passkey' => $event->get('key'),
            'password' => $event->get('password'),
            'user_id'  => $event->get('user_id')
        ]);
        // needed for migration away from old embedded install model
        \OWA\Core\CoreAPI::debug('setting migration flag...'. \OWA\Core\CoreAPI::getSetting('base', 'is_embedded') );
        if ( $u && \OWA\Core\CoreAPI::getSetting('base', 'is_embedded') ) {
				\OWA\Core\CoreAPI::debug('setting migration flag...');	
	        	\OWA\Core\CoreAPI::setSetting('base', 'is_embedded_admin_user_password_reset', true, true);
		}

        if ($u !== false) {
            $data['view'] = 'base.usersSetPassword';
            $data['view_method'] = 'email';
            $data['ip'] = $event->get('ip');
            $data['subject'] = 'Password Change Complete';
            $data['email_address'] = $u->get('email_address');
            
           
        }
        
        return $data;
    }

}



?>
