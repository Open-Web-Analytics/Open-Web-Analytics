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

class owa_reportClicksController extends owa_reportController {
	
	function owa_reportClicksController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'admin';
	
	}
	
	function action() {
		
		$data = array();
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
			
		// Fetch document object
		$d = owa_coreAPI::entityFactory('base.document');
		$d->getByPk('id', $this->params['document_id']);
		$data['document_details'] = $d->_getProperties();
		$data['document_id'] = $this->params['document_id'];
		
		// Get clicks
		$data['clicks'] = $api->getMetric('base.topClicks', array(
	
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'document_id'		=> $this->params['document_id'],
				'ua_id'			=> $this->params['ua_id']
				),
			'limit'				=> 500
		));
		
		// get top User agents
		$data['uas'] = $api->getMetric('base.clickBrowserTypes', array(
		
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'document_id'		=> $this->params['document_id']),
			'limit'				=> 10
		
		));
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportClicks';
		$data['view_method'] = 'delegate';
		$data['nav_tab'] = 'base.reportContent';
		
		return $data;
	}
}




/**
 * Click Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportClicksView extends owa_view {
	
	function owa_reportClicksView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_clicks.tpl');
	
		$this->body->set('headline', 'Click Map Report');
		
		$this->body->set('clicks', $data['clicks']);
		$this->body->set('uas', $data['uas']);
		//print_r($data['uas']);
		$this->body->set('detail', $data['document_details']);
		
		$this->body->set('document_id', $data['document_id']);
		$this->body->set('params', $data['params']);	
		return;
	}
	
	
}


?>