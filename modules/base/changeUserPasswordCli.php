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

require(OWA_INCLUDE_DIR . 'jsmin-1.1.1.php');
require_once(OWA_BASE_CLASS_DIR . 'cliController.php');

/**
 * Build Controller
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
     * owa_changeUserPasswordCliController constructor.
     * @param $params
     */
    public function __construct($params)
    {
        parent::__construct($params);

        $this->setRequiredCapability('edit_settings');
    }

    /**
     *
     */
    public function action()
    {
        $user = $this->getParam('user');
        $password = $this->getParam('password');

        if (!$user) {
            owa_coreAPI::debug("No user given.");
            return;
        }

        if (!$password) {
            owa_coreAPI::debug("No password given.");
            return;
        }

        $u = owa_coreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $user);
        $u->set('password', $password);

        $status = $u->update();

        if ($status == true) {
            owa_coreAPI::debug( "Updated user password successfully." );
            return;
        }

        owa_coreAPI::debug( "User password update failed." );
	}
}


?>