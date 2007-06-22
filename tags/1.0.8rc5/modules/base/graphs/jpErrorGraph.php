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
require_once (OWA_JPGRAPH_DIR .'jpgraph_canvas.php');


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

class owa_jpErrorGraph extends owa_graph {
	
	function owa_jpErrorGraph($params) {
		
		$this->owa_graph();
		$this->params = $params;
		
		return;
	}
	
	function construct() {
		
		$this->graph = new CanvasGraph($this->params['width'], $this->params['height']);    
		
		$t1 = new Text($this->params['error_msg']);
		//$t1->Pos(0.05, 0.1); 
		$t1->Pos(0.5,0.5,'center','center'); 
		$t1->ParagraphAlign('center'); 
		$t1->SetOrientation('h'); 
		$t1->SetFont(FF_FONT1, FS_BOLD); 
		$t1->SetColor('gray5'); 
		$this->graph->AddText($t1); 
		
		return;
		
	}

}

?>