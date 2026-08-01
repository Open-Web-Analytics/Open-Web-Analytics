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

    function __construct($params) {

        // Applying schema updates is an admin operation. Without a declared
        // capability the base controller performs no authentication at all,
        // which left this action reachable unauthenticated.
        //
        // 'install_schema' would NOT work here -- it is granted to the
        // "everyone" role, so isEveryoneCapable() short-circuits the check.
        //
        // Note the sibling base.updates is deliberately NOT gated: it is the
        // target of the pre-auth update interception. Gating only this action
        // keeps that notice reachable while requiring an admin to actually
        // mutate the schema.
        //
        // If a future update ever breaks authentication itself (new code
        // querying a column an old schema lacks), the web path here cannot
        // complete. That is recoverable, not a lockout: `php cli.php cmd=update`
        // (base.updatesApplyCli, registered in Base/Module.php) applies updates
        // without web auth, and updates 005/006/007/010 already require it.
        $this->setRequiredCapability('edit_modules');

        // Capability alone still allowed a logged-in admin to be induced into
        // applying updates via a crafted link (CSRF). The "Apply updates" link
        // in base.updates now carries a nonce (makeLink's 5th arg).
        //
        // Ordering matters and is in our favour: doAction() runs the capability
        // check BEFORE the nonce check, so an unauthenticated visitor is sent to
        // login rather than failing on a nonce. And because createNonce() binds
        // to user_id, the nonce minted for an anonymous view of base.updates
        // will not verify -- after logging in the interception re-renders the
        // page, minting one bound to the real user.
        $this->setNonceRequired();

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