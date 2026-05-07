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
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersEditController extends owa_adminController {

    function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // check that user_id is present
        $this->addValidation('user_id', $this->getParam('user_id'), 'required');

        // Check user name exists
        $userEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'user_id',
            'errorMsg'  => $this->getMsg(3001)
        ];

        $this->addValidation('user_id', $this->getParam('user_id'), 'entityExists', $userEntityConf);
    }

    function action() {

        // This needs form validation in a bad way.

        $u = owa_coreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $this->getParam('user_id'));
        $u->set('email_address', $this->getParam('email_address'));

        // never change the role of the admin user
        if (!$u->isOWAAdmin()) {
            $u->set('real_name', $this->getParam('real_name'));
            $u->set('role', $this->getParam('role'));
        }


        $u->update();
        $this->set('status_code', 3003);
        $this->setRedirectAction('base.users');
    }

    function errorAction() {

        $this->setView('base.options');
        $this->setSubview('base.usersProfile');
        $this->set('error_code', 3311);
        $this->set('user', $this->params);
    }

}


?>