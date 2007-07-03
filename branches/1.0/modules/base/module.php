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
		
		$this->owa_module();
		
		return;
	}
	
	/**
	 * Registers Admin panels with the core API
	 *
	 */
	function registerAdminPanels() {
		
		$this->addAdminPanel(array('view' 			=> 'base.optionsGeneral', 
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
									
		$this->addAdminPanel(array('view' 			=> 'base.sites', 
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
		
		$this->addNavigationLink(array('view' 			=> 'base.reportDocument', 
										'nav_name'		=> 'subnav',
										'ref'			=> 'base.reportClicks',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Click Map Report',
										'order'			=> 1));
		
		
		$this->addNavigationLink(array('view' 			=> 'base.report', 
										'nav_name'		=> 'top_level_report_nav',
										'ref'			=> 'base.reportDashboard',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Dashboard',
										'order'			=> 1));
										
		$this->addNavigationLink(array('view' 			=> 'base.report', 
										'nav_name'		=> 'top_level_report_nav',
										'ref'			=> 'base.reportVisitors',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Visitors',
										'order'			=> 3));
										
		$this->addNavigationLink(array('view' 			=> 'base.report', 
										'nav_name'		=> 'top_level_report_nav',
										'ref'			=> 'base.reportTraffic',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Traffic Sources',
										'order'			=> 2));	
		
		$this->addNavigationLink(array('view' 			=> 'base.report', 
										'nav_name'		=> 'top_level_report_nav',
										'ref'			=> 'base.reportContent',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Content',
										'order'			=> 4));
		
		$this->addNavigationLink(array('view' 			=> 'base.report', 
										'nav_name'		=> 'top_level_report_nav',
										'ref'			=> 'base.reportFeeds',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Feeds',
										'order'			=> 5));
										
		$this->addNavigationLink(array('view' 			=> 'base.reportVisitors', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportVisitsGeolocation',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Geo-location',
										'order'			=> 1));
										
		$this->addNavigationLink(array('view' 			=> 'base.reportVisitors', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportHosts',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Domains',
										'order'			=> 1));								

		$this->addNavigationLink(array('view' 			=> 'base.reportVisitors', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportVisitorsLoyalty',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Visitor Loyalty',
										'order'			=> 1));

		$this->addNavigationLink(array('view' 			=> 'base.reportContent', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportEntryExits',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Entry & Exit Pages',
										'order'			=> 1));


		$this->addNavigationLink(array('view' 			=> 'base.reportTraffic', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportKeywords',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Keywords',
										'order'			=> 1));
										
		$this->addNavigationLink(array('view' 			=> 'base.reportTraffic', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportAnchortext',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Inbound Link Text',
										'order'			=> 2));
		
		$this->addNavigationLink(array('view' 			=> 'base.reportTraffic', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportSearchEngines',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Search Engines',
										'order'			=> 3));
		
		$this->addNavigationLink(array('view' 			=> 'base.reportTraffic', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportReferringSites',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Referring Web Sites',
										'order'			=> 3));
		
		$this->addNavigationLink(array('view' 			=> 'base.reportDashboard', 
										'nav_name'		=> 'sub_nav',
										'ref'			=> 'base.reportDashboardSpy',
										'priviledge' 	=> 'viewer', 
										'anchortext' 	=> 'Spy Dashboard',
										'order'			=> 1));
		
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
		
		$this->entities[] = 'request';
	}
	
	
}


?>