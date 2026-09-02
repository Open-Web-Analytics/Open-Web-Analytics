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

class OptionsGoalEdit extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);
        $this->type = 'options';
        $this->setRequiredCapability('edit_settings');
        $this->setNonceRequired();

        $goal = $this->getParam('goal');

        /*
         * Only a funnel goal has steps, and most goals are not funnel goals, so
         * the key is legitimately absent far more often than it is present.
         * Iterating it unguarded warned on every ordinary goal save -- invisible
         * on an install that does not surface warnings, and a hard failure under
         * the suite's failOnWarning.
         */
        $funnel_steps = $goal['details']['funnel_steps'] ?? null;

        foreach ( is_array( $funnel_steps ) ? $funnel_steps : array() as $num => $step ) {
            $check = \OWA\Core\Lib::array_values_assoc($step);
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

        /*
         * A renamed goal group must be given an actual name.
         *
         * The field is optional -- leaving it empty keeps the group's default
         * label -- but a name of nothing but spaces is not "no rename", it is a
         * blank label. Every goal group with an active goal becomes a metric-set
         * tab on every tabbed report, so a blank name is an unlabelled tab
         * across the whole reporting UI.
         */
        $new_group_name = (string) $this->getParam('new_goal_group_name');

        if ( $new_group_name !== '' && trim( $new_group_name ) === '' ) {

            $this->addValidation('new_goal_group_name', '', 'required');
        }

        if ($goal['goal_type'] === 'url_destination') {
            // check that match_type is present
            $this->addValidation('match_type', $goal['details']['match_type'], 'required');

            // check that goal_url is present
            $this->addValidation('goal_url', $goal['details']['goal_url'], 'required');
        }

        /*
         * Inverted: this returned when the steps WERE present and fell through
         * into the loop when they were not. So a funnel goal returned before
         * its steps were ever checked -- the two validations below have been
         * unreachable -- and an ordinary goal, which has no steps at all,
         * iterated null and warned.
         *
         * Only a funnel goal has steps, so having none is the normal case and
         * simply means there is nothing further to validate.
         */
        if ( ! isset( $goal['details']['funnel_steps'] )
            || ! is_array( $goal['details']['funnel_steps'] ) ) {

            return;
        }

        foreach ($goal['details']['funnel_steps'] as $num => $step) {

            /*
             * A step with nothing in it is a row the user added and left alone,
             * not a mistake. The constructor drops those -- but it cannot be
             * relied on here, because Controller::__construct calls validate()
             * BEFORE the subclass constructor body runs, so this always sees
             * the raw submission. Same rule, applied where the decision is made.
             */
            if ( ! \OWA\Core\Lib::array_values_assoc( (array) $step ) ) {

                continue;
            }

            /*
             * Anything else has at least one value, so a missing name or url is
             * a HALF-FILLED step -- a mistake worth reporting.
             *
             * This used to `return` on one, which did three wrong things at
             * once: it accepted the half-filled step silently, it skipped every
             * later step, and it abandoned the rest of validate() with them.
             * The two checks below were unreachable because of it, and
             * tautological when they did run -- `required` on values the guard
             * had already proven non-empty. They mean something now.
             */
            $this->addValidation('step_name_'.$num, $step['name'] ?? '', 'required');
            $this->addValidation('step_path_'.$num, $step['path'] ?? '', 'required');

            /*
             * A path, not a URL, and said so rather than left to fail quietly.
             *
             * Every consumer treats this as a path: the funnel report builds
             * `pagePath == <this>` and checkGoalStart matches it against the
             * event's page_uri. A full URL therefore matches nothing -- the
             * funnel reports zero and the goal never starts, with nothing
             * logged. The field used to be LABELLED "Step URL", which invited
             * exactly that.
             *
             * Refused rather than silently trimmed to its path: quietly
             * rewriting what someone typed is how they end up not knowing what
             * is stored.
             */
            if ( isset( $step['path'] ) && preg_match( '~^[a-z][a-z0-9+.\-]*://~i', (string) $step['path'] ) ) {

                $this->addValidation( 'step_path_'.$num, '', 'required', array(
                    'errorMsg' => sprintf(
                        'Step %s: enter the page PATH, such as /basket -- not a full web address. '
                        . 'Funnel steps are matched on the path alone.', $num ),
                ) );
            }
        }
    }

    function action() {

        // setup goal manager
        $siteId = $this->get('siteId');
        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);
        $goal = $this->getParam('goal');
        //$all_goals = owa_coreAPI::getSiteSetting($site_id, 'goals');
        //$goal_groups = owa_coreAPI::getSiteSetting($site_id, 'goal_groups');
        $gm->saveGoal($goal['goal_number'], $goal);

        /*
         * Trimmed, and tested for length rather than truthiness: a group named
         * "0" is falsy in PHP, so the old check discarded that rename silently
         * and the label appeared not to save.
         */
        $new_group_name = trim( (string) $this->get( 'new_goal_group_name' ) );

        if ( $new_group_name !== '' ) {

            $gm->saveGoalGroupLabel( $goal['goal_group'], $new_group_name );
        }

        \OWA\Core\CoreAPI::debug('New goals: '.print_r($gm->goals,true));
        $this->setStatusCode(2504);
        $this->set('siteId', $siteId);
        $this->setRedirectAction('base.optionsGoals');
    }

    function errorAction() {
        $goal = $this->getParam('goal');
        /*
         * The hierarchy wrapper. There is one settings nav now -- the old
         * base.options menu is gone -- so every settings screen carries the tile
         * and the tier groups, module screens included.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->setView('base.optionsHierarchy');
        $this->setSubview('base.optionsGoalEntry');
        $this->set('error_code', 3311);
        $this->set('goal', $goal);
        $this->set('goal_number', $goal['goal_number']);
        $siteId = $this->get('siteId');
        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);
        $this->set('goal_groups', $gm->getAllGoalGroupLabels() );
    }
}

?>