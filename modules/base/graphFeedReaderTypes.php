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
 * Feed Reader Types Pie Graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphFeedReaderTypesView extends owa_abstractJpGraphView  {
	
	function owa_graphFeedReaderTypesView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Fetch Data
		
		$api = &owa_coreAPI::singleton($data);
		
		$result = $api->getMetric('base.feedReaderTypesCount', array(
		
			'constraints'		=> array('site_id' => $this->params['site_id'])
		
		));
		
		// chop results and summarize into 6 reader types.
		if (count($result) >5):
		
			//$this->e->debug('before'.print_r($result, true));
			$temp_result = array_chunk($result, 9);
			$remainder = owa_lib::deconstruct_assoc($temp_result[1]);
			$temp_result[0][] = array('browser_type' => 'Others', 'count' => array_sum($remainder['count']));
			$result = owa_lib::deconstruct_assoc($temp_result[0]);
			//$this->e->debug('after'.print_r($result, true));
		else:
			$result = owa_lib::deconstruct_assoc($result);
		endif;
								
		//Graph params
		
		if (empty($result)):
			
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
			
		else:
			
			$this->graph = owa_coreAPI::graphFactory('base.jpPieGraph');
			
			// Graph params
			
			$this->graph->params['legend_columns'] = 2;
			$this->graph->params['data']['data_pie'] = $result['count'];
			$this->graph->params['legends'] = $result['browser_type'];
			$this->graph->params['graph_title'] = "Feed Reader Types for " . $this->graph->get_period_label($data['period']);
			$count = count($result['browser_type']);
			$this->graph->params['height']	= 280+$count*10;
			$this->graph->params['width']	= 350;
			
		endif;
		
		return;
	}
	
	
}

?>