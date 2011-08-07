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
 * Visits Geolocation KML network Link Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_kmlVisitsGeolocationNetworkLinkController extends owa_reportController {

	function __construct($params) {
		
		$this->priviledge_level = 'viewer';
		return parent::__construct($params);
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data = array();
		$data['params'] = $this->params;

		if ($this->params['site_id']):
			//get site labels
			$s = owa_coreAPI::entityFactory('base.site');
			$s->getByColumn('site_id', $this->params['site_id']);
			$data['site_name'] = $s->get('name');
			$data['site_description'] = $s->get('description');
			$data['site_domain'] = $s->get('domain');
		else:
			$data['site_name'] = 'All Sites';
			$data['site_description'] = 'Visits for all sitess tracked by OWA.';
			$data['site_domain'] = 'owa';
		endif;
		
		
		$data['view'] = 'base.kmlVisitsGeolocationNetworkLink';
		$data['user_name'] = $this->params['u'];
		$data['passkey'] = $this->params['pk'];
		
		return $data;	
		
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

class owa_kmlVisitsGeolocationNetworkLinkView extends owa_view {
	
	function __construct() {
		
		$this->priviledge_level = 'guest';
		
		return parent::__construct();
	}
	
	function render($data) {
		
		$this->t->set_template('wrapper_blank.tpl');
		
		// load body template
		$this->body->set_template('kml_network_link_geolocation.tpl');
		$this->body->set('params', $data['params']);
		$this->body->set('site_name', $data['site_name']);
		$this->body->set('site_domain', $data['site_domain']);
		$this->body->set('site_description', $data['site_description']);	
		$this->body->set('period_label', owa_lib::get_period_label($data['params']['period']));
		$this->body->set('date_label', owa_lib::getDateLabel($data['params']['period']));
		$this->body->set('xml', '<?xml version="1.0" encoding="UTF-8"?>');
		$this->body->set('user_name', $data['user_name']);
		$this->body->set('passkey', $data['passkey']);
		
		$this->_setLinkState();
		
		header('Content-type: application/vnd.google-earth.kml+xml; charset=UTF-8', true);	
		//header('Content-type: application/keyhole', true);
	}
}


?>