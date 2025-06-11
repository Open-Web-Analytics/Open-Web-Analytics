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

require_once(OWA_DIR.'owa_view.php');
require_once(OWA_DIR.'owa_adminController.php');

/**
 * Goals Edit Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_optionsGoalEditController extends owa_adminController {

    function __construct($params) {

        parent::__construct($params);
        $this->type = 'options';
        $this->setRequiredCapability('edit_settings');
        $this->setNonceRequired();

        $goal = $this->getParam('goal');

        foreach ($goal['details']['funnel_steps'] as $num => $step) {
            $check = owa_lib::array_values_assoc($step);
            if (!empty($check)) {
                $step['step_number'] = $num;
                $this->params['goal']['details']['funnel_steps'][$num] = $step;
            } else {
                // remove the array as it only contains empty values.
                // this can happen when the use adds a step but does not fill in any
                // values.
                unset( $this->params['goal']['details']['funnel_steps'][$num] );
            }
        }
    }

    public function validate()
    {
        $goal = $this->getParam('goal');

        // check that goal number is present
        $this->addValidation('goal_number', $goal['goal_number'], 'required');

        // check that goal status is present
        $this->addValidation('goal_status', $goal['goal_status'], 'required');

        // check that goal status is present
        $this->addValidation('goal_group', $goal['goal_group'], 'required');

        // check that goal type is present
        $this->addValidation('goal_type', $goal['goal_type'], 'required');

        if ($goal['goal_type'] === 'url_destination') {
            // check that match_type is present
            $this->addValidation('match_type', $goal['details']['match_type'], 'required');

            // check that goal_url is present
            $this->addValidation('goal_url', $goal['details']['goal_url'], 'required');
        }

        if (isset($goal['details']['funnel_steps'])) {
            return;
        }

        foreach ($goal['details']['funnel_steps'] as $num => $step) {
            if (empty($step['name']) || empty($step['url'])) {
                return;
            }

            // check that step name is present
            $this->addValidation('step_name_'.$num, $step['name'], 'required');

            // check that step url is present
            $this->addValidation('step_url_'.$num, $step['url'], 'required');
        }
    }

    function action() {

        // setup goal manager
        $siteId = $this->get('siteId');
        $gm = owa_coreAPI::supportClassFactory('base', 'goalManager', $siteId);
        $goal = $this->getParam('goal');
        //$all_goals = owa_coreAPI::getSiteSetting($site_id, 'goals');
        //$goal_groups = owa_coreAPI::getSiteSetting($site_id, 'goal_groups');
        $gm->saveGoal($goal['goal_number'], $goal);

        if ( $this->get( 'new_goal_group_name' ) ) {
            $gm->saveGoalGroupLabel($goal['goal_group'], $this->get( 'new_goal_group_name' ) );
            //$goal_groups[$goal['goal_group']] = $this->get( 'new_goal_group_name' );
        }

        owa_coreAPI::debug('New goals: '.print_r($gm->goals,true));
        $this->setStatusCode(2504);
        $this->set('siteId', $siteId);
        $this->setRedirectAction('base.optionsGoals');
    }

    function errorAction() {
        $goal = $this->getParam('goal');
        $this->setView('base.options');
        $this->setSubview('base.optionsGoalEntry');
        $this->set('error_code', 3311);
        $this->set('goal', $goal);
        $this->set('goal_number', $goal['goal_number']);
        $siteId = $this->get('siteId');
        $gm = owa_coreAPI::supportClassFactory('base', 'goalManager', $siteId);
        $this->set('goal_groups', $gm->getAllGoalGroupLabels() );
    }
}

?>