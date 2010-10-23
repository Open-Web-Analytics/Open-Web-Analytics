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
	
	function action() {
	
		$site_id = $this->getParam('siteId');
		
		if ($site_id) {
			//get site labels
			$s = owa_coreAPI::entityFactory('base.site');
			$s->getByColumn('site_id', $site_id);
			$this->set('site_name', $s->get('name'));
			$this->set('site_description', $s->get('description'));
		}
	
		$rs = owa_coreAPI::executeApiCommand(array(
				'do'				=> 'getLatestVisits',
				'siteId'			=> $this->getParam('siteId'),
				'page'				=> $this->getParam('page'),
				'startDate'			=> $this->getParam('startDate'),
				'endDate'			=> $this->getParam('endDate'),
				'period'			=> $this->getParam('period'),
				'resultsPerPage'	=> 200 ) );
		
		$this->set('latest_visits', $rs);
		$this->set('site_id', $site_id);
		$this->setTitle('Visitor Geo-location');
		$this->setView('base.report');
		$this->setSubview('base.reportVisitsGeolocation');
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
		
	function render($data) {
		
		// Assign data to templates
		$this->body->set_template('report_geolocation.tpl');
		$this->body->set('latest_visits', $this->get('latest_visits'));
		$this->body->set('site_id', $this->get('site_id') );
		$this->setjs('jmaps', 'base/js/includes/jquery/jquery.jmap-r72.js');
		$this->setjs('owa.map', 'base/js/owa.map.js');
	}
}

?>