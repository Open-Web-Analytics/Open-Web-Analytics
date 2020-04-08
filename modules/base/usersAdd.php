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

require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Add User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersAddController extends owa_adminController {

    function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // Check for user with the same email address
        // this is needed or else the change password feature will not know which account
        // to chane the password for.
        $userEmailAddressEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'email_address',
            'errorMsg'  => $this->getMsg(3009)
        ];

        $this->addValidation('email_address', trim($this->getParam('email_address')), 'entityDoesNotExist', $userEmailAddressEntityConf);

        // Check user name.
        $userEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'user_id',
            'errorMsg'  => $this->getMsg(3001)
        ];

        $this->addValidation('user_id', $this->getParam('user_id'), 'entityDoesNotExist', $userEntityConf);
    }

    function action() {

        $userManager = owa_coreApi::supportClassFactory('base', 'userManager');


        $user_params = array( 'user_id'         => trim($this->params['user_id']),
                              'real_name'         => $this->params['real_name'],
                              'role'            => $this->params['role'],
                              'email_address'     => trim($this->params['email_address']));

        $temp_passkey = $userManager->createNewUser($user_params);

        // log account creation event to event queue
        $ed = owa_coreAPI::getEventDispatch();
        $ed->log(array( 'user_id'     => $this->params['user_id'],
                        'real_name' => $this->params['real_name'],
                        'role'         => $this->params['role'],
                        'email_address' => $this->params['email_address'],
                        'temp_passkey' => $temp_passkey),
                        'base.new_user_account');

        $this->setRedirectAction('base.users');
        $this->set('status_code', 3000);
    }

    function errorAction() {
        $this->setView('base.options');
        $this->setSubview('base.usersProfile');
        $this->set('error_code', 3009);
        //assign original form data so the user does not have to re-enter the data
        $this->set('profile', $this->params);
    }
}

?>