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

class owa_processRequestController extends owa_processEventController {
	
	function owa_processRequestController($params) {
		
		$this->owa_processEventController($params);
		
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function action() {
		
		// Control logic
		
		// Do not log if the first_hit cookie is still present.
        if (!empty($this->params[$this->config['first_hit_param'].'_'.$this->params['site_id']])):
        	$this->e->debug('Aborting request processing due to finding first hit cookie.');
			return;
		endif;
		
		// Setup request event
		$this->event = owa_coreAPI::supportClassFactory('base', 'requestEvent');
		
		// Pre process - set default and standard event property names
		$this->pre();
		
		// Set event properties
		$this->event->_setProperties($this->params['caller']);
		
		// set page type to unknown if not already set by caller
		if (empty($this->params['caller']['page_type'])):
			$this->event->properties['page_type'] = $this->getMsg(3600);
		endif;
		
		// Set the uri or else construct it from environmental vars
		if (empty($this->params['caller']['page_url'])):
			$this->event->properties['page_url'] = owa_lib::get_current_url();
		endif;
		
		$this->event->properties['inbound_page_url'] = $this->event->properties['page_url'];
		
		// Feed subscription tracking code
		$this->event->properties['feed_subscription_id'] = $this->params['caller'][$this->config['feed_subscription_param']];
		
		// Traffic Source code
		$this->event->properties['source'] = $this->params['caller'][$this->config['source_param']];
		
		//Check for what kind of page request this is
		if ($this->params['browscap']['Crawler'] == true):
			$this->event->is_robot = true;
			$this->event->properties['is_robot'] = true;
			$this->event->properties['is_browser'] = false;
			$this->event->state = 'robot_request';
			$this->event->properties['event_type'] = 'base.robot_request';
		elseif ($this->params['caller']['is_feedreader'] == true || $this->params['browscap']['isSyndicationReader'] == true):			
			$this->event->properties['is_feedreader'] == true;
			$this->event->properties['is_browser'] = false;
			$this->event->properties['is_feedreader'] = true;
			$this->event->properties['feed_reader_guid'] = $this->event->setEnvGUID();
			$this->event->state = 'feed_request';
			$this->event->properties['event_type'] = 'base.feed_request';
		else:
			$this->event->state = 'page_request';
			$this->event->properties['event_type'] = 'base.page_request'; 
			$this->event->properties['is_browser'] = true;
			$this->event->sessionize($this->event->properties['inbound_session_id']);
		endif;	
		
		//update last-request time cookie
		
		$this->event->setState($this->config['site_session_param'], $this->config['last_request_param'], $this->event->properties['sec'], true);
		
		
		
		// Post Process - cleanup after all properties are set
		$this->post();
		
		return $this->event->log();
		
	}
	
	
	
}


?>