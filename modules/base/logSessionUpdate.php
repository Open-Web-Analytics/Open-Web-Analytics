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
 * Log Session Update Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logSessionUpdateController extends owa_controller {
	
	function owa_logSessionUpdateController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
	
		// Make entity
		$s = owa_coreAPI::entityFactory('base.session');
		
		// Fetch from session from database
		$s->getByPk('id', $this->params['session_id']);
		
		// increment number of page views
		$s->set('num_pageviews', $s->get('num_pageviews') + 1);
		
		// update timestamp
		$s->set('last_req', $this->params['last_req']);
		
		// update last page id
		$s->set('last_page_id', owa_lib::setStringGuid($this->params['page_url']));
		
		// Persist to database
		$s->update();
		
		// setup event message
		$session = $s->_getProperties();
		$properties = array_merge($this->params, $session);
		$properties['request_id'] = $this->params['guid'];
		
		// Log session update event to event queue
		$this->logEvent('base.session_update', $properties);
			
		return;
			
	}
	
	
}

?>