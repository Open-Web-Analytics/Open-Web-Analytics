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
 * Document Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDocumentController extends owa_reportController {
	
	function owa_reportDocumentController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data = array();
		$data['params'] = $this->params;
		
		switch ($this->params['period']) {

			case "this_year":
				$data['core_metrics_data'] = $api->getMetric('base.requestCountsByDay', array(
							
					'constraints'		=> array(
						'site_id'		=> $this->params['site_id'],
						'document_id' 	=> $this->params['document_id']
						),
					'groupby'			=> 'month'
				
				));
				
			break;
			
			default:
				$data['core_metrics_data'] = $api->getMetric('base.requestCountsByDay', array(
		
				'constraints'		=> array(
					'site_id'		=> $this->params['site_id'],
					'document_id' 	=> $this->params['document_id']
					),
				'groupby'			=> 'day'
			
			));
		
			break;
		}
		
		$data['summary_stats_data'] = $api->getMetric('base.requestCounts', array(
	
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'document_id' 	=> $this->params['document_id']
				)
		
		));
		
		$d = owa_coreAPI::entityFactory('base.document');
		$d->getByPk('id', $this->params['document_id']);
		$data['document_details'] = $d->_getProperties();
		
		$data['top_referers'] = $api->getMetric('base.topReferers', array(
			
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'session.first_page_id'	=> $this->params['document_id']
				),
			'limit'				=> 30
		));
		
		// get navigation
		$data['nav'] = $api->getNavigation('base.reportDocument', 'subnav');
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportDocument';
		$data['nav_tab'] = 'base.reportContent';
		
		return $data;

		
	}

}


/**
 * Visit Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDocumentView extends owa_view {
	
	function owa_reportDocumentView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		
		$this->body->caller_params['link_state']['document_id'] = $data['params']['document_id'];
		
		// Assign data to templates
		
		$this->body->set_template('report_document.tpl');
		$this->body->set('headline', 'Document Report');
		$this->body->set('core_metrics', $data['core_metrics_data']);
		$this->body->set('summary_stats', $data['summary_stats_data']);
		$this->body->set('detail', $data['document_details']);
		$this->body->set('top_referers', $data['top_referers']);
		$this->body->set('document_id', $data['params']['document_id']);
		$this->body->set('nav', $data['nav']);
			
		return;
	}
	
	
}


?>