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
 * Updates Application Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UpdatesApply extends \OWA\Core\Controller {

    /**
     * DELIBERATELY has no required capability and no nonce, for the same reason
     * the sibling base.updates has none: the schema has to be brought forward
     * before the rest of the application can be relied on, and the authentication
     * path is part of what may be waiting on it.
     *
     * This action WAS gated. Gating it made the documented upgrade unusable for a
     * signed-out admin, which is what #979 reported. base.updates renders
     * anonymously, so its Apply link carries a nonce minted with no user_id;
     * createNonce() binds to user_id, so that nonce can never verify once the
     * admin signs in. The request was turned away, the browser returned to the
     * login form, and correct credentials appeared to be rejected.
     *
     * WordPress takes the same position for the equivalent step: wp-admin/upgrade.php
     * loads wp-load.php rather than admin.php and calls wp_upgrade() with no
     * capability check and no nonce.
     *
     * What that gives up is a crafted link inducing an update. The exposure is
     * small: the work is idempotent, does nothing unless the schema is behind,
     * and produces only the change the administrator was already being told to
     * make. Weighed against an upgrade path that cannot be completed from the
     * browser, that is the better trade.
     *
     * `php cli.php cmd=update` (base.updatesApplyCli) remains the route for
     * updates that cannot run over the web; updates 005/006/007/010 require it.
     */
    function __construct($params) {

        parent::__construct($params);
    }

    function action() {

        // fetch list of modules that require updates
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $modules = $s->getModulesNeedingUpdates();
        //print_r($modules);
        //return;

        // foreach do update in order

        $error = false;
        // Set alongside $error inside the loop below, so at runtime it is always
        // defined by the time it is read. Initialised anyway: the correlation is
        // not visible to a reader (or to static analysis) at the point of use.
        $cli_update_required = false;

        foreach ($modules as $k => $v) {

            $ret = $s->modules[$v]->update();

            if ($ret != true):
                $error = true;
                // if there is an error check to see if it's because the cli update mode is required
                $cli_update_required = $s->modules[$v]->isCliUpdateModeRequired();
                break;
            endif;

        }

        if ($error === true) {

            if($cli_update_required) {
                $this->set('error_msg', $this->getMsg(3311));
            } else {
                $this->set('error_msg', $this->getMsg(3307));
            }

            $this->setView('base.error');
            $this->setViewMethod('delegate');
        } else {

            // add data to container
            $this->set('status_code', 3308);
            $this->set('do', 'base.optionsGeneral');
            $this->setViewMethod('redirect');
        }
    }
}

?>