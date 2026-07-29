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
class ChangeUserPasswordCli extends \OWA\Core\Controller\Cli
{
    /**
     * @var \owa_userManager
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

        $this->_userManager = \OWA\Core\CoreAPI::supportClassFactory('base', 'userManager');
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

        // 'stringLength' (not 'required') is the validator that actually reads
        // the operator/length config above; typed as 'required' the length rule
        // silently degraded to a bare non-empty check and short passwords passed.
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', $passwordLengthConf);
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
            \OWA\Core\CoreAPI::notice( "Updated user password successfully." );
            return;
        }

        \OWA\Core\CoreAPI::notice( "User password update failed." );
    }

    public function errorAction()
    {
        $this->setView('base.changeUserPasswordCli');
        $this->set('msgs', $this->getParam('validation_errors'));
    }
}



?>
