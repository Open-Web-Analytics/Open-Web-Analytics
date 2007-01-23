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
require_once(OWA_BASE_DIR.'/owa_browscap.php');

/**
 * Generic Event Processor Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processEventController extends owa_controller {
	
	function owa_processEventController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	/**
	 * Main Constrol Logic
	 *
	 * @return unknown
	 */
	function action() {
		
		// Setup generic event model
		$event = owa_coreAPI::supportClassFactory('base', 'event');
		
		$event->state = $this->params['event'];
		
		$event->_setProperties($this->params);
		
		$event->setTime();
		
		$event->setIp();
		
		// Resolve host name
		if ($this->config['resolve_hosts'] = true):
			$event->setHost($this->params['REMOTE_HOST']);
		endif;
	
		// sets browser related properties
		$event->setBrowser();
		
		//Clean Query Strings
		if ($this->config['clean_query_strings'] == true):
			$event->cleanQueryStrings();
		endif;
			
		return $event->log();
		
	}
	
}


?>