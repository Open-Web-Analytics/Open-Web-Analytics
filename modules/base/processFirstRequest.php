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
require_once(OWA_BASE_MODULE_DIR.'processEvent.php');

/**
 * Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processFirstRequestController extends owa_processEventController {
	
	function owa_processFirstRequestController($params) {
		$this->owa_processEventController($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		if (!empty($this->params[$this->config['first_hit_param']])):
		
			// Create a new request object
			$this->event = owa_coreAPI::supportClassFactory('base', 'requestEvent');
			
			$this->event->state = 'first_page_request';
		
			//Load request properties from first_hit cookie if it exists
			if (!empty($this->params[$this->config['first_hit_param']])):
				$this->event->load_first_hit_properties($this->params[$this->config['first_hit_param']]);
			endif;
			
			$this->e->debug(sprintf('First hit Request %d logged to event queue',
									$r->properties['request_id']));
			
			// Log the request
			$this->event->log();
		
		endif;	
			
		$data = array();
		
		$data['view'] = 'base.pixel';
		$data['view_method'] = 'image';
		
		return $data;
		
	}
	
	
}

?>