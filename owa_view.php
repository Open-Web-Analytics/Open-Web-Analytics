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

require_once(OWA_BASE_CLASSES_DIR.'owa_template.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_requestContainer.php'); // ??

/**
 * Abstract View Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_view extends owa_base {

	/**
	 * Main view template object
	 *
	 * @var object
	 */
	var $t;
	
	/**
	 * Body content template object
	 *
	 * @var object
	 */
	var $body;
	
	/**
	 * Sub View object
	 *
	 * @var object
	 */
	var $subview;
	
	/**
	 * Rednered subview
	 *
	 * @var string
	 */
	var $subview_rendered;
	
	/**
	 * CSS file for main template
	 *
	 * @var unknown_type
	 */
	var $css_file;
	
	/**
	 * The priviledge level required to access this view
	 * @depricated
	 * @var string
	 */
	var $priviledge_level;
	
	/**
	 * Type of page
	 *
	 * @var unknown_type
	 */
	var $page_type;
	
	/**
	 * Request Params
	 *
	 * @var unknown_type
	 */
	var $params;
	
	/**
	 * Authorization object
	 *
	 * @var object
	 */
	var $auth;
	
	var $module; // set by factory.
	
	var $data;
	
	var $default_subview;
	
	var $is_subview;
	
	var $js = array();
	
	var $css = array();
	
	/**
	 * Constructor
	 *
	 * @return owa_view
	 */
	function owa_view($params = null) {
		
		return owa_view::__construct($params);
	}
	
	function __construct($params = null) {
	
		parent::__construct($params);
		
		$this->t = new owa_template();
		$this->body = new owa_template($this->module);
		$this->setTheme();
		return;

	}
	
	/**
	 * Assembles the view using passed model objects
	 *
	 * @param unknown_type $data
	 * @return unknown
	 */
	function assembleView($data) {
		
		$this->e->debug('Assembling view: '.get_class($this));
		
		// set view name in template class. used for navigation.
		$this->body->caller_params['view'] = $this->data['view'];
		
		if (array_key_exists('params', $this->data)):
			$this->body->set('params', $this->data['params']);
		endif;
		
		if (array_key_exists('subview', $this->data)):
			$this->body->caller_params['subview'] = $this->data['subview'];
		endif;
		
		if (array_key_exists('nav_tab', $this->data)):
			$this->body->caller_params['nav_tab'] = $this->data['nav_tab'];
		endif;
		
		// Assign status msg
		if (array_key_exists('status_msg', $data)):
			$this->t->set('status_msg', $data['status_msg']);
		endif;
		
		// get status msg from code passed on the query string from a redirect.
		if (array_key_exists('status_code', $data)):
			$this->t->set('status_msg', $this->getMsg($data['status_code']));
		endif;
		
		// set error msg directly if passed from constructor
		if (array_key_exists('error_msg', $data)):
			$this->t->set('error_msg', $data['error_msg']);
		endif;
		
		// auth user
		//$auth_data = $this->auth->authenticateUser($this->priviledge_level);		
		
		// authentication status
		if (array_key_exists('auth_status', $data)):
			$this->t->set('authStatus', $this->data['auth_status']);
		endif;
		
		// get error msg from error code passed on the query string from a redirect.
		if (array_key_exists('error_code', $data)):
			$this->t->set('error_msg', $this->getMsg($data['error_code']));
		endif;
		
		// load subview
		if (!empty($this->data['subview']) || !empty($this->default_subview)):
			// Load subview
			$this->loadSubView($this->data['subview']);
		endif;
		
		// construct main view.  This might set some properties of the subview.
		if (method_exists(get_class($this), 'render')) {
			$this->render($this->data);
		} else {
			// old style
			$this->construct($this->data);
		}
		//array of errors usually used for field validations
		if (array_key_exists('validation_errors', $data)):
			$this->body->set('validation_errors', $data['validation_errors']);
		endif;
		
		// pagination
		if (array_key_exists('pagination', $this->data)):
			$this->body->set('pagination', $this->data['pagination']);
		endif;
		
		$this->_setLinkState();
			
		// assemble subview
		if (!empty($this->data['subview'])):
			
			// set view name in template. used for navigation.
			$this->subview->body->caller_params['view'] = $this->data['subview'];
			
			// Set validation errors
			$this->subview->body->set('validation_errors', $this->data['validation_errors']);
			
			// Load subview 
			$this->renderSubView($this->data);
			
			// assign subview to body template
			$this->body->set('subview', $this->subview_rendered);
			
			// pagination
			if (array_key_exists('pagination', $this->data)):
				$this->subview->body->set('pagination', $this->data['pagination']);
			endif;
			
			if (array_key_exists('params', $this->data)):
				$this->subview->body->set('params', $this->data['params']);
				$this->subview->body->set('do', $this->data['params']['do']);
			endif;
			
			
		endif;
		
		if (!empty($this->data['validation_errors'])):
			$ves = new owa_template('base');
			$ves->set_template('error_validation_summary.tpl');
			$ves->set('validation_errors', $this->data['validation_errors']);
			$validation_errors_summary = $ves->fetch();
			$this->t->set('error_msg', $validation_errors_summary);
		endif;		
		
		// assign css and js ellements if the view is not a subview.
		// subview css/js have been merged/pulls from subview and assigned here.
		if ($this->is_subview != true):
			if (!empty($this->css)):
				$this->t->set('css', $this->css);
			endif;
			
			if (!empty($this->js)):
				$this->t->set('js', $this->js);
			endif;
		endif;
		
		//Assign body to main template
		$this->t->set('config', $this->config);
					
		//Assign body to main template
		$this->t->set('body', $this->body);
		
		// Return fully asembled View
		return $this->t->fetch();
		
	}
	
	/**
	 * Sets the theme to be used by a view
	 *
	 */
	function setTheme() {
		
		$this->t->set_template($this->config['report_wrapper']);
		
		return;
	}
	
	/**
	 * Abstract method for assembling a view
	 * @depricated
	 * @param array $data
	 */
	function construct($data) {
		
		return;
		
	}
	
	/**
	 * Assembles subview
	 *
	 * @param array $data
	 */
	function loadSubView($subview) {
		
		if (empty($subview)):
			if (!empty($this->default_subview)):
				$subview = $this->default_subview;
				$this->data['subview'] = $this->default_subview;
			else:
				return $this->e->debug("No Subview was specified by caller.");
			endif;
		endif;
	
		$this->subview = owa_coreAPI::subViewFactory($subview);
		$this->subview->setData($this->data);
		
		return;
		
	}
	
	/**
	 * Assembles subview
	 *
	 * @param array $data
	 */
	function renderSubView($data) {
		
		// Stores subview as string into $this->subview
		$this->subview_rendered = $this->subview->assembleSubView($data);
		
		// pull css and jas elements needed by subview
		$this->css = array_merge($this->css, $this->subview->css);
		$this->js = array_merge($this->js, $this->subview->js);
	
		return;
		
	}
	
	/**
	 * Assembles the view using passed model objects
	 *
	 * @param unknown_type $data
	 * @return unknown
	 */
	function assembleSubView($data) {
		
		// construct main view.  This might set some properties of the subview.
		if (method_exists(get_class($this), 'render')) {
			$this->render($data);
		} else {
			// old style
			$this->construct($data);
		}
		
		$this->t->set_template('wrapper_subview.tpl');
		
		//Assign body to main template
		$this->t->set('body', $this->body);

		// Return fully asembled View
		$page =  $this->t->fetch();
	
		return $page;
					
	}
	
	function setCss($file, $path = '') {
		
		if(empty($path)):
			$path = $this->config['public_url'].'css'.DIRECTORY_SEPARATOR;
		endif;
		
		$this->css[] = $path.$file;
		return;
	}
	
	function setJs($file, $path = '') {
		
		if(empty($path)):
			$path = $this->config['public_url'].'js'.DIRECTORY_SEPARATOR;
		endif;
		
		$this->js[] = $path.$file;
		return;
	}
	
	
	/**
	 * Sets the Priviledge Level required to access this view
	 *
	 * @param string $level
	 */
	function _setPriviledgeLevel($level) {
		
		$this->priviledge_level = $level;
		
		return;
	}
	
	/**
	 * Sets the page type of this view. Used for tracking.
	 *
	 * @param string $page_type
	 */
	function _setPageType($page_type) {
		
		$this->page_type = $page_type;
		
		return;
	}
	
	function _setLinkState() {
		
		// create state params for all links
		$link_params = array(
								'period'	=> $this->data['params']['period'], // could be set by setPeriod
								'day'		=> $this->data['params']['day'],
								'month'		=> $this->data['params']['month'],
								'year'		=> $this->data['params']['year'],
								'day2'		=> $this->data['params']['day2'],
								'month2'	=> $this->data['params']['month2'],
								'year2'		=> $This->data['params']['year2'],
								'site_id'	=> $this->data['params']['site_id']								
							);		
							
		$this->body->caller_params['link_state'] =  $link_params;
		
		if(!empty($this->subview)):
			$this->subview->body->caller_params['link_state'] =  $link_params;
		endif;
		
		return;
	}
	
	function get($name) {
		
		return $this->data[$name];
	}
	
	function set($name, $value) {
		
		$this->data[$name] = $value;
		return;
	}
	
	function setSubViewProperty($name, $value) {
		
		$this->subview->set($name, $value);
		return;
	}
	
	function getSubViewProperty($name) {
		return $this->subview->get($name); 
	}
	
	function setData($data) {
		$this->data = $data;
	}
	
}

