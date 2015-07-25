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
		
		$this->dom_id = str_replace('.', '-', $this->getParam('do'));
		$this->data['dom_id'] = $this->dom_id;
		$this->data['do'] = $this->getParam('do');
		$nav = owa_coreAPI::getGroupNavigation('Reports');
		// setup tabs
		$siteId = $this->get('siteId');
		if ($siteId) {
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
		
			
		
			if ( ! owa_coreAPI::getSiteSetting( $this->getParam( 'siteId' ), 'enableEcommerceReporting' ) ) {
			
				unset($nav['Ecommerce']);
			}
		}				
		
		//$this->body->set('sub_nav', owa_coreAPI::getNavigation($this->get('nav_tab'), 'sub_nav'));
		
		
		$this->set('top_level_report_nav', $nav);		
		
		
	}
	
	function post() {
		
		return;
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
		
		$db = owa_coreAPI::dbSingleton();
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