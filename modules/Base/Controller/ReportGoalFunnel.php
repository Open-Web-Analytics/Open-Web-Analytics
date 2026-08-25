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
 * Goal Funnel Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ReportGoalFunnel extends \OWA\Core\ReportController {

    function action() {

        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $this->getParam( 'siteId' ) );

        $goal_number = $this->getParam('goalNumber');

        if ( ! $goal_number ) {
            $goal_number = 1;
        }

        $goal = $gm->getGoal($goal_number);
        $funnel = $gm->getGoalFunnel($goal_number);

        if ( $funnel ) {
            $goal = $gm->getGoal($goal_number);
            // find required steps. build a constraint string.
            $required_step_constraints = '';
            $steps_count = count($funnel);
            for ($i=1; $i <= $steps_count ;$i++ ) {

                if (array_key_exists('is_required', $funnel[$i]) && $funnel[$i]['is_required'] === true) {

                    $required_step_constraints .= 'pagePath=='.$funnel[$i]['path'].',';
                }
            }
            $required_step_constraints = trim($required_step_constraints, ',');

            //print $required_step_constraints;
            // get total visits
            $total_visitors_rs = \OWA\Core\CoreAPI::executeApiCommand(array(
	            
	            	'request_method'	=> 'GET',
					'module'			=> 'base',
					'version'			=> 'v1',
		            'do'                => 'reports',
                    'period'       => $this->get('period'),
                    'startDate'      => $this->get('startDate'),
                    'endDate'      => $this->get('endDate'),
                    'constraints' => $required_step_constraints,
                    'metrics'       => 'visitors',
                    'siteId'      => $this->getParam( 'siteId' )
            ));
            //print_r($total_visitors_rs);
            // The aggregate is an object, and this is the denominator of the
            // conversion rate at the end of the method -- the same mistake as
            // the per-step count below, just further from where it surfaces.
            $total_visitors = isset( $total_visitors_rs->aggregates->visitors->value )
                ? (int) $total_visitors_rs->aggregates->visitors->value
                : 0;
            //print "Total visits: $total_visitors";

            $this->set( 'total_visitors',  $total_visitors);
            // get visits for each step

            // add goal url to steps array
            // Keyed `path` like every other element: the loop below constrains on
            // $step['path'], and the stored steps carry that key since the rename.
            // Built with 'url' it was the one element the loop could not read.
            $funnel[] = array('path' => $goal['details']['goal_url'], 'name' => $goal['goal_name'], 'step_number' => $steps_count + 1);
            foreach ( $funnel as $k => $step ) {
                $operator = '==';
                $rs = \OWA\Core\CoreAPI::executeApiCommand(array(
	                
	                	'request_method'	=> 'GET',
						'module'			=> 'base',
						'version'			=> 'v1',
			            'do'                => 'reports',
                        'period'       => $this->get('period'),
                        'startDate'      => $this->get('startDate'),
                        'endDate'      => $this->get('endDate'),
                        'metrics'       => 'visitors',
                        'constraints' => 'pagePath'.$operator.$step['path'],
                        'siteId'      => $this->getParam( 'siteId' )
                ));

                /*
                 * `$$rs` -- a double dollar -- was a variable variable: PHP
                 * evaluated $rs (a stdClass), used it as a variable NAME, and
                 * fatalled with "Object of class stdClass could not be
                 * converted to string". So this report returned a 500 for any
                 * goal that actually had a funnel, which is why nothing caught
                 * it: no install had ever configured one.
                 *
                 * The aggregate is an object -- {value, formatted_value, ...} --
                 * so the count has to come off `value`, and as an int: the
                 * comparison and division below are arithmetic, and the template
                 * prints it.
                 */
                $visitors = isset( $rs->aggregates->visitors->value )
                    ? (int) $rs->aggregates->visitors->value
                    : 0;
                $funnel[$k]['visitors'] = $visitors;

                // backfill check in case there are more visitors to this step than were at prior step.
                if ($funnel[$k]['visitors'] <= $funnel[$k-1]['visitors']) {
                    if ($funnel[$k-1]['visitors'] > 0 ) {
                        $funnel[$k]['visitor_percentage'] = round($funnel[$k]['visitors'] / $funnel[$k-1]['visitors'], 4) * 100 . '%';
                    } else {
                        $funnel[$k]['visitor_percentage'] = '0.00%';
                    }
                } else {
                    $funnel[$k]['visitor_percentage'] = '100%';
                }
            }

            //print_r($funnel);

            $goal_step = end($funnel);
            // A site with no visitors in the period is not an error; it is a
            // funnel nobody entered.
            $goal_conversion_rate = $total_visitors > 0
                ? round( $goal_step['visitors'] / $total_visitors, 2 ) * 100 . '%'
                : '0%';
            $this->set('goal_conversion_rate', $goal_conversion_rate);
            $this->set('funnel', $funnel);

        }
        // set view stuff
        $this->setSubview('base.reportGoalFunnel');
        $this->setTitle('Funnel Visualization:', 'Goal ' . $goal_number);
        $this->set('goal_number', $goal_number);
    }
}




?>
