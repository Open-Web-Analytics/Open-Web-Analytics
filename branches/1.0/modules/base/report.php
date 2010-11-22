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
	
	function render($data) {
		
		// Set Page title
		$this->t->set('page_title', $this->get('title'));
		
		// Set Page headline
		$this->body->set('title', $this->get('title'));
		$this->body->set('titleSuffix', $this->get('titleSuffix'));
		
		// Report Period Filters
		$pl = owa_coreAPI::supportClassFactory('base', 'timePeriod');
		$this->body->set('reporting_periods', $pl->getPeriodLabels());
		
		// Set reporting period
		$this->setPeriod($this->data['period']);
		$this->subview->body->set('is_default_period', $this->get('is_default_period'));
	
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
		$this->body->set('sites', $this->get('sites') );
	
		$this->body->set('dom_id', $this->data['dom_id']);
		// add if here
		$this->subview->body->set('dom_id', $this->data['dom_id']);
		$this->body->set('do', $this->data['do']);
		
		// Set navigation
		$this->body->set('top_level_report_nav', $this->get('top_level_report_nav'));
		
		// load body template
		$this->body->set_template('report.tpl');
			
		// set Js libs to be loaded
		$this->setJs('jquery', 'base/js/includes/jquery/jquery-1.4.2.min.js', '1.4.2');
		$this->setJs("sprintf", "base/js/includes/jquery/jquery.sprintf.js", '', array('jquery'));
		$this->setJs("jquery-ui", "base/js/includes/jquery/jquery-ui-1.8.1.custom.min.js", '1.8.1', array('jquery'));
		$this->setJs("sparkline", "base/js/includes/jquery/jquery.sparkline.min.js", '', array('jquery'));
		$this->setJs('jqgrid','base/js/includes/jquery/jquery.jqGrid.min.js');
		$this->setJs('excanvas','base/js/includes/excanvas.compiled.js', '', '', true);
		$this->setJs('flot','base/js/includes/jquery/flot/jquery.flot.min.js');
		$this->setJs('flot-pie','base/js/includes/jquery/flot/jquery.flot.pie.js');
		$this->setJs('jqote','base/js/includes/jquery/jQote2/jquery.jqote2.min.js');
		$this->setJs("owa", "base/js/owa.js");
		$this->setJs("owa.report", 'base/js/owa.report.js', '', array('owa', 'jquery'));
		//$this->setJs("owa.dataGrid", "base/js/owa.dataGrid.js", '', array('owa', 'jquery', 'jquery-ui'));
		$this->setJs("owa.resultSetExplorer", "base/js/owa.resultSetExplorer.js", '', array('owa', 'jquery', 'jquery-ui'));
		$this->setJs("json2", "base/js/includes/json2.js");
		$this->setJs("owa.sparkline", "base/js/owa.sparkline.js", '', array('owa', 'jquery', 'sparkline'));
		
		// css libs to be loaded
		$this->setCss('base/css/smoothness/jquery-ui-1.8.1.custom.css');
		$this->setCss("base/css/owa.report.css");
		$this->setCss('base/css/ui.jqgrid.css');
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
		$this->body->set('period_obj', $period);
		$this->subview->body->set('period_obj', $period);
		$this->subview->body->set('period', $period->get());
		// set period label
		$period_label = $period->getLabel();
		$this->body->set('period_label', $period_label);
		$this->subview->body->set('period_label', $period_label);
	}
	
	/**
	 * Applies calling params
	 *
	 * @access 	private
	 * @param 	array $properties
	 */
	function _setParams($params = null) {
	
		if(!empty($params)) {
			foreach ($params as $key => $value) {
				if(!empty($value)) {
					$this->params[$key] = $value;
				}
			}
		}
	}

	function post() {
		
		$this->setCss("base/css/owa.admin.css");
	}	
}

/**
 *  Dimension Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDimensionView extends owa_view {
	
	function render($data) {
			
		// Assign Data to templates
		$this->body->set('tabs', $this->get('tabs') );
		$this->body->set('metrics', $this->get('metrics'));
		$this->body->set('dimensions', $this->get('dimensions'));
		$this->body->set('sort', $this->get('sort'));
		$this->body->set('resultsPerPage', $this->get('resultsPerPage'));
		$this->body->set('dimensionLink', $this->get('dimensionLink'));
		$this->body->set('trendChartMetric', $this->get('trendChartMetric'));
		$this->body->set('trendTitle', $this->get('trendTitle'));
		$this->body->set('constraints', $this->get('constraints'));
		$this->body->set('gridTitle', $this->get('gridTitle'));
		$this->body->set('gridFormatters', $this->get('gridFormatters'));
		$this->body->set('excludeColumns', $this->get('excludeColumns'));
		$this->body->set_template('report_dimensionalTrend.php');
	}
}

/**
 *  Dimension Detail Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDimensionDetailView extends owa_view {
		
	function render($data) {
		
		// Assign Data to templates
		$this->body->set('tabs', $this->get('tabs') );
		$this->body->set('metrics', $this->get('metrics'));
		$this->body->set('dimension', $this->get('dimension'));
		$this->body->set('trendChartMetric', $this->get('trendChartMetric'));
		$this->body->set('trendTitle', $this->get('trendTitle'));
		$this->body->set('constraints', $this->get('constraints'));
		$this->body->set('dimension_properties', $this->get('dimension_properties'));
		$this->body->set('dimension_template', $this->get('dimension_template'));
		$this->body->set('excludeColumns', $this->get('excludeColumns'));
		$this->body->set_template('report_dimensionDetail.php');
	}
}

/**
 * Simple Dimensional Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_reportSimpleDimensionalView extends owa_view {
		
	function render() {
		
		// Assign Data to templates
		$this->body->set('metrics', $this->get('metrics'));
		$this->body->set('dimensions', $this->get('dimensions'));
		$this->body->set('sort', $this->get('sort'));
		$this->body->set('resultsPerPage', $this->get('resultsPerPage'));
		$this->body->set('dimensionLink', $this->get('dimensionLink'));
		$this->body->set('trendChartMetric', $this->get('trendChartMetric'));
		$this->body->set('trendTitle', $this->get('trendTitle'));
		$this->body->set('gridFormatters', $this->get('gridFormatters'));
		$this->body->set('constraints', $this->get('constraints'));
		$this->body->set('gridTitle', $this->get('gridTitle'));
		$this->body->set('excludeColumns', $this->get('excludeColumns'));
		$this->body->set_template('report_dimensionDetailNoTabs.php');
	}
}

?>