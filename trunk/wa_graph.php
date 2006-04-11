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

require_once (WA_BASE_DIR . '/owa_api.php');
require_once (WA_INCLUDE_DIR.'jpgraph/jpgraph.php');

/**
 * Graph Generator
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class wa_graph {

	/**
	 * Current Time
	 *
	 * @var array
	 */
	var $time_now;
	
	/**
	 * Graph Data
	 *
	 * @var array
	 */
	var $data = array();
	
	/**
	 * Graph Parameters
	 *
	 * @var array
	 */
	var $params = array();
	
	/**
	 * Graph Height
	 *
	 * @var integer
	 */
	var $height = 200;
	
	/**
	 * Graph Width
	 *
	 * @var integer
	 */
	var $width = 400;
	
	/**
	 * Image Format
	 *
	 * @var string
	 */
	var $image_format = "png";
	
	/**
	 * Metrics
	 *
	 * @var unknown_type
	 */
	var $metrics;
	
	/**
	 * API type
	 *
	 * @var string
	 */
	var $api_type = 'graph';
	
	/**
	 * API Calls
	 *
	 * @var array
	 */
	var $api_calls = array();

	/**
	 * Constructor
	 *
	 * @return wa_graph
	 * @access public
	 */
	function wa_graph() {
		
		// Set current time
		$this->time_now = wa_lib::time_now();
		
		// Fetch all  metrics objects through the api
		$this->metrics = owa_api::get_instance('metric');
		
		return;
	}

	/**
	 * Line Graph Wrapper
	 * 
	 */
	function line_graph() {
	
		require_once (WA_INCLUDE_DIR.'jpgraph/jpgraph_line.php');
		
		$datay = $this->data['datay'];
		$graph = new Graph($this->width,$this->height,"auto");
		$graph->SetScale("textlin");
		$graph->img->SetImgFormat($this->image_format);
		$graph->img->SetMargin(40,40,40,40);    
		$graph->SetShadow();
		
		$graph->title->SetFont(FF_FONT1,FS_BOLD);
		$graph->xaxis->SetTickLabels($this->data['datax']);
		
		$graph->title->Set($this->params['graph_title']);
		$graph->xaxis->title->Set($this->params['xaxis_title']);
		$graph->yaxis->title->Set($this->params['yaxis_title']);
		
		$p1 = new LinePlot($datay);
		$p1->SetFillColor("orange");
		$p1->mark->SetType(MARK_FILLEDCIRCLE);
		$p1->mark->SetFillColor("red");
		$p1->mark->SetWidth(2);
		$graph->Add($p1);
		
		$graph->Stroke();
		
		return;
	}
	
	/**
	 * Vertical Bar Graph
	 *
	 */
	function bar_graph() {
	
		require_once (WA_INCLUDE_DIR .'jpgraph/jpgraph_bar.php');
	
		$datay = $this->data['datay'];
	
		// Create the graph. These two calls are always required
		$graph = new Graph($this->params['width'],$this->params['height'],"auto"); 
		$graph->SetScale("textlin");
		$graph->img->SetImgFormat($this->image_format);
		$graph->SetBackgroundGradient('white','white'); 
	
		// Add a drop shadow
		//$graph->SetShadow();
		
		// Adjust the margin a bit to make more room for titles
		$graph->img->SetMargin(40,30,20,40);
		
		// Create a bar pot
		$bplot = new BarPlot($datay);
		$bplot->SetFillColor('orange');
		$bplot->SetWidth(1.0);
		//$bplot->SetValuePos('top'); 
		$graph->Add($bplot);
		
		// Setup the titles
		$graph->title->Set($this->params['graph_title']);
		$graph->xaxis->SetTickLabels($this->data['datax']);
		$graph->xaxis->title->Set($this->params['xaxis_title']);
		$graph->yaxis->title->Set($this->params['yaxis_title']);
		
		$graph->title->SetFont(FF_FONT1,FS_BOLD);
		$graph->yaxis->title->SetFont(FF_FONT1,FS_BOLD);
		$graph->xaxis->title->SetFont(FF_FONT1,FS_BOLD);
		
		// Display the graph
		$graph->Stroke();
		
		return;
	}
	
	/**
	 * Pie Graph
	 *
	 */
	function pie_graph() {
	
		require_once (WA_INCLUDE_DIR .'jpgraph/jpgraph_pie.php');

		$data = $this->data['data_pie'];
		
		// Create the Pie Graph.
		$graph = new PieGraph($this->params['width'],$this->params['height']);
		
		// Set A title for the plot
		//$graph->title->Set($this->params['graph_title']);
		$graph->title->SetFont(FF_FONT1,FS_BOLD); 
		$graph->title->SetColor("black");
		$graph->legend->SetAbsPos(10,10, 'right', 'top');
		$graph->legend->SetColumns(3); 		
		// Create pie plot
		$p1 = new PiePlot($data);
		$p1->SetCenter(0.5,0.55);
		$p1->SetSize(0.3);
		
		// Enable and set policy for guide-lines
		$p1->SetGuideLines();
		$p1->SetGuideLinesAdjust(1.4);
		
		// Setup the labels
		$p1->SetLabelType(PIE_VALUE_ABS);    
		$p1->value->Show();            
		$p1->value->SetFont(FF_FONT1,FS_BOLD);    
		$p1->value->SetFormat('%d users');        
		
		$p1->SetLegends($this->params['legends']);
		
		// Add and stroke
		$graph->Add($p1);
		$graph->Stroke();
		
		return;
	}
	
	/**
	 * Get Display Label for Reporting Period
	 *
	 * @param string $period
	 * @return string $label
	 * @access public
	 */
	function get_period_label($period) {
	
		switch ($period) {
		
			case "today";
				$label = "Today";
				break;
			case "yesterday";
				$label = "Yesterday";
				break;
			case "this_month";
				$label = "This Month";
				break;
			case "this_week";
				$label = "This Week";
				break;
			case "this_year";
				$label = "This Year";
				break;
			case "last_seven_days";
				$label = "The Last Seven Days";
				break;
		}
		
		return $label;
	}
	
	/**
	 * makelabel of some kind or another
	 *
	 * @param unknown_type $format
	 * @param unknown_type $data
	 * @todo decide what to do here.
	 */
	function make_date_label($format, $data) {
	
		return;
	}

}

?>
