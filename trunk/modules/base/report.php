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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_news.php');

/**
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportView extends owa_view {
	
	function owa_reportView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		
		return;
	}
	
	function construct($data) {
		
		// Set Page title
		$this->t->set('page_title', 'Report');
		
		// Set Page headline
		$this->body->set('headline', '');
		
		// Report Period Filters
		$this->body->set('reporting_periods', owa_lib::reporting_periods());
		$this->body->set('date_reporting_periods', owa_lib::date_reporting_periods());
		$this->body->set('months', owa_lib::months());
		$this->body->set('days', owa_lib::days());
		$this->body->set('years', owa_lib::years());
		
		// Set reporting period
		$this->setPeriod($data['params']['period']);
		
		// Set date labels
		$date_label = $this->setDateLabel($data['params']);
		$this->body->set('date_label', $date_label);
		$this->subview->body->set('date_label', $date_label);
		
		//create the report control params array
		$this->report_params = $this->data['params'];
		unset($this->report_params['p']);
		unset($this->report_params['u']);
		unset($this->report_params['v']);
		unset($this->report_params['s']);
		unset($this->report_params['last_req']);
		unset($this->report_params['guid']);
		unset($this->report_params['caller']);
		
		$this->body->set('params', $this->report_params);
		
		// create state params for all links
		$link_params = array(
								'period'	=> $this->data['params']['period'], // could be set by setPeriod
								'day'		=> $data['params']['day'],
								'month'		=> $data['params']['month'],
								'year'		=> $data['params']['year'],
								'day2'		=> $data['params']['day2'],
								'month2'	=> $data['params']['month2'],
								'year2'		=> $data['params']['year2'],
								'site_id'	=> $this->data['params']['site_id']								
							);		
							
		$this->body->caller_params['link_state'] =  $link_params;
		$this->subview->body->caller_params['link_state'] =  $link_params;
		
		// set site filter list
		$this->body->set('sites', $this->getSitesList());
		
		
		//Fetch latest OWA news
		if ($this->config['fetch_owa_news'] == true):
			$rss = new owa_news;
			$news = $rss->Get($rss->config['owa_rss_url']);
		endif;

		$this->body->set('news', $news);
		
		// Set navigation
		$api = &owa_coreAPI::singleton();
	
		$this->body->set('sub_nav', $api->getNavigation($this->data['nav_tab'], 'sub_nav'));
		$this->body->set('top_level_report_nav', $api->getNavigation('base.report', 'top_level_report_nav'));
		
		// load body template
		
		$this->body->set_template('report.tpl');
		
		return;
	}
	
	/**
	 * Set report period
	 *
	 * @access public
	 * @param string $period
	 */
	function setPeriod($period) {
			
		// set in various templates and params
		$this->data['params']['period'] = $period;
		$this->body->set('period', $period);
		$this->subview->body->set('period', $period);
		
		// set period label
		$period_label = $this->get_period_label($period);
		$this->body->set('period_label', $period_label);
		$this->subview->body->set('period_label', $period_label);
		return;
	}

	/**
	 * Lookup report period label
	 *
	 * @param string $period
	 * @access private
	 * @return string $label
	 */
	function get_period_label($period) {
	
		return owa_lib::get_period_label($period);
	}
	
	
	
	function setDateLabel($params) {

		return owa_lib::getDateLabel($params);
		
	}
	
	/**
	 * Applies calling params
	 *
	 * @access 	private
	 * @param 	array $properties
	 */
	function _setParams($params = null) {
	
		if(!empty($params)):
			foreach ($params as $key => $value) {
				if(!empty($value)):
					$this->params[$key] = $value;
				endif;
			}
		endif;
		
		return;	
	}
	
	function getSitesList() {
		
		$s = owa_coreAPI::entityFactory('base.site');
		
		return $s->find();
		
	}
	
	
}

?>