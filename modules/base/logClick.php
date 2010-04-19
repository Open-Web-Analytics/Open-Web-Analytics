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

require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_controller.php');

/**
 * Log Click Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logClickController extends owa_controller {
	
	function owa_logClickController($params) {
		
		$this->owa_controller($params);
	}
	
	function action() {
		
		// Control logic
		
		//$this->e->debug("click controller params: ".print_r($this->params, true));
		
		$event = $this->getParam('event');
			
		$c = owa_coreAPI::entityFactory('base.click');
				
		$c->setProperties($event->getProperties());
		
		// Set Click Id
		$c->set('id', $event->get('guid'));
		
		$c->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));
		
		// Make document id	
		$c->set('document_id', owa_lib::setStringGuid($event->get('page_url'))); 
		
		// Make Target page id
		$c->set('target_id', owa_lib::setStringGuid($c->get('target_url')));
		
		// Make position id used for group bys
		$c->set('position', $c->get('click_x').$c->get('click_y'));
		
		$c->create();			
	}
}

?>