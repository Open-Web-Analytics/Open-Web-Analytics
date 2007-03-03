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
 * Visitor Type Pie Graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphVisitorTypesView extends owa_abstractJpGraphView  {
	
	function owa_graphVisitorTypesView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Fetch Data
		
		$api = &owa_coreAPI::singleton($data);
		
		$result = $api->getMetric('base.visitorTypesCount', array(
				'request_params'	=> $data,
				'period'			=> $data['period'],
				'result_format'		=> 'single_array',
				'constraints'		=> array(
					'site_id'		=> $data['site_id'],		
					'is_browser' 	=> 1,
					'is_robot' 		=> 0
					
					)
			
				));
									
		//Graph params
		
		if (empty($result['new_visitor']) && empty($result['repeat_visitor'])):
			
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
			
		else:
			
			$this->graph = owa_coreAPI::graphFactory('base.jpPieGraph');
			
			// Graph params
			$this->graph->params['graph_title'] = "New Vs. Repeat Visitors for \n" . $this->graph->get_period_label($this->params['period']);
			$this->graph->params['height']	= 220;
			$this->graph->params['width']	= 350;
			$this->graph->params['data']['data_pie'] = array($result['new_visitor'], $result['repeat_visitor']);
			$this->graph->params['legends'] = array('New Visitors', 'Repeat Visitors');
			$this->graph->params['graph_title'] = "Visitor Types for " . $this->graph->get_period_label($data['period']);
			
		endif;
		
		return;
	}
	
	
}



?>