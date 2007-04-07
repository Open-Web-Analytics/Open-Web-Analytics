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

require_once(OWA_BASE_CLASSES_DIR.'owa_lib.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_coreAPI.php');
require_once(OWA_BASE_CLASS_DIR.'abstractJpGraphView.php');

/**
 * Feed Requests Graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphFeedRequestsView extends owa_abstractJpGraphView  {
	
	function owa_graphFeedRequestsView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Fetch Data
		
		$api = &owa_coreAPI::singleton($data);
		
		switch ($data['period']) {

			case "this_year":
		
				$result = $api->getMetric('base.feedViewsTrend', array(
				
					'result_format'		=> '',
					'groupby'			=> array('year', 'month'),
					'constraints'		=> array('site_id' => $this->params['site_id'])
				));
				
				break;
				
			default:
			
				$result = $api->getMetric('base.feedViewsTrend', array(
				
					'result_format'		=> '',
					'groupby'			=> array('year', 'month', 'day'),
					'constraints'		=> array('site_id' => $this->params['site_id'])
				));
			
				break;
		}	
	//print_r($result);
									
		//Graph params
		
		if (empty($result)):
			
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
			
		else:
			
			$graph_arrays = owa_lib::deconstruct_assoc($result);
			$this->graph = owa_coreAPI::graphFactory('base.jpBarAreaGraph');
			
			// Graph params
			$this->graph->params['height']	= 240;
			$this->graph->params['width']	= 700;
			$this->graph->params['y2_title'] = "Feed Requests";
			$this->graph->params['y1_title'] = "Unique Feed Readers";
			$this->graph->params['data']['y2'] = $graph_arrays['fetch_count'];
			$this->graph->params['data']['y1'] = $graph_arrays['reader_count'];	
			$this->graph->params['data']['x'] = $this->makeDateArray($result, "n/j");
							
			$this->graph->params['graph_title'] = "Feed Fetches & Unique Feed Readers for " . $this->graph->get_period_label($data['period']);
			
		endif;
		
		return;
	}
	
	
}



?>