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

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Base Package Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_baseModule extends owa_module {
	
	
	function owa_baseModule() {
		
		$this->name = 'base';
		$this->display_name = 'Open Web Analytics';
		$this->group = 'Base';
		$this->author = 'Peter Adams';
		$this->version = '1.0';
		$this->description = 'Base functionality for OWA.';
		$this->config_required = false;
		$this->required_schema_version = 2;
		
		
		$this->owa_module();
		//$this->c->set('base', 'schema_version', '1');
		return;
	}
	
	/**
	 * Registers Admin panels with the core API
	 *
	 */
	function registerAdminPanels() {
		
		$this->addAdminPanel(array('do' 			=> 'base.optionsGeneral', 
									'priviledge' 	=> 'admin', 
									'anchortext' 	=> 'Main Configuration',
									'group'			=> 'General',
									'order'			=> 1));
		
		if ($this->config['is_embedded'] != true):
		
			$this->addAdminPanel(array('view' 			=> 'base.users', 
										'priviledge' 	=> 'admin', 
										'anchortext' 	=> 'User Management',
										'group'			=> 'General',
										'order'			=> 2));
									
		endif;
									
		$this->addAdminPanel(array('do' 			=> 'base.sites', 
									'priviledge' 	=> 'admin', 
									'anchortext' 	=> 'Site Roster',
									'group'			=> 'General',
									'order'			=> 3));
								
		$this->addAdminPanel(array('do' 			=> 'base.optionsModules', 
									'priviledge' 	=> 'admin', 
									'anchortext' 	=> 'Modules Admin',
									'group'			=> 'General',
									'order'			=> 3));
									
		return;
		
	}
	
	function registerNavigation() {
		
		$this->addNavigationLink('Reports', '', 'base.reportDashboard', 'Dashboard', 1);
		$this->addNavigationLink('Reports', '', 'base.reportVisitors', 'Visitors', 3);
		$this->addNavigationLink('Reports', '', 'base.reportTraffic', 'Traffic', 2);
		$this->addNavigationLink('Reports', '', 'base.reportContent', 'Content', 4);
		$this->addNavigationLink('Reports', 'Content', 'base.reportClicks', 'Click Map Report', 1);
		$this->addNavigationLink('Reports', 'Content', 'base.reportFeeds', 'Feeds', 2);
		$this->addNavigationLink('Reports', 'Content', 'base.reportEntryExits', 'Entry & Exit Pages', 3);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportVisitsGeolocation', 'Geo-location', 1);								$this->addNavigationLink('Reports', 'Visitors', 'base.reportHosts', 'Domains', 2);								
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportVisitorsLoyalty', 'Visitor Loyalty', 3);							$this->addNavigationLink('Reports', 'Traffic', 'base.reportKeywords', 'Keywords', 1);								
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportAnchortext', 'Inbound Link Text', 2);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportSearchEngines', 'Search Engines', 3);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportReferringSites', 'Referring Web Sites', 4);							$this->addNavigationLink('Reports', 'Dashboard', 'base.reportDashboardSpy', 'Spy Dashboard', 1);	
		
		return;
		
	}
	
	/**
	 * Registers Event Handlers with queue queue
	 *
	 */
	function _registerEventHandlers() {
		
		// User management
		$this->_addHandler(array('base.set_password', 
								'base.reset_password', 
								'base.new_user_account'), 
								'userHandlers');
		
		// Page Requests
		$this->_addHandler(array('base.page_request', 'base.first_page_request'), 'requestHandlers');
		
		// Sessions
		$this->_addHandler(array('base.page_request_logged', 'base.first_page_request_logged'), 'sessionHandlers');
		
		// Clicks
		$this->_addHandler('base.click', 'clickHandlers');
		
		// Documents
		$this->_addHandler(array('base.page_request_logged', 'base.first_page_request_logged', 'base.feed_request_logged'), 'documentHandlers');
		
		// Referers
		$this->_addHandler('base.new_session', 'refererHandlers');
		
		// User Agents
		$this->_addHandler(array('base.feed_request', 'base.new_session'), 'userAgentHandlers');
		
		// Hosts
		$this->_addHandler(array('base.feed_request', 'base.new_session'), 'hostHandlers');
		
		// Hosts
		$this->_addHandler('base.new_comment', 'commentHandlers');
		
		// Hosts
		$this->_addHandler('base.feed_request', 'feedRequestHandlers');
		
		// User management
		$this->_addHandler('base.new_session', 'visitorHandlers');

		// Nofofcation handlers
		$this->_addHandler('base.new_session', 'notifyHandlers');

		
		return;
		
	}
	
	function _registerEntities() {
		
		//$this->_addEntity('testtable');
								
		$this->_addEntity(array('request', 
								'session', 
								'document', 
								'feed_request', 
								'click', 
								'ua', 
								'referer', 
								'site', 
								'visitor', 
								'host',
								'exit',
								'os',
								'impression', 
								'configuration'));
		
	}
	
	
	
	
}


?>