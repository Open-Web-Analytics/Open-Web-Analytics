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
class InstallFinish extends \OWA\Core\Controller\Install {

    /**
     * Writes nothing, and shows nothing of the install.
     *
     * It set the install-complete flag, on a GET with no nonce: the flag is set
     * by base.installBase, in the same transaction as the admin and the first
     * site (InstallManager::finishInstall()), and base.installBase renders the
     * finish screen itself. An installed site never gets here
     * (Core\Controller\Install::doAction() redirects); anyone else is sent to
     * the start.
     */
    public function action() {

        $this->setRedirectAction( 'base.installStart' );
    }
}




?>
