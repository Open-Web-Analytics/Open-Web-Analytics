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
 * Traffic Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportTrafficController extends owa_reportController {
	
	function owa_reportTrafficController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {
		
		$data = array();
		
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data['session_count'] = $api->getMetric('base.sessionsCount', array(
			
			'result_format'		=> 'single_row',
			'constraints'		=> array('site_id'	=> $this->params['site_id'])

		));
		
		$data['from_se'] = $api->getMetric('base.visitsFromSearchEnginesCount', array(
		
			'result_format'		=> 'single_row',
			'constraints'		=> array('site_id'	=> $this->params['site_id'])

		));
		
		$data['from_sites'] = $api->getMetric('base.visitsFromSitesCount', array(
			
			'result_format'		=> 'single_row',
			'constraints'		=> array('site_id'	=> $this->params['site_id'])
			
		));
		
		$data['from_direct'] = $api->getMetric('base.visitsFromDirectNavCount', array(
		
			'result_format'		=> 'single_row',
			'constraints'		=> array('site_id'	=> $this->params['site_id'])
		
		));
		
		$data['from_feeds'] = $api->getMetric('base.visitsFromFeedsCount', array(
		
			'result_format'		=> 'single_row',
			'constraints'		=> array('site_id'	=> $this->params['site_id'])
			
		));
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportTraffic';
		$data['view_method'] = 'delegate';
		$data['nav_tab'] = 'base.reportTraffic';
		
		return $data;
		
	}
}


/**
 * Traffic Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportTrafficView extends owa_view {
	
	function owa_reportTrafficView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		// Assign Data to templates
		
		$this->body->set('headline', 'Traffic Sources');
		$this->body->set('keywords', $data['top_keywords']);
		$this->body->set('anchors', $data['top_anchors']);
		$this->body->set('domains', $data['top_hosts']);
		$this->body->set('referers', $data['top_referers']);
		$this->body->set('se_hosts', $data['top_search_engines']);
		$this->body->set('sessions', $data['session_count']);
		$this->body->set('from_feeds', $data['from_feeds']);
		$this->body->set('from_sites', $data['from_sites']);
		$this->body->set('from_direct', $data['from_direct']);
		$this->body->set('from_se', $data['from_se']);
		
		$this->body->set_template('report_traffic.tpl');

		$this->body->set('headline', 'Traffic Sources Report');
		
		return;
	}
	
	
}


?>