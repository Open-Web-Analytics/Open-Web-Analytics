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

require_once(OWA_BASE_CLASSES_DIR.'owa_adminController.php');

/**
 * Abstract Report Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_reportController extends owa_adminController {
	
	/**
	 * Constructor
	 *
	 * @param array $params
	 * @return
	 */
	function __construct($params) {	
		$this->setControllerType('report');
		$this->setRequiredCapability('view_reports');
		return parent::__construct($params);
	}
	
	
	/**
	 * pre action
	 *
	 */
	function pre() {
		
		$this->set('sites', $this->getSitesAllowedForCurrentUser());
		
		$this->setParam('siteId', $this->getCurrentSiteId());
		
		// pass full set of params to view
		$this->data['params'] = $this->params;
				
		// set default period if necessary
		if ( ! $this->getParam( 'period' ) && ! $this->getParam( 'startDate' ) ) {
			$this->set('is_default_period', true);
			$period = 'last_seven_days';
			$this->params['period'] = $period;
		} elseif (  ! $this->getParam( 'period' ) &&  $this->getParam( 'startDate' ) ) {
			$period = 'date_range';
			$this->params['period'] = $period;
		} else {
			$period = $this->getParam('period');
		}
		
		$this->setPeriod($period);
		
		$this->setView('base.report');
		$this->setViewMethod('delegate');
		
		$this->dom_id = str_replace('.', '-', $this->getParam('do'));
		$this->data['dom_id'] = $this->dom_id;
		$this->data['do'] = $this->getParam('do');
		
		// setup tabs
		$siteId = $this->get('siteId');
		$gm = owa_coreAPI::supportClassFactory('base', 'goalManager', $siteId);
		
		$tabs = array();
		$site_usage = array(
				'tab_label'		=> 'Site Usage',
				'metrics'		=> 'visits,pagesPerVisit,visitDuration,bounceRate,uniqueVisitors',
				'sort'			=> 'visits-',
				'trendchartmetric'	=>	'visits'
		);
		
		$tabs['site_usage'] = $site_usage;
		
		// ecommerce tab
		if ( owa_coreAPI::getSiteSetting( $this->getParam('siteId'), 'enableEcommerceReporting') ) {
		
			$ecommerce = array(
					'tab_label'		=> 'e-commerce',
					'metrics'		=> 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
					'sort'			=> 'transactionRevenue-',
					'trendchartmetric'	=>	'transactions'
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
						'tab_label'		=>	$gm->getGoalGroupLabel($group),
						'metrics'		=>	$goal_metrics,
						'sort'			=> 'goalValueAll-',
						'trendchartmetric'	=>	'visits'
				);
				$name = 'goal_group_'.$group;
				$tabs[$name] = $goal_group;
			}
		}
				
		$this->set('tabs', $tabs);
		$this->set('tabs_json', json_encode($tabs));
		
		
		//$this->body->set('sub_nav', owa_coreAPI::getNavigation($this->get('nav_tab'), 'sub_nav'));
		$nav = owa_coreAPI::getGroupNavigation('Reports');
		
		if ( ! owa_coreAPI::getSiteSetting( $this->getParam( 'siteId' ), 'enableEcommerceReporting' ) ) {
		
			unset($nav['Ecommerce']);
		}
		
		$this->set('top_level_report_nav', $nav);		
		$this->set('currentSiteId', $this->getCurrentSiteId());
		
	}
	
	function post() {
		
		return;
	}
	
	function setTitle($title, $suffix = '') {
		
		$this->set('title', $title);
		$this->set('titleSuffix', $suffix);
	}
	
	/**
	 * Override owa_controller method - in order to always get a valid site id if the user has at least access to one site
	 * @return string
	 */
	protected function getCurrentSiteId() {
		$site_id =  parent::getCurrentSiteId();
		// if there is no site_id o nthis request then pick one from
		// the alowed sites list for this user.
		if ( ! $site_id ) {		
			$allowedSites = $this->getSitesAllowedForCurrentUser();
			if ( current($allowedSites) instanceof owa_site) {
				//set default
				$site_id =  current($allowedSites)->get('site_id');
			}
		}
		return $site_id;
	}
}

?>