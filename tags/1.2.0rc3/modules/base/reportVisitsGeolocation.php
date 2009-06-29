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
 * Visits geolocation Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitsGeolocationController extends owa_reportController {
	
	function owa_reportVisitsGeolocationController($params) {
		
		return owa_reportVisitsGeolocationController::__construct($params);

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
		$m->setLimit(500);
		$m->setOrder('DESC');
		$m->setPage($this->getParam('page'));
		$this->set('latest_visits', $m->generate());
		$pagination = $m->getPagination();
		$this->setPagination($pagination);
	
		$this->setTitle('Visitor Geo-location');
		$this->setView('base.report');
		$this->setSubview('base.reportVisitsGeolocation');
		
		
		return;

	}

}


/**
 * Visits Geolocation Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitsGeolocationView extends owa_view {
	
	function owa_reportVisitsGeolocationView() {
				
		return owa_reportVisitsGeolocationView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		$this->body->set_template('report_geolocation.tpl');
		$this->body->set('latest_visits', $this->get('latest_visits'));
		$this->setjs('includes/jquery/jquery.jmap-r72.js');
		$this->setjs('owa.map.js');
		//$this->setjs('includes/markermanager.js');
		
		return;
	}
	
	
}


?>