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

require_once(OWA_BASE_CLASS_DIR . 'cliController.php');

/**
 * Change user password cli Controller
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_changeUserPasswordCliController extends owa_cliController
{
    /**
     * @var owa_userManager
     */
    private $_userManager;

    /**
     * owa_changeUserPasswordCliController constructor.
     * @param $params
     */
    public function __construct($params)
    {
        parent::__construct($params);

        $this->setRequiredCapability('edit_settings');

        $this->_userManager = owa_coreApi::supportClassFactory('base', 'userManager');
    }

    public function validate()
    {
        $this->addValidation('user_required', $this->getParam('user'), 'required');
        $this->addValidation('password_required', $this->getParam('password'), 'required');

        $passwordLengthConf = [
            'operator'  => '>=',
            'length'    => 6,
            'errorMsg'  => 'Your password must be at least 6 characters in length.',
        ];

        $this->addValidation('password_length', $this->getParam('password'), 'required', $passwordLengthConf);
    }

    /**
     *
     */
    public function action()
    {
        $user = $this->getParam('user');
        $password = $this->getParam('password');

        $status = $this->_userManager->updateUserPassword([
            'user_id' => $user,
            'password' => $password,
        ]);

        if ($status !== false) {
            owa_coreAPI::notice( "Updated user password successfully." );
            return;
        }

        owa_coreAPI::notice( "User password update failed." );
    }

    public function errorAction()
    {
        $this->setView('base.changeUserPasswordCli');
        $this->set('msgs', $this->getParam('validation_errors'));
    }
}

require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Change user password cli View
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_changeUserPasswordCliView extends owa_cliView
{

}
?>