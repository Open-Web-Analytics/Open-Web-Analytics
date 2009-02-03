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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_reportController.php');
require_once(OWA_INCLUDE_DIR.'heatmap.class.php');

class owa_heatmapClicksController extends owa_reportController {
	
	function owa_heatmapClicksController($params) {
		
		return owa_heatmapClicksController::__construct($params);
			
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
		
		// Get clicks
		$c = owa_coreAPI::metricFactory('base.topClicks');
		$c->setPeriod($this->getPeriod());
		$c->setConstraint('site_id', $this->getParam('site_id')); 
		$c->setConstraint('document_id', $this->getParam('document_id'));
		$c->setConstraint('ua_id', $this->getParam('ua_id'));
		$c->setLimit(500);
		$clicks = $c->generate();
				
		foreach ($clicks as $k => $v) {
		
			//if ($this->config['click_drawing_mode'] == 'center_on_page'):
				$x = $this->params['width'] * ($v['click_x'] / $v['page_width']);
				$this->data['clicks'][$x][$v['click_y']] = $v['count'];
			//else:
			//	$data['clicks'][$v['click_x']][$v['click_y']] = $v['count'];
			//endif;
		}
		
		$this->set('width', $this->getParam('width'));
		$this->set('height', $this->getParam('height'));
		$this->setView('base.heatmapClicks');
		
		return;
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
		
		return owa_heatmapClicksView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		ob_end_clean();
		//draw the heatmap
	    $map = new heatmap($this->get('clicks'));
	    $map->render($this->get('width'), $this->get('height'));
			
		return;
	}
	
	
}


?>