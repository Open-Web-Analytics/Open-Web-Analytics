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
		
		return owa_reportView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Set Page title
		$this->t->set('page_title', $this->get('title'));
		
		// Set Page headline
		$this->body->set('title', $this->get('title'));
		
		// Report Period Filters
		$this->body->set('reporting_periods', owa_lib::reporting_periods());
		
		// Set reporting period
		$this->setPeriod($this->data['period']);
	
		//create the report control params array
		$this->report_params = $this->data['params'];
		unset($this->report_params['p']);
		unset($this->report_params['u']);
		unset($this->report_params['v']);
		
		// unset per site session cookies but not site_id param
		foreach ($this->report_params as $k => $v) {
		
			// remove site specific session values
			if (substr($k, 0, 3) == 'ss_'):
				unset($this->report_params[$k]);
			endif;
			
			// remove left over first hit session value if found.
			if (substr($k, 0, 10) == 'first_hit_'):
				unset($this->report_params[$k]);
			endif;
			
		}
		
		unset($this->report_params['guid']);
		unset($this->report_params['caller']);
		
		$this->body->set('params', $this->report_params);
		$this->subview->body->set('params', $this->report_params);
		$this->_setLinkState();
		
		// set site filter list
		$this->body->set('sites', $this->getSitesList());
		
		$this->body->set('dom_id', $this->data['dom_id']);
		// add if here
		$this->subview->body->set('dom_id', $this->data['dom_id']);
		$this->body->set('do', $this->data['do']);
		
		// Set navigation
		$api = &owa_coreAPI::singleton();
		$this->body->set('sub_nav', $api->getNavigation($this->get('nav_tab'), 'sub_nav'));
		$this->body->set('top_level_report_nav', $api->getGroupNavigation('Reports'));
		
		// load body template
		$this->body->set_template('report.tpl');
		
		// set Js libs to be loaded
		$this->setJs('jquery', 'base/js/includes/jquery/jquery-1.2.6.min.js', '1.2.6');
		$this->setJs("sprintf", "base/js/includes/jquery/jquery.sprintf.js", '', array('jquery'));
		$this->setJs("jquery-ui", "base/js/includes/jquery/jquery-ui-personalized-1.5.2.min.js", '1.5.2', array('jquery'));
		$this->setJs("tablesorter", "base/js/includes/jquery/tablesorter/jquery.tablesorter.js", '', array('jquery'));
		$this->setJs("sparkline", "base/js/includes/jquery/jquery.sparkline.min.js", '', array('jquery'));
		$this->setJs("owa", "base/js/owa.js");
		$this->setJs("owa.report", 'base/js/owa.report.js', '', array('owa', 'jquery'));
		$this->setJs("owa.widget", "base/js/owa.widgets.js", '', array('owa', 'jquery'));
		$this->setJs("swfobject", "base/js/includes/swfobject.js");
		$this->setJs("json2", "base/js/includes/json2.js");
		$this->setJs("owa.chart", "base/js/owa.chart.js", '', array('owa', 'json2', 'swfobject', 'jquery'));
		$this->setJs("owa.sparkline", "base/js/owa.sparkline.js", '', array('owa', 'jquery', 'sparkline'));
		// data table style
		//$this->setCss('flora/flora.css');
		//$this->setCss('flora/flora.datepicker.css');
		//$this->setCss('ui.datepicker.css');
		$this->setCss('base/css/jquery-ui-themeroller.css');
		$this->setCss('base/js/includes/jquery/tablesorter/themes/blue/style.css');
		$this->setCss("base/css/owa.report.css");
		$this->setCss("base/css/owa.widgets.css");
		
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
		$this->data['params']['period'] = $period->get();
		$this->body->set('period', $period->get());
		$this->subview->body->set('period', $period->get());
		
		//$this->body->set('timePeriod', $period);
		//$this->subview->body->set('timePeriod', $period);
		// set period label
		$period_label = $period->getLabel();
		$this->body->set('period_label', $period_label);
		$this->subview->body->set('period_label', $period_label);
		return;
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
	
	function post() {
		
		$this->setCss("base/css/owa.admin.css");
		return;
	}
	
	
}

?>