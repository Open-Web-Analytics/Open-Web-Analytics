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
 * Visits Geolocation Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_kmlVisitsGeolocationController extends owa_reportController {

	function owa_kmlVisitsGeolocationController($params) {
		
		return owa_kmlVisitsGeolocationController::__construct($params);
	
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
			
		if ($this->params['site_id']):
			//get site labels
			$s = owa_coreAPI::entityFactory('base.site');
			$s->getByColumn('site_id', $this->getParam('site_id'));
			$this->set('site_name', $s->get('name'));
			$this->set('site_description', $s->get('description'));
		else:
			$this->set('site_name', 'All Sites');
			$this->set('site_description', 'All Sites Tracked by OWA');
		endif;
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.latestVisits');
		$m->setConstraint('site_id', $this->getParam('site_id'));
		$m->setPeriod($this->getPeriod());
		$m->setOrder(OWA_SQL_DESCENDING); 
		$m->setLimit(15);
		$results = $m->generate();

		
		$this->set('latest_visits', $results);
		
		$this->setView('base.kmlVisitsGeolocation');
			
		return;
		
	}
	
}
		
/**
 * Visits Geolocation KML View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_kmlVisitsGeolocationView extends owa_view {
	
	function owa_kmlVisitsGeolocationView() {
		
		return owa_kmlVisitsGeolocationView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		$this->t->set_template('wrapper_blank.tpl');
		
		// load body template
		$this->body->set_template('kml_visits_geolocation.tpl');
		$this->body->set('visits', $this->get('latest_visits'));
		$this->body->set('site_name', $this->get('site_name'));
		$this->body->set('site_domain', $this->get('site_domain'));
		$this->body->set('site_description', $this->get('site_description'));
	
		//$this->_setLinkState();
		
		$this->body->set('xml', '<?xml version="1.0" encoding="UTF-8"?>');
				
		header('Content-type: application/vnd.google-earth.kml+xml; charset=UTF-8', true);
		
		header('Content-Disposition: inline; filename="owa.kml"');
		//header('Content-type: text/plain', true);		
		return;
	}
	
	
}


?>