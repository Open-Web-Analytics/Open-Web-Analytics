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


/**
 * Log Page View Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logPageRequestController extends owa_controller {
	
	function owa_logPageRequestController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		$event = $this->getParam('event');
		// Control logic
		
		$r = owa_coreAPI::entityFactory('base.request');
		
		//print_r($r);
	
		$r->setProperties($event->getProperties());
	
		// Set Primary Key
		$r->set('id', $event->get('guid'));
		
		// Make ua id
		$r->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));
	
		// Make OS id
		$r->set('os_id', owa_lib::setStringGuid($event->get('os')));
	
		// Make document id	
		$r->set('document_id', owa_lib::setStringGuid($event->get('page_url')));
		
		// Generate Referer id
		$r->set('referer_id', owa_lib::setStringGuid($event->get('HTTP_REFERER')));
		
		// Generate Host id
		$r->set('host_id', owa_lib::setStringGuid($event->get('host')));
		
		$result = $r->create();
		
		if ($result == true) {
			$event->setEventType($event->getEventType().'_logged');
			$this->logEvent($event->getEventType(), $event);
		}
		
		return;
			
	}
	
	
}

?>