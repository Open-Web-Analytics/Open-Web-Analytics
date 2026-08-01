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
 * Goals Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ReportGoals extends \OWA\Core\ReportController {

    function action() {

        $this->setSubview('base.reportGoals');
        $this->setTitle('Goals');
        $this->set('metrics', 'visits,goalCompletionsAll,goalConversionRateAll,goalAbandonRateAll,goalValueAll');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.goalCompletionsAll.formatted_value *> goals completed.');
        $this->set('trendChartMetric', 'goalCompletionsAll');

        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $this->getParam( 'siteId' ) );
        $goals = $gm->getActiveGoals();

        // Read by set() after the branch, so it must exist even when a site has
        // no active goals.
        $goal_metrics = '';

        if ($goals) {
            $goal_count = count($goals);
            $i = 1;
            foreach ($goals as $goal) {
                $goal_metrics .= 'goal'.$goal['goal_number'].'Completions';

                if ($i < $goal_count) {
                        $goal_metrics .= ',';
                }
                $i++;
            }
        }
        $this->set('goal_metrics', $goal_metrics);
    }
}



?>