class owa_areaFlashChartView extends owa_view {

	function owa_areaFlashChartView() {
	
		return owa_areaFlashChartView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
		
	}

	function assembleView($data) {
		
		include_once(OWA_INCLUDE_DIR.'open-flash-chart.php' );
		
		$g = new graph();
		//$g->title($data['title'], '{font-size: 20px;}' );
		$g->bg_colour = '#FFFFFF';
		$g->x_axis_colour('#cccccc', '#ffffff');
		$g->y_axis_colour('#cccccc', '#cccccc');
		//$g->set_inner_background( '#FFFFFF', '#', 90 );

		// y series
		$g->set_data($data['y']['series']);
		// width: 2px, dots: 3px, area alpha: 25% ...
		$g->area_hollow( 1, 3, 60, '#99CCFF', $data['y']['label'], 12, '#99CCFF' );
		
		
		$g->set_x_labels($data['x']['series']);
		$g->set_x_label_style( 10, '#000000', 0, 2 );
		$g->set_x_axis_steps( 2 );
		$g->set_x_legend( $data['x']['label'], 12, '#000000' );
		
		$g->set_y_min( 0 );
		
		$max = max($data['y']['series']);
		
		$g->set_y_max($max + 2);
		
		$g->y_label_steps( 2 );
		//$g->set_y_legend( '', 12, '#C11B01' );
		
		return $g->render();
	
	}

}



