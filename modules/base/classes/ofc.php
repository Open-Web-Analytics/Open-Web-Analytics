<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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

require_once(OWA_OFC_DIR.'open-flash-chart.php');

/**
 * Open Flash Charts
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.0
 */


class owa_ofc {
	
	var $chart;
	var $x_axis;
	var $y_axis;
	var $area_fill_color = '#FFA500';
	var $line_color = '#4169e1';
	
	function owa_ofc() {
		
		return owa_ofc::__construct();
	}
	
	function __construct() {
		
		$this->chart = new open_flash_chart();
		$this->chart->set_bg_colour( '#FFFFFF' );
		$this->y_axis = new y_axis();
		$this->y_axis->set_colour('#9f9f9f');
		$this->y_axis->set_grid_colour('#9f9f9f');
		$this->y_axis->labels = null;
		//$this->y_axis->set_tick_length( 30 );
		$this->x_axis = new x_axis();
		$this->x_axis->set_colour('#9f9f9f');
		$this->x_axis->set_grid_colour('#ffffff');
		
		return;
	}
	
	function getAreaPlot() {
	
		// setup area plot
		$area = new area();
		// set the circle line width:
		$area->set_width( 3 );
		$area->set_default_dot_style( new hollow_dot(5) );
		$area->set_halo_size( 1 );
		$area->set_colour($this->line_color);
		$area->set_fill_colour($this->area_fill_color);
		$area->set_fill_alpha( 0.8 );
		
		return $area;
	
	}
	
	function getBarPlot() {
		
		$bar = new bar();
		
		return $bar;
	
	}
	
	function area($chartData) {
		
		if ($chartData->checkForSeries()) {
				
			// if chart title then:
			//$this->chart->set_title( new title( 'Area Chart' ) );
			$area = $this->getAreaPlot();
			
			// need to force a conversion of strings to ints for the arrea to render properly.
			$numArray = $this->convertArrayToInts($chartData->getSeriesData('area'));
			//$area->set_values($chartData->getSeriesData('area'));
			$area->set_values($numArray);
			$area->set_key( $chartData->getSeriesLabel('area'), 12 );
			
			// y-axis specific settings
			$steps = $chartData->getMax('area') / 4 ;
			$this->y_axis->set_range($chartData->getMin('area'), $chartData->getMax('area'), $steps);
			
			//$x_axis->labels = $chartData->getSeriesData('x');
			$this->x_axis->set_steps( 2 );
			//$this->x_axis->set_offset( false );
			
			// Add the X Axis Labels to the X Axis
			$x_labels = new x_axis_labels();
			$x_labels->set_steps( 2 );
			$x_labels->set_labels($chartData->getSeriesData('x'));
			//$x_labels->set_vertical();
			$this->x_axis->set_labels( $x_labels );
			
			// Assemble chart
			$this->chart->set_y_axis($this->y_axis);
			$this->chart->set_x_axis( $this->x_axis );
			// add the area object to the chart:
			$this->chart->add_element( $area );
			
			return $this->chart->toPrettyString();
		
		} else {
			// error chart
			return; 
		}
	
	}
	
	function bar() {
	
		//$title = new title( date("D M d Y") );
		
		$bar = $this->getBarPlot();
		$bar->set_values( array(9,8,7,6,5,4,3,2,1) );
		
		$chart = new open_flash_chart();
		//$chart->set_title( $title );
		$chart->add_element( $bar );
		
		echo $chart->toPrettyString();
		
	}
	
	function areaBar($chartData) {
		
		if ($chartData->checkForSeries()) {

			///bar
			$bar = $this->getBarPlot();
			$bar->set_values($this->convertArrayToInts($chartData->getSeriesData('bar')));
			$bar->set_key( $chartData->getSeriesLabel('bar'), 12 );
			// Make our area chart:
			$area = $this->getAreaPlot();
			// need to force a conversion of strings to ints for the arrea to render properly.
			$numArray = $this->convertArrayToInts($chartData->getSeriesData('area'));
			//$area->set_values($chartData->getSeriesData('area'));
			$area->set_values($numArray);
			$area->set_key( $chartData->getSeriesLabel('area'), 12 );		
			
			$steps = round($chartData->getMax('area', 'bar') / 4);
			
			$this->y_axis->set_range($chartData->getMin('area'), $chartData->getMax('area', 'bar'), $steps);
			$this->y_axis->set_tick_length(5); 	

			
			$x_labels = new x_axis_labels();
			$x_labels->set_steps( 2 );
			$x_labels->set_labels($chartData->getSeriesData('x'));
			// Add the X Axis Labels to the X Axis
			$this->x_axis->set_labels( $x_labels );
			
			// assemble chart
			$this->chart->set_y_axis($this->y_axis);
			$this->chart->x_axis = $this->x_axis;
			$this->chart->add_element($bar);
			$this->chart->add_element($area);
			
			
			return $this->chart->toPrettyString();
		} else {
			return;
		}	
	}
	
	function getPiePlot() {
	
		$pie = new pie();
		$pie->set_start_angle( 35 );
		$pie->set_animate( true );
		$pie->set_label_colour( '#432BAF' );
		$pie->set_gradient_fill();
		$pie->set_tooltip( '#label#<br>#val# (#percent#)' );
		$pie->set_colours(
		    array(
		        '#1F8FA1',    // <-- blue
		        '#848484',    // <-- grey
		        '#CACFBE',    // <-- green
		        '#DEF799'    // <-- light green
		    ) );
		
		return $pie;
	
	}
	
	function pie($chartData, $label_length = 50) {
		
		if ($chartData->checkForSeries()) {
		
			$pie = $this->getPiePlot();	
			
			$values = $this->convertArrayToInts($chartData->getSeriesData('values'));
			$labels = $chartData->getSeriesData('labels');
			
			$pie_slices = array();
		
			foreach ($values as $k => $v) {
			
				$pie_slices[] = new pie_value($v, owa_lib::truncate($labels[$k], $label_length));
			}
	
			$pie->set_values($pie_slices);
		
			$this->chart->add_element($pie);
			
			return $this->chart->toPrettyString();
		
		} else {
			// error chart
			return;
		}			
	
		
	}
	
	function convertArrayToInts($array) {
		if  (!empty($array)) {
			return array_map(create_function('$value', 'return (int)$value;'),$array);
		} else {
			return false;
		}
		
	}
		
}



?>