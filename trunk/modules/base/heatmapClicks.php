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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_INCLUDE_DIR.'heatmap.class.php');

class owa_heatmapClicksController extends owa_controller {
	
	function owa_heatmapClicksController($params) {
		
		$this->owa_controller($params);
		$this->priviledge_level = 'viewer';
	
	}
	
	function action() {
		
		$data = array();
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
			
		// Get clicks
		$clicks = $api->getMetric('base.topClicks', array(
	
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'document_id'		=> $this->params['document_id'],
				'ua_id'			=> $this->params['ua_id']
				),
			'limit'				=> 500
		));
		
		foreach ($clicks as $k => $v) {
		
			//if ($this->config['click_drawing_mode'] == 'center_on_page'):
				$x = $this->params['width'] * ($v['click_x'] / $v['page_width']);
				$data['clicks'][$x][$v['click_y']] = $v['count'];
			//else:
			//	$data['clicks'][$v['click_x']][$v['click_y']] = $v['count'];
			//endif;
		}
		
		
		$data['width'] = $this->params['width'];
		$data['height'] = $this->params['height'];
		$data['view'] = 'base.heatmapClicks';
		$data['view_method'] = 'delegate';
		
		return $data;
	}
}




/**
 * Click Heatmap View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_heatmapClicksView extends owa_view {
	
	function owa_heatmapClicksView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates
		ob_end_clean();
		//draw the heatmap
	    $map = new heatmap($data['clicks']);
	    $map->render($data['width'], $data['height']);
			
		return;
	}
	
	
}


?>