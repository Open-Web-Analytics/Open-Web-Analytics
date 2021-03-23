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

require_once(OWA_BASE_CLASS_DIR.'installController.php');

/**
 * Embedded Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_installEmbeddedController extends owa_installController {

    function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        return parent::__construct($params);

    }

    function action() {

        $service = owa_coreAPI::serviceSingleton();

        $this->e->notice('starting Embedded install');

        //create config file

        $this->c->createConfigFile($this->params);
        $this->c->applyConfigConstants();
        // install schema
        $base = $service->getModule('base');
        $status = $base->install();

        // schema was installed successfully
        if ($status === true) {

            //create admin user
            $cu = owa_coreAPI::getCurrentUser();
            $this->createAdminUser($cu->getUserData('user_id'), $cu->getUserData('email_address'));

            // create default site
            $this->createDefaultSite($this->getParam('domain'), $this->getParam('name'), $this->getParam('description'), $this->getParam('site_family'), $this->getParam('site_id'));

            // Persist install complete flag.
            $this->c->persistSetting('base', 'install_complete', true);
            $save_status = $this->c->save();

            if ($save_status === true) {
                $this->e->notice('Install Complete Flag added to configuration');
            } else {
                $this->e->notice('Could not persist Install Complete Flag to the Database');
            }

            $this->setView('base.installFinishEmbedded');

        // schema was not installed successfully
        } else {
            $this->e->notice('Aborting embedded install due to errors installing schema. Try dropping all OWA tables and try again.');
            return false;
        }
    }
}

?>