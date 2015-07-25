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
			
		// Set reporting period
		$this->setPeriod($this->data['period']);
		$this->subview->body->set('is_default_period', $this->get('is_default_period'));
	
		//create the report control params array
		// TODO: this is evil as it may contain xss. Kill it's use downstream with fire, then nuke it here.
		$this->report_params = $this->data['params'];
		
		unset($this->report_params['guid']);
		unset($this->report_params['caller']);
		
		$this->body->set('params', $this->report_params);
		$this->subview->body->set('params', $this->report_params);
		
		
		// set site filter list
		$this->body->set('sites', $this->get('sites') );
	
		$this->body->set('dom_id', $this->get( 'dom_id' ) );
		// add if here
		$this->subview->body->set('dom_id', $this->get( 'dom_id' ) );
		$this->body->set('do', $this->data['do']);
		
		// Set navigation
		$this->body->set('hideReportingNavigation', $this->get('hideReportingNavigation') );
		$this->body->set('top_level_report_nav', $this->get('top_level_report_nav'));
		
		$this->body->set('hideSitesFilter', $this->get('hideSitesFilter') );
		
		$this->body->set('currentSiteId', $this->get('currentSiteId'));
		
		
		// load body template
		$this->body->set_template('report.tpl');
		
		// set link state used by report navigation
		$period = $this->get('period');
		
		$link_state = array(
			'siteId' => $this->get('currentSiteId')
		);
		
		if ( $period->get() === 'date_range' ) {
			
			$link_state[ 'startDate' ] = $period->getStartDate()->getYyyymmdd();
			$link_state[ 'endDate' ] = $period->getEndDate()->getYyyymmdd();
			
		} else {
		
			$link_state[ 'period' ] = $period->get();
		}
	
		$this->_setLinkState( $link_state );
			
		// set Js libs to be loaded
		/*
$this->setJs('lazy-load', 'base/js/includes/lazyload-2.0.min.js', '2.0');
		$this->setJs("json2", "base/js/includes/json2.js");
		$this->setJs('jquery', 'base/js/includes/jquery/jquery-1.6.4.min.js', '1.6.4');
		$this->setJs("sprintf", "base/js/includes/jquery/jquery.sprintf.js", '', array('jquery')); // needed anymore?
		$this->setJs("jquery-ui", "base/js/includes/jquery/jquery-ui-1.8.12.custom.min.js", '1.8.12', array('jquery'));
		$this->setJs("jquery-ui-selectmenu", "base/js/includes/jquery/jquery.ui.selectmenu.js", '1.8.1', array('jquery-ui'));
		$this->setJs("chosen", "base/js/includes/jquery/chosen.jquery.min.js", '0.9.7', array('jquery'));
		$this->setJs("sparkline", "base/js/includes/jquery/jquery.sparkline.min.js", '', array('jquery'));
		$this->setJs('jqgrid','base/js/includes/jquery/jquery.jqGrid.min.js');
		$this->setJs('excanvas','base/js/includes/excanvas.compiled.js', '', '', true);
		$this->setJs('flot','base/js/includes/jquery/flot_v0.7/jquery.flot.min.js');
		$this->setJs('flot-resize','base/js/includes/jquery/flot_v0.7/jquery.flot.resize.min.js');
		$this->setJs('flot-pie','base/js/includes/jquery/flot_v0.7/jquery.flot.pie.min.js');		
		$this->setJs('jqote','base/js/includes/jquery/jQote2/jquery.jqote2.min.js');
		$this->setJs("owa", "base/js/owa.js");
		$this->setJs("owa.report", 'base/js/owa.report.js', '', array('owa', 'jquery'));
		$this->setJs("owa.resultSetExplorer", "base/js/owa.resultSetExplorer.js", '', array('owa', 'jquery', 'jquery-ui'));
		$this->setJs("owa.sparkline", "base/js/owa.sparkline.js", '', array('owa', 'jquery', 'sparkline'));
		$this->setJs("owa.areaChart", "base/js/owa.areachart.js", '', array('owa', 'jquery', 'owa.resultSetExplorer', 'flot'));
		$this->setJs("owa.pieChart", "base/js/owa.piechart.js", '', array('owa', 'jquery', 'owa.resultSetExplorer', 'flot'));
		$this->setJs("owa.kpibox", "base/js/owa.kpibox.js", '', array('owa', 'jquery', 'owa.resultSetExplorer', 'jqote'));
		
*/
		$this->setJs('owa.reporting', 'base/js/owa.reporting-combined-min.js');
		// css libs to be loaded
		/*
$this->setCss('base/css/smoothness-1.8.12/jquery-ui.css');
		$this->setCss('base/css/jquery.ui.selectmenu.css');
		$this->setCss('base/css/ui.jqgrid.css');
		$this->setCss('base/css/chosen/chosen.css');
		$this->setCss("base/css/owa.admin.css");
		$this->setCss("base/css/owa.report.css");
*/
		$this->setCss("base/css/owa.reporting-css-combined.css");
		$additionalCss = $this->c->get('base','additionalCss');
		if (is_array($additionalCss)) {
			foreach ($additionalCss as $css) {
				$this->setCss($css);
			}			
		}
	}
	
	/**
	 * Set report period
	 *
	 * @access public
	 * @param string $period
	 */
	function setPeriod( $period ) {
			
		// set in various templates and params
		$this->data['params']['period'] = $period->get();
		$this->body->set( 'period_obj', $period);
		$this->subview->body->set( 'period_obj', $period);
		$this->body->set( 'period', $period->get() );
		$this->subview->body->set( 'period', $period->get() );
		// set period label
		$period_label = $period->getLabel();
		$this->body->set('period_label', $period_label);
		$this->subview->body->set('period_label', $period_label);
		$start_date = $period->get('startDate');
		$this->body->set( 'startDate', $start_date );
		$this->subview->body->set('startDate', $start_date );
		$end_date =  $period->get('endDate');
		$this->body->set('endDate', $end_date );
		$this->subview->body->set('endDate', $end_date );
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