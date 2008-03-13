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
 * Dashboard Page View Visits View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphDashboardTrendView extends owa_abstractJpGraphView  {
	
	function owa_graphDashboardTrendView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		$api = &owa_coreAPI::singleton($data);
		
		$result = $api->getMetric('base.dashCoreByDay', array(
		
					'request_params'	=> $data,
					'period'			=> $data['period'],
					'constraints'		=> array(
						'site_id'	=> $data['site_id'],
						'is_browser' => 1,
						'is_robot' 	=> 0),
					
					'order'				=> 'ASC'
					));
		
	
		//Graph params

		$new_result = owa_lib::deconstruct_assoc($result);
		//$new_result = '';
		
		if(empty($new_result['page_views']) && empty($new_result['sessions'])):
		
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');	
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
			
		else:
			
			$this->graph = owa_coreAPI::graphFactory('base.jpBarAreaGraph');
		
			$this->graph->params['width'] = 900;
			$this->graph->params['height'] = 240;
			$this->graph->params['y2_title'] = "PageViews";
			$this->graph->params['y1_title'] = "Visits";
			$this->graph->params['data']['y2'] = $new_result['page_views'];
			$this->graph->params['data']['y1'] = $new_result['sessions'];	
			$this->graph->params['data']['x'] = $this->makeDateArray($result, "n/j");
				
			//$this->graph->params['graph_title'] = "Page Views & Visits for " . $this->graph->get_period_label($data['period']);
						
		endif;
		
		return;
	}
	
	
	
	
}



?>