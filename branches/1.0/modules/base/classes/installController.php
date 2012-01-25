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

require_once(OWA_DIR.'owa_controller.php');

/**
 * Abstract Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_installController extends owa_controller {

	var $is_installer = true;
			
	function __construct($params) {
		
		// needed just in case a re-install happens and updates are also needed.
		// tells the controller to skip the updates redirect
		if (!defined('OWA_INSTALLING')) {
			define('OWA_INSTALLING', true);
		}
		
		//$this->c->setSetting('base', 'cache_objects', false);
				
		return parent::__construct($params);
	}
			
	function pre() {
		
		if (owa_coreAPI::getSetting('base', 'install_complete')) {
			owa_coreAPI::debug('Install complete redirecting to base.installDetected');
			return $this->redirectBrowser('base.installDetected', false);
		}

		return;
	}
	
	function installSchema() {
		
		$service = owa_coreAPI::serviceSingleton();
		$base = $service->getModule('base');
		$status = $base->install();
		return $status;

	}
	
	function createAdminUser($email_address, $real_name = '', $password = '') {
		
		//create user entity
		$u = owa_coreAPI::entityFactory('base.user');
		// check to see if an admin user already exists
		$u->getByColumn('role', 'admin');
		$id_check = $u->get('id');		
		// if not then proceed
		if (empty($id_check)) {
	
			//Check to see if user name already exists
			$u->getByColumn('user_id', owa_user::ADMIN_USER_ID);
	
			$id = $u->get('id');
	
			// Set user object Params
			if (empty($id)) {
				
				// create random passsword if none provided.
				if ( ! $password ) {
					$password = $u->generateRandomPassword();
				}
				$ret = $u->createNewUser('admin', owa_user::ADMIN_USER_ID, $password, $email_address, $real_name);
				owa_coreAPI::debug("Admin user created successfully.");
				return $password;
				
			} else {				
				owa_coreAPI::debug($this->getMsg(3306));
			}
		} else {
			owa_coreAPI::debug("Admin user already exists.");
		}

	}
		
	function createDefaultSite($domain, $name = '', $description = '', $site_family = '', $site_id = '') {
	
		if (!$name) {
			$name = $domain;
		}
		
		$site = owa_coreAPI::entityFactory('base.site');
		
		if (!$site_id) {
			$site_id = $site->generateSiteId($domain);
		}
		
	
		// Check to see if default site already exists
		$this->e->notice('Checking for existence of default site.');
		
		// create site_id....how???
		$site->getByColumn('site_id', $site_id);
		$id = $site->get('id');
	
		if(empty($id)) {
	    	// Create default site
	    	$site->set('id', $site->generateId($site_id));
			$site->set('site_id', $site_id);
			$site->set('name', $name);
			$site->set('description', $description);
			$site->set('domain', $domain);
			$site->set('site_family', $site_family);
			$site_status = $site->create();
		
			if ($site_status == true) {
				$this->e->notice('Created default site.');
			} else {
				$this->e->notice('Creation of default site failed.');
			}
			
		} else {
			$this->e->notice(sprintf("Default site already exists (id = %s). nothing to do here.", $id));
		}
		
		return $site->get('site_id');
	}
	
	function checkDbConnection() {
		
		// Check DB connection status
		$db = owa_coreAPI::dbSingleton();
		$db->connect();
		if ($db->connection_status === true) {
			return true;
		} else {
			return false;
		}

	}

}

?>