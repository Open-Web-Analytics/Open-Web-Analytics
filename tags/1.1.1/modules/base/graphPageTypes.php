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
 * Page Type Pie Graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphPageTypesView extends owa_abstractJpGraphView  {
	
	function owa_graphPageTypesView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Fetch Data
		
		$api = &owa_coreAPI::singleton($data);
		
		$result = $api->getMetric('base.pageTypesCount',array(
		
		'result_format'		=> 'inverted_array',
		'constraints'		=> array('site_id' => $data['site_id'])
	
		));
		
		//$result = owa_lib::deconstruct_assoc($results);
		
		//Graph params
		
		if (empty($result)):
			
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
			
		else:
			
			$this->graph = owa_coreAPI::graphFactory('base.jpPieGraph');
			$count = count($result['page_type']);
			// Graph params
			$this->graph->params['legend_columns'] = 3;
			$this->graph->params['graph_title'] = "Page Types for " . $this->graph->get_period_label($data['period']);
			$this->graph->params['height']	= 240+20*$count/$this->graph->params['legend_columns'];
			$this->graph->params['width']	= 350;
			$this->graph->params['data']['data_pie'] = $result['count'];
			$this->graph->params['legends'] = $result['page_type'];
			
			
			
		endif;
		
		return;
	}
	
	
}



?>