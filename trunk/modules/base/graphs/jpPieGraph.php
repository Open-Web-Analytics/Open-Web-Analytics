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
require_once (OWA_JPGRAPH_DIR . 'jpgraph.php');
require_once (OWA_JPGRAPH_DIR . 'jpgraph_pie.php');
require_once (OWA_JPGRAPH_DIR . 'jpgraph_pie3d.php');

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

class owa_jpPieGraph extends owa_graph {

	/**
	 * Constructor
	 * 
	 * @var array caller params
	 */
	function owa_jpPieGraph($params) {
		
		$this->owa_graph();
		$this->params = $params;
	
		return;
	}
	
	function construct() {
		
		$data = array_reverse($this->params['data']['data_pie']);
		
		// Create the Pie Graph.
		$this->graph = new PieGraph($this->params['width'],$this->params['height']);
		$this->graph->SetAntiAliasing();
		
		// Set A title for the plot
		$this->graph->title->Set($this->params['graph_title']);
		$this->graph->title->SetFont(FF_FONT1,FS_BOLD, 12); 
		$this->graph->title->SetColor("black");
		$this->graph->img->SetMargin(40,40,20,70);
		
		// Legend
		$this->graph->legend->Pos(0.01, 0.98, 'left', 'bottom'); 
		$this->graph->legend->SetLayout(LEGEND_HOR); 
		$this->graph->legend->SetShadow(false); 
		$this->graph->legend->SetFillColor('white'); 
		$this->graph->legend->SetFrameWeight(0); 
		$this->graph->legend-> SetFont( FF_FONT1, '',12);
		
		// set number of columns used in legend
		if ($this->params['legend_columns']):
			$this->graph->legend->SetColumns($this->params['legend_columns']);
		endif;
		
		$this->graph->SetFrame(true,'silver',1); 
		
		if($this->params['legends_cols']):	
			$this->graph->legend->SetColumns($this->params['legends_cols']); 	
		endif;		
		
		// Create pie plot
		$p1 = new PiePlot3D($data);
		$p1->SetSize(0.4);
		$p1->SetCenter(0.5,0.4);
		$p1->SetLegends($this->params['legends']);
		$p1->SetHeight(18);
		$p1->SetSliceColors(array('orange','lightblue','green','red','navy','gray', 'purple', 'yellow', 'brown', 'pink'));  
		//$p1->SetSliceColors(array_reverse(array('orange','lightblue','green','red','navy')));  
		$p1->value->HideZero();
		
		//Enable and set policy for guide-lines
		//$p1->SetGuideLines(true, false);
		//$p1->SetGuideLinesAdjust(1.4);
		
		// Setup the labels
		//$p1->SetLabelType(PIE_VALUE_ABS);    
		//$p1->value->Show();            
		$p1->value->SetFont(FF_FONT1,FS_BOLD, 12); 
		$p1->value->SetColor('gray5');  
		//$p1->value->SetFormat('%d '.$this->params['slice_label']);        
		//$p1->SetLabels($this->params['labels'], 1.1); 
		
		// Add plot
		$this->graph->Add($p1);
		
		return;
	}

}

?>