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
 * Feed Formats Pie Graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphFeedFormatsView extends owa_abstractJpGraphView  {
	
	function owa_graphFeedFormatsView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Fetch Data
		
		$api = &owa_coreAPI::singleton($data);
		
		$result = $api->getMetric('base.feedFormatsCount', array(
		
			'result_format'		=> 'inverted_array',
			'constraints'		=> array('site_id' => $this->params['site_id'])
		
		));
									
		//Graph params
		
		if (empty($result)):
			
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
			
		else:
			
			$this->graph = owa_coreAPI::graphFactory('base.jpPieGraph');
			
			// Graph params
			$this->graph->params['height']	= 220;
			$this->graph->params['width']	= 350;
			$this->graph->params['data']['data_pie'] = $result['count'];
			$this->graph->params['legends'] = $result['feed_format'];;
			$this->graph->params['graph_title'] = "Feed Formats for " . $this->graph->get_period_label($data['period']);
			
		endif;
		
		return;
	}
	
	
}



?>