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
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_coreAPI.php');
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_location.php');

/**
 * Log Host Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logHostController extends owa_controller {
	
	function owa_logHostController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		$h = owa_coreAPI::entityFactory('base.host');
		
		$h->setProperties($this->params);
		
		$h->set('id', owa_lib::setStringGuid($this->params['host'])); 

		// makes the geo-location object from the service specified in the config
		$location = owa_location::factory($this->config['plugin_dir']."location".DIRECTORY_SEPARATOR, $this->config['geolocation_service']);
		
		// lookup
		$location->get_location($this->params['ip_address']);
		
		//set properties of the session
		$h->set('country', $location->country);
		$h->set('city', $location->city);
		$h->set('latitude', $location->latitude);
		$h->set('longitude', $location->longitude);
		
		$h->create();
		
		return;
			
	}
	

	
}

?>