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
 * Installation Finish
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

// needed??
class InstallFinish extends \owa_installController {

    function action() {

        // Persist install complete flag.
        $this->c->persistSetting('base', 'install_complete', true);
        $save_status = $this->c->save();

        if ($save_status == true) {
            $this->e->notice('Install Complete Flag added to configuration');
        } else {
            $this->e->notice('Could not persist Install Complete Flag to the Database');
        }

        $site = \owa_coreAPI::entityFactory('base.site');
        $site->getByPk('id', '1');
        $this->setView('base.install');
        $this->setSubview('base.installFinish');
        $this->set('site_id', $site->get('site_id'));
        $this->set('u', $this->getParam('u'));
        $this->set('p', $this->getParam('p'));
    }
}




?>
