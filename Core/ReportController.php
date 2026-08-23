<?php
namespace OWA\Core;


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
 * Abstract Report Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class ReportController extends \OWA\Core\AdminController {
	
	/**
	 * An array of request param keys that
	 * should be passed downstream as state
	 */
	var $state_keys = [];
	
    /**
     * Constructor
     *
     * @param array $params
     * @return
     */
    function __construct($params) {
        $this->setControllerType('report');
        $this->setRequiredCapability('view_reports');
        parent::__construct($params);

        // set a siteId is none is set on the request params
        $siteId = $this->getCurrentSiteId();

        if ( ! $siteId ) {
            //$siteId = $this->getDefaultSiteId();
        }

        $this->setParam( 'siteId', $siteId );
    }

    /**
     * Pre Action
     * Current user is fully authenticated and loaded by this point
     *
     */
    function pre() {

        $sites = $this->getSitesAllowedForCurrentUser();
        $this->set('sites', $sites);

        $this->set( 'currentSiteId', $this->getParam('siteId') );

        // pass full set of params to view
        $this->data['params'] = $this->params;

        // setup the time period object in $this->period
        $this->setPeriod();
        // check to see if the period is a default period. TODO move this ot view where needed.
        $this->set('is_default_period', $this->period->isDefaultPeriod() );
        $this->setView('base.report');
        $this->setViewMethod('delegate');

        /*
         * Derived from the report's identity, not from the action that reached
         * it.
         *
         * Every report used to have its own action, so hyphenating `do` gave
         * each one a distinct container id -- base-reportPages,
         * base-reportEntryPages. Reaching them all through base.report collapses
         * that to the single value "base-report" for every report on the
         * installation.
         *
         * Nothing keys persistence off dom_id today, so nothing is broken by the
         * collision right now. It is still worth not introducing: the id is what
         * OWA.report binds to and what anything remembering per-report UI state
         * would naturally key on, and a collision that costs nothing today is
         * the kind that costs a confusing afternoon later.
         *
         * The direct route is unchanged -- there is no reportId on it -- so this
         * adds a distinct id for the new route rather than altering the old one.
         */
        $reportId = $this->getParam('reportId');

        $this->dom_id = $reportId
            ? 'report-' . str_replace( array( '.', '_' ), '-', (string) $reportId )
            : str_replace('.', '-', (string) $this->getParam('do'));
        $this->data['dom_id'] = $this->dom_id;
        $this->data['do'] = $this->getParam('do');
        $nav = \OWA\Core\CoreAPI::getGroupNavigation('Reports');
        
        // setup tabs
        $siteId = $this->get('siteId');
        $tabs = array();

        if ( $siteId ) {
            $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);

            $site_usage = array(
                    'tab_label'        => 'Site Usage',
                    'metrics'        => 'visits,pagesPerVisit,visitDuration,bounceRate,uniqueVisitors',
                    'sort'            => 'visits-',
                    'trendchartmetric'    =>    'visits'
            );

            $tabs['site_usage'] = $site_usage;

            // ecommerce tab
            if ( \OWA\Core\CoreAPI::getSiteSetting( $this->getParam('siteId'), 'enableEcommerceReporting') ) {

                $ecommerce = array(
                        'tab_label'        => 'e-commerce',
                        'metrics'        => 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
                        'sort'            => 'transactionRevenue-',
                        'trendchartmetric'    =>    'transactions'
                );

                $tabs['ecommerce'] = $ecommerce;
            }
            $goal_groups = $gm->getActiveGoalGroups();

            if ( $goal_groups ) {
                foreach ($goal_groups as $group) {
                    $goal_metrics = 'visits';
                    $active_goals = $gm->getActiveGoalsByGroup($group);

                    if ( $active_goals ) {

                        foreach ($active_goals as $goal) {
                            $goal_metrics .= sprintf(',goal%sCompletions', $goal);
                        }
                    }

                    $goal_metrics .= ',goalValueAll';
                    $goal_group = array(
                            'tab_label'        =>    $gm->getGoalGroupLabel($group),
                            'metrics'        =>    $goal_metrics,
                            'sort'            => 'goalValueAll-',
                            'trendchartmetric'    =>    'visits'
                    );
                    $name = 'goal_group_'.$group;
                    $tabs[$name] = $goal_group;
                }
            }

            if ( ! \OWA\Core\CoreAPI::getSiteSetting( $this->getParam( 'siteId' ), 'enableEcommerceReporting' ) ) {

                unset($nav['Ecommerce']);
            }
        }

        $this->set('tabs', $tabs);
        $this->set('tabs_json', json_encode($tabs));
        $this->set('top_level_report_nav', $nav);
    }

    function post() {
		
		// pass the state_keys var to views
        $this->set( 'state_keys', $this->state_keys );
    }
    
    /**
	 * Used to designate a request param as state for
     * use by downstream views and templates
     */
    function addState( $param_name ) {
	    
	    $this->state_keys[] = $param_name;
    }

    function setTitle($title, $suffix = '') {

        $this->set('title', $title);
        $this->set('titleSuffix', $suffix);
    }

    /**
     * Chooses a siteId from a list of AllowedSites
     *
     * needed jsut in case a siteId is not passed on the request.
     * @return string
     */
    protected function getDefaultSiteId() {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->select('site_id');
        $db->from('owa_site');
        $db->limit(1);
        $ret = $db->getOneRow();

        return $ret['site_id'];
    }

    protected function hideReportingNavigation() {

        $this->set('hideReportingNavigation', true);
    }

    protected function hideSitesFilter() {

        $this->set('hideSitesFilter', true);
    }
}

?>