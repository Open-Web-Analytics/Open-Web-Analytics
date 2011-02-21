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
		$this->_setCapability('view_reports');
		return parent::__construct($params);
	
	}
	
	/**
	 * pre action
	 *
	 */
	function pre() {
		
		// site lists
		$sites = owa_coreAPI::getSitesList();
		$this->set('sites', $sites);
		// set default siteId if none exists on request
		$site_id = $this->getParam('siteId');
		if ( ! $site_id ) {
			$site_id = $this->getParam('site_id'); 
		}
		if ( ! $site_id ) {
			$site_id = $sites[0]['site_id']; 
		}
		$this->setParam('siteId', $site_id);
		
		// pass full set of params to view
		$this->data['params'] = $this->params;
				
		// set default period if necessary
		if (empty($this->params['period'])) {
			$this->params['period'] = 'last_seven_days';
			$this->set('is_default_period', true);
		}
		
		$this->setPeriod($this->getParam('period'));
		
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
				'sort'			=> 'visits-'
		);
		
		$tabs['site_usage'] = $site_usage;
		
		// ecommerce tab
		if ( owa_coreAPI::getSiteSetting( $this->getParam('siteId'), 'enableEcommerceReporting') ) {
		
			$ecommerce = array(
					'tab_label'		=> 'e-commerce',
					'metrics'		=> 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
					'sort'			=> 'transactionRevenue-'
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
						'sort'			=> 'goalValueAll-'
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
		
	}
	
	function post() {
		
		return;
	}
	
	function setTitle($title, $suffix = '') {
		
		$this->set('title', $title);
		$this->set('titleSuffix', $suffix);
	}
}

?>