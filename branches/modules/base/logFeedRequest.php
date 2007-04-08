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
 * LogFeed Request Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logFeedRequestController extends owa_controller {
	
	function owa_logFeedRequestController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		// Make entity
		$f = owa_coreAPI::entityFactory('base.feed_request');
		
		$f->setProperties($this->params);
		
		// Set Primary Key
		$f->set('id', $this->params['guid']);
		
		// Make ua id
		$f->set('ua_id', owa_lib::setStringGuid($this->params['HTTP_USER_AGENT']));
		
		// Make OS id
		$f->set('os_id', owa_lib::setStringGuid($this->params['os']));
	
		// Make document id	
		$f->set('document_id', owa_lib::setStringGuid($this->params['page_url']));
		
		// Generate Host id
		$f->set('host_id', owa_lib::setStringGuid($this->params['host']));
		
		// Persist to database
		$result = $f->create();
		
		// makes request event processing idem potent
		if ($result == true):
			$eq = &eventQueue::get_instance();
			$eq->log($this->params, $this->params['event_type'].'_logged');
		endif;
			
		return;
			
	}
	
	
}

?>