class owa_areaBarsFlashChartView extends owa_view {

	function owa_areaBarsFlashChartView() {
	
		return owa_areaBarsFlashChartView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
		
	}

	function assembleView($data) {
		
		include_once(OWA_INCLUDE_DIR.'open-flash-chart.php' );
		
		$cd = $data['chart_data'];
				
		$g = new graph();
		//$g->title($data['title'], '{font-size: 20px;}' );
		$g->bg_colour = '#FFFFFF';
		$g->x_axis_colour('#cccccc', '#ffffff');
		$g->y_axis_colour('#cccccc', '#cccccc');
		//$g->set_inner_background( '#FFFFFF', '#', 90 );
		
		// y2 series
		$g->set_data($cd->getSeriesData('bar'));
		$g->bar( 100, '#FF9900', $cd->getSeriesLabel('bar'), 10 );

		// y series
		$g->set_data($cd->getSeriesData('area'));
		// width: 2px, dots: 3px, area alpha: 25% ...
		$g->area_hollow( 1, 3, 60, '#99CCFF', $cd->getSeriesLabel('area'), 12, '#99CCFF' );
		
		
		$g->set_x_labels($cd->getSeriesData('x'));
		$g->set_x_label_style( 10, '#000000', 0, 2 );
		$g->set_x_axis_steps( 2 );
		$g->set_x_legend($cd->getSeriesLabel('x'), 12, '#000000' );
		
		$g->set_y_min( 0 );
		
		$max = max(array_merge($cd->getSeriesData('bar'), $cd->getSeriesData('area')));
		
		$g->set_y_max($max + 2);
		
		$g->y_label_steps( 2 );
		//$g->set_y_legend( '', 12, '#C11B01' );
		
		return $g->render();
	
	}

}

/*
class owa_areaBarsFlashChart2View extends owa_view {

	function owa_areaBarsFlashChart2View() {
	
		return owa_areaBarsFlashChart2View::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
		
	}

	function construct($data) {
		
		include_once(OWA_INCLUDE_DIR.'ofc-2.0/php-ofc-library/open-flash-chart.php');
		
		$this->t->set_template('wrapper_component.tpl');		
		$this->body->set_template('ofc2.tpl');
		$this->setJs('includes/json2.js');
		$this->setJs('includes/swfobject.js');
		
		$g = new open_flash_chart();
		
		$x = new x_axis();
		$y = new y_axis();
		//$g->title($data['title'], '{font-size: 20px;}' );
		$g->bg_colour = '#FFFFFF';
		$x->set_colour('#cccccc', '#ffffff');
		$y->set_colour('#cccccc', '#cccccc');
		//$g->set_inner_background( '#FFFFFF', '#', 90 );
		
		// y2 series
		$bar = new bar();
		$bar->set_values($data['y']['series']);
		//$g->set_data($data['y']['series']);
		//$g->bar( 100, '#FF9900', $data['y']['label'], 10 );
		$bar->set_colour('#FF9900');
		//$bar->set_alpha(10)
		// y series
		
		// area
		$a = new area_hollow();
		$a->set_values($data['y2']['series']);
		// width: 2px, dots: 3px, area alpha: 25% ...
		//$g->area_hollow( 1, 3, 60, '#99CCFF', $data['y2']['label'], 12, '#99CCFF' );
		
		
		//$g->set_x_labels($data['x']['series']);
		$x->set_labels( $data['x']['series'] );
		$g->x_axis = $x;
		$g->add_y_axis( $y );
		//$g->set_x_label_style( 10, '#000000', 0, 2 );
		//$g->set_x_axis_steps( 2 );
		//$g->set_x_legend( $data['x']['label'], 12, '#000000' );
		
		//$g->set_y_min( 0 );
		//$g->set_y_max( 225 );
		
		//$g->y_label_steps( 15 );
		//$g->set_y_legend( '', 12, '#C11B01' );
		
		$this->body->set('data', $g->toPrettyString());
		$this->body->set('dom_id', $data['dom_id']);
		return;
	}

}

*/

class owa_pieFlashChartView extends owa_view {

	function owa_pieFlashChartView() {
	
		return owa_pieFlashChartView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
		
	}

	function assembleView($data) {
		
		include_once(OWA_INCLUDE_DIR.'open-flash-chart.php' );
		
		$g = new graph();
		$g->bg_colour = '#FFFFFF';
		//
		// PIE chart, 60% alpha
		//
		$g->pie(100,'#505050','{font-size: 10px; color: #404040;');
		//$g->pie(60,'#E4F0DB','{display:none;}',false,1);
		//
		// pass in two arrays, one of data, the other data labels
		//
		$g->pie_values($data['values'], $data['labels']);
		//
		// Colours for each slice, in this case some of the colours
		// will be re-used (3 colurs for 5 slices means the last two
		// slices will have colours colour[0] and colour[1]):
		//
		$g->pie_slice_colours( array('#99CCFF', '#FF9900', '#356aa0','#C79810', '#848484','#CACFBE','#DEF799') );
		
		//$g->set_tool_tip( '#val#%' );
		$g->set_tool_tip( 'Label: #x_label#<br>Value: #val#' );
		return $g->render();
	
	}

}



/**
 * Generic HTMl Table View
 *
 * Will produce a generic html table
 *
 */
class owa_genericTableView extends owa_view {

	function __construct() {
		
		return parent::__construct();
		
	}
	
	function owa_genericTableView() {
	
		return owa_genericTableView::__construct(); 
	}
	
	function construct($data) {
	
		$this->t->set_template('wrapper_blank.tpl');		
		$this->body->set_template('generic_table.tpl');
		
		if (!empty($data['labels'])):
			$this->body->set('labels', $data['labels']);
			$this->body->set('col_count', count($data['labels']));
		else:
			$this->body->set('labels', '');
			$this->body->set('col_count', count($data['rows'][0]));
		endif;
			
		if (!empty($data['rows'])):
			$this->body->set('rows', $data['rows']);
			$this->body->set('row_count', count($data['rows']));
		else:
			$this->body->set('rows', '');
			$this->body->set('row_count', 0);
		endif;
		
		if (array_key_exists('table_class', $data)):
			$this->body->set('table_class', $data['table_class']);
		else:
			$this->body->set('table_class', 'data');		
		endif;
		
		if (array_key_exists('header_orientation', $data)):
			$this->body->set('header_orientation', $data['header_orientation']);
		else:
			$this->body->set('header_orientation', 'col');		
		endif;
		
		if (array_key_exists('table_footer', $data)):
			$this->body->set('table_footer', $data['table_footer']);
		else:
			$this->body->set('table_footer', '');		
		endif;
		
		if (array_key_exists('table_caption', $data)):
			$this->body->set('table_caption', $data['table_caption']);
		else:
			$this->body->set('table_caption', '');		
		endif;
		
		if (array_key_exists('is_sortable', $data)) {
			if ($data['is_sortable'] != true) {
				$this->body->set('sort_table_class', '');
			}
		} else {
			$this->body->set('sort_table_class', 'tablesorter');		
		}
		
		if (array_key_exists('table_row_template', $data)):
			$this->body->set('table_row_template', $data['table_row_template']);
		else:
			;		
		endif;
		
		
		$this->body->set('table_id', str_replace('.', '-', $data['params']['do']).'-table');
		
		return;
		
		
	}

}

class owa_openFlashChartView extends owa_view {

	function owa_openFlashChartView() {
		
		owa_openFlashChartView::__construct();
		
		return;
	}
	
	function __construct() {
		
		return parent::__construct();
		
	}
	
	function construct($data) {
		
		// load template
		$this->t->set_template('wrapper_blank.tpl');
		$this->body->set_template('ofc.tpl');
		// set
		$this->body->set('widget', $data['widget']);
		$this->body->set('height', $data['height']);
		$this->body->set('width', $data['width']);
		$this->body->set('dom_id', $data['dom_id']);
		$this->body->set('params', $data['params']);
		
		return;
	
	}

}

class owa_sparklineView extends owa_view {

	function owa_sparklineView() {
	
		return owa_sparklineView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();

	}
	
	function construct($data) {
	
		// load template
		$this->t->set_template('wrapper_blank.tpl');
		$this->body->set_template('sparkline.tpl');
		// set
		$this->body->set('widget', $data['widget']);
		$this->body->set('type', $data['type']);
		$this->body->set('height', $data['height']);
		$this->body->set('width', $data['width']);
		
		return;
	}

}

class owa_sparklineLineGraphView {

	function assembleView($data) {
	
		require_once(OWA_SPARKLINE_DIR.'Sparkline_Line.php');
	
		$sparkline = new Sparkline_Line();
		
		$sparkline->SetData(0, 15);
		$sparkline->SetData(1, 18);
		$sparkline->SetData(2, 9);
		$sparkline->SetData(3, 40);
		$sparkline->RenderResampled($data['width'], $data['height']);
		$sparkline->Output();
		return;
	}
}

class owa_sparklineJsView extends owa_view {

function owa_sparklineJsView() {
	
		return owa_sparklinejSView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();

	}
	
	function construct($data) {
	
		// load template
		$this->t->set_template('wrapper_blank.tpl');
		$this->body->set_template('sparklineJs.tpl');
		// set
		$this->body->set('widget', $data['widget']);
		$this->body->set('type', $data['type']);
		$this->body->set('height', $data['height']);
		$this->body->set('width', $data['width']);
		$this->body->set('values', $data['series']['values']);
		$this->body->set('dom_id', $data['dom_id'].rand());
		//$this->setJs("includes/jquery/jquery.sparkline.js");
		return;
	}


}

?>