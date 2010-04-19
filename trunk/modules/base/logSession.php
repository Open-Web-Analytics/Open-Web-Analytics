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

require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Log New Session Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logSessionController extends owa_controller {
	
	function owa_logSessionController($params) {
		
		$this->owa_controller($params);
	}
	
	function action() {
		
		// Control logic
		
		$s = owa_coreAPI::entityFactory('base.session');
		
		$event = $this->getParam('event');
	
		$s->setProperties($event->getProperties());
	
		// Set Primary Key
		$s->set('id', $event->get('session_id'));
		 
		// set initial number of page views
		$s->set('num_pageviews', 1);
		$s->set('is_bounce', true);

		// set prior session time properties		
		$s->set('prior_session_lastreq', $event->get('last_req'));
				
		$s->set('prior_session_id', $event->get('inbound_session_id'));
	owa_coreAPI::debug('hi');	
		if ($s->get('prior_session_lastreq') > 0) {
			$s->set('time_sinse_priorsession', $s->get('timestamp') - $event->get('last_req'));
			$s->set('prior_session_year', date("Y", $event->get('last_req')));
			$s->set('prior_session_month', date("M", $event->get('last_req')));
			$s->set('prior_session_day', date("d", $event->get('last_req')));
			$s->set('prior_session_hour', date("G", $event->get('last_req')));
			$s->set('prior_session_minute', date("i", $event->get('last_req')));
			$s->set('prior_session_dayofweek', date("w", $event->get('last_req')));
		}
		
		// set last_req to be the timestamp of the event that triggered this session.
		$s->set('last_req', $event->get('timestamp'));
						
		// set source			
		$s->set('source', $event->get('source'));
						
		// Make ua id
		$s->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));
		
		// Make OS id
		$s->set('os_id', owa_lib::setStringGuid($event->get('os')));
	
		// Make document ids	
		$s->set('first_page_id', owa_lib::setStringGuid($event->get('page_url')));
			
		$s->set('last_page_id', $s->get('first_page_id'));
	
		// Generate Referer id
		$s->set('referer_id', owa_lib::setStringGuid($event->get('HTTP_REFERER')));
			
		// Generate Host id
		$s->set('host_id', owa_lib::setStringGuid($event->get('full_host')));
				
		$s->create();

		// create event message
		$session = $s->_getProperties();
		$properties = array_merge($event->getProperties(), $session);
		$properties['request_id'] = $event->get('guid');
		$ne = owa_coreAPI::supportClassFactory('base', 'event');
		$ne->setProperties($properties);
		$ne->setEventType('base.new_session');
		
		// log the new session event to the event queue
		$eq = owa_coreAPI::getEventDispatch();
		$eq->notify($ne);
			
		return;
			
	}
	
	
}

?>