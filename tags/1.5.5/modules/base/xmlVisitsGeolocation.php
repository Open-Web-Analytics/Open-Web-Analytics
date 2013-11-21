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

/**
 * XML Visits Geolocation Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_xmlVisitsGeolocationController extends owa_reportController {

	function owa_xmlVisitsGeolocationController($params) {
		
		return owa_xmlVisitsGeolocationController::__construct($params);
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {	

		$site_id = $this->getParam('site_id');
		if ($site_id):
			//get site labels
			$s = owa_coreAPI::entityFactory('base.site');
			$s->getByColumn('site_id', $site_id);
			$this->set('site_name', $s->get('name'));
			$this->set('site_description', $s->get('description'));
		else:
			$this->set('site_name', 'All Sites');
			$this->set('site_description', 'All Sites Tracked by OWA');
		endif;
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.latestVisits');
		$m->setConstraint('site_id', $this->getParam('site_id'));
		//$period = $this->makeTimePeriod('all_time');
		$m->setPeriod($this->getPeriod());
		$m->setLimit(100);
		$m->setOrder('DESC');
		$this->set('latest_visits', $m->generate());
		$this->setView('base.xmlVisitsGeolocation');
			
		return;	
		
	}
	
}
		


/**
 * Visits Geolocation xml View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_xmlVisitsGeolocationView extends owa_view {
	
	function owa_xmlVisitsGeolocationView() {
		
		return owa_xmlVisitsGeolocationView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		$this->t->set_template('wrapper_blank.tpl');
		
		// load body template
		$this->body->set_template('xml_visits_geolocation.tpl');
		//$this->body->set_template('kml_google_sample.tpl');
		$this->body->set('visits', $this->get('latest_visits'));
		$this->body->set('site_name', $this->get('site_name'));
		$this->body->set('site_domain', $this->get('site_domain'));
		$this->body->set('site_description', $this->get('site_description'));
		$this->body->set('xml', trim('<?xml version="1.0" encoding="UTF-8"?>'));
		$this->_setLinkState();
				
		//if (substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')):
		//	ob_start("ob_gzhandler");
		//	header('Content-type: text/xml', true);
		//	ob_end_flush();
		//else:
		//header('Content-type: text/xml', true);
		header('Content-type: application/vnd.google-earth.kml+xml; charset=UTF-8', true);
		//endif:
		
		return;
	}
	
	
}


?>