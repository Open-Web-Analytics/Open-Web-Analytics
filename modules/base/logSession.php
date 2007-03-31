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
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		// Control logic
		
		$s = owa_coreAPI::entityFactory('base.session');
		
		//print_r($r);
	
		$s->setProperties($this->params);
	
		// Set Primary Key
		$s->set('id', $this->params['session_id']);
		 
		// set initial number of page views
		$s->set('num_pageviews', 1);
			
		// set prior session time properties		
		$s->set('prior_session_lastreq', $this->params['last_req']);
		$s->set('prior_session_id', $this->params['inbound_session_id']);
		
		if ($s->get('prior_session_lastreq') > 0):
			$s->set('time_sinse_priorsession', $s->get('timestamp') - $this->params['last_req']);
			$s->set('prior_session_year', date("Y", $this->params['last_req']));
			$s->set('prior_session_month', date("M", $this->params['last_req']));
			$s->set('prior_session_day', date("d", $this->params['last_req']));
			$s->set('prior_session_hour', date("G", $this->params['last_req']));
			$s->set('prior_session_minute', date("i", $this->params['last_req']));
			$s->set('prior_session_dayofweek', date("w", $this->params['last_req']));
		endif;
						
		// set source			
		$s->set('source', $this->params['source']);
						
		// Make ua id
		$s->set('ua_id', owa_lib::setStringGuid($this->params['HTTP_USER_AGENT']));
		
		// Make OS id
		$s->set('os_id', owa_lib::setStringGuid($this->params['os']));
	
		// Make document ids	
		$s->set('first_page_id', owa_lib::setStringGuid($this->params['page_url']));
		$s->set('last_page_id', $s->get('first_page_id'));
		
		// Generate Referer id
		$s->set('referer_id', owa_lib::setStringGuid($this->params['HTTP_REFERER']));
		
		// Generate Host id
		$s->set('host_id', owa_lib::setStringGuid($this->params['host']));
		
		$s->create();
		
		// create event message
		$session = $s->_getProperties();
		$properties = array_merge($this->params, $session);
		$properties['request_id'] = $this->params['guid'];
		
		// log the new session event to the event queue
		$this->logEvent('base.new_session', $properties);
			
		return;
			
	}
	
	
}

?>