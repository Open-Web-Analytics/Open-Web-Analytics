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

class owa_processRequestController extends owa_controller {
	
	
	var $bcap; // browscap
	
	function owa_processRequestController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		// Control logic
		
		// Do not log if the first_hit cookie is still present.
        if (!empty($this->params[$this->config['first_hit_param']])):
			return;
		endif;
		
		// Setup request event
		$r = owa_coreAPI::supportClassFactory('base', 'requestEvent');
		
		// Set event properties
		$r->_setProperties($this->params);
		
		// set site id if not already set
		if (empty($this->params['site_id'])):
			$r->properties['site_id'] = $this->config['site_id'];
		endif;
		
		// Set Ip Address
		$r->setIp();
		
		// Set all time related properties
		$r->setTime();
		
		// Set Operating System
		$r->setOs($this->params['browscap_Platform']);
		
		// Set host related properties
		if ($this->config['resolve_hosts'] = true):
			$r->setHost($this->params['REMOTE_HOST']);
		endif;
		
		// sets browser related properties NEEDED?
		$r->setBrowser();
		
		// Set the uri or else construct it from environmental vars
		if (empty($this->params['page_url'])):
			$r->properties['page_url'] = owa_lib::get_current_url();
		endif;
		
		$r->properties['inbound_page_url'] = $r->properties['page_url'];
		
		// Strip session based URL params 
		$r->properties['page_url'] = $r->stripDocumentUrl($r->properties['page_url']);
		
		// Feed subscription tracking code
		$r->properties['feed_subscription_id'] = $this->params[$this->config['feed_subscription_param']];
		
		// Traffic Source code
		$r->properties['source'] = $this->params[$this->config['source_param']];
		
		//Check for what kind of page request this is
		if ($this->params['browscap_Crawler'] == true):
			$r->is_robot = true;
			$r->properties['is_robot'] = true;
			$r->properties['is_browser'] = false;
			$r->state = 'robot_request';
		elseif ($r->properties['is_feedreader'] == true || $this->params['browscap_isSyndicationReader'] == true):			$r->properties['is_feedreader'] == true;
			$r->properties['is_browser'] = false;
			$r->properties['feed_reader_guid'] = $r->setEnvGUID();
			$r->state = 'feed_request';
		else:
			$r->state = 'page_request';
			$r->properties['is_browser'] = true;
			$r->assign_visitor();
			$r->sessionize();
		endif;	
		
		//update last-request time cookie
		setcookie($this->config['ns'].$this->config['last_request_param'], 
					$r->properties['sec'], 
					time()+3600*24*365*30, 
					"/", 
					$r->setCookieDomain($this->params['HTTP_HOST']));
		
		return $r->log();
		
	}
	
	
	
}


?>