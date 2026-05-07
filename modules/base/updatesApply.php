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

require_once(OWA_DIR.'owa_controller.php');

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

class owa_updatesApplyController extends owa_controller {

    function action() {

        // fetch list of modules that require updates
        $s = owa_coreAPI::serviceSingleton();

        $modules = $s->getModulesNeedingUpdates();
        //print_r($modules);
        //return;

        // foreach do update in order

        $error = false;

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