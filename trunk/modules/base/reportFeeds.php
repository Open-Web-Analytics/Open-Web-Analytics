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
require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Feeds Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportFeedsController extends owa_reportController {
	
	function owa_reportFeedsController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {
		
		$data = array();
		
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
			
			switch ($this->params['period']) {
	
				case "this_year":
					$data['trend'] = $api->getMetric('base.feedViewsTrend', array(
					
						'constraints'		=> array('site_id'	=> $this->params['site_id']),
						'groupby'			=> array('year', 'month'),
						'order'				=> 'DESC '
					));		
					
					break;
				
				default:
					$data['trend'] = $api->getMetric('base.feedViewsTrend', array(
					
						'constraints'		=> array('site_id'	=> $this->params['site_id']),
						'groupby'			=> array('year', 'month', 'day'),
						'order'				=> 'DESC '
					));	
					
			}
			
			$data['view'] = 'base.report';
			$data['subview'] = 'base.reportFeeds';
			$data['view_method'] = 'delegate';
			
			return $data;
		
	}
	
}

/**
 * Feeds Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportFeedsView extends owa_view {
	
	function owa_reportFeedsView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
	
		// Assign Data to templates
		
		$this->body->set('headline', 'Feeds');
	
		$this->body->set('feed_trend', $data['trend']);
		
		$this->body->set_template('report_feeds.tpl');
	
		$this->body->set('headline', 'Feeds Report');
		
		return;
	}
	
	
}


?>