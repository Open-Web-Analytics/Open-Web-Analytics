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

require_once (OWA_BASE_CLASSES_DIR . 'owa_graph.php');
require_once (OWA_JPGRAPH_DIR .'jpgraph.php');
require_once (OWA_JPGRAPH_DIR .'jpgraph_line.php');
require_once (OWA_JPGRAPH_DIR .'jpgraph_bar.php');

/**
 * Bar and Area Combination Graph based on JP graph Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_jpBarAreaGraph extends owa_graph {
	
	var $graph;
	var $linePlot;
	var $barPlot;

	function owa_jpBarAreaGraph($params) {
		
		$this->owa_graph();
		$this->params = $params;
		
		return;
	}
	
	function construct() {
		
		$data_y1 = $this->params['data']['y1'];
		
		$data_y2 = $this->params['data']['y2'];
		
		$datax = $this->params['data']['x'];
		
		// Create the graph. 
		$this->graph = new Graph($this->params['width'],$this->params['height']);    
		//$this->graph->img->SetAntiAliasing();
		$this->graph->SetColor('white'); 
		$this->graph->SetMarginColor('white'); 
		$this->graph->SetFrame(false,'silver',1); 
		$this->graph->SetScale("textlin");
		//$this->graph->SetScale( 'datlin'); 
		$this->graph->img->SetMargin(40,40,35,60);
		
		// Legend
		$this->graph->legend->Pos(0.01, 0.98, 'left', 'bottom'); 
		$this->graph->legend->SetLayout(LEGEND_HOR); 
		$this->graph->legend->SetShadow(false); 
		$this->graph->legend->SetFillColor('white'); 
		$this->graph->legend->SetFrameWeight(0); 
		$this->graph->legend-> SetFont( FF_FONT1, '',12);
		
		
		$this->graph->xaxis->SetTickLabels($datax);
		$this->graph->xaxis->SetLabelAngle(90); 
		
		// Create the linear line plot
		$l1plot = new LinePlot($data_y1);
		$l1plot->SetColor("lightblue");
		$l1plot->SetWeight(1);
		$l1plot->SetFillColor("lightblue@0.2");
		$l1plot->SetLegend($this->params['y1_title']);	
		
		//Center the line plot in the center of the bars
		$l1plot->SetBarCenter();
	
		// Create the bar plot
		$bplot = new BarPlot($data_y2);
		$bplot->SetFillColor("orange");
		$bplot->SetWidth(1.0);
		$bplot->SetLegend($this->params['y2_title']);
		
		// Add the plots to the graph
		$this->graph->Add($bplot);
		$this->graph->Add($l1plot);
		
		// Decorate Graph
		$this->graph->title->Set($this->params['graph_title']);
		$this->graph->xaxis->title->Set($this->params['xaxis_title']);
		$this->graph->yaxis->title->Set($this->params['yaxis_title']);
		
		$this->graph->title->SetFont(FF_FONT1,FS_BOLD);
		$this->graph->yaxis->title->SetFont(FF_FONT1,FS_BOLD);
		$this->graph->xaxis->title->SetFont(FF_FONT1,FS_BOLD);
		
		return;
	}

}

?>