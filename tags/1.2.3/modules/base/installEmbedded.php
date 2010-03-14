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

require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Embedded Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installEmbeddedController extends owa_controller {
	
	function owa_installEmbeddedController($params) {
		
		return owa_installEmbeddedController::__construct($params);
	}
	
	function __construct($params) {
		
		parent::__construct($params);
		$this->c->setSettingTemporary('base', 'cache_objects', false);
		$this->setRequiredCapability('edit_modules');
		return;
	}
	
	function action() {
		
	    $service = &owa_coreAPI::serviceSingleton();
	    
	    $this->e->notice('starting Embedded install');
	    
	    //create config file
	    
	    $this->c->createConfigFile($this->params);
	    
		// install schema
		$base = $service->getModule('base');
		$status = $base->install();
		
		// schema was installed successfully
		if ($status == true):
		    
		    //create admin user
		    $this->createAdminUser();
		    
			$this->createDefaultSite();
			
			// Persist install complete flag. 
			$this->c->setSetting('base', 'install_complete', true);
			$save_status = $this->c->save();
			
			if ($save_status == true):
				$this->e->notice('Install Complete Flag added to configuration');
			else:
				$this->e->notice('Could not persist Install Complete Flag to the Database');
			endif;

		
			$this->setView('base.installFinishEmbedded');
			
			return;
		
		// schema was not installed successfully
		else:
			$this->e->notice('Aborting embedded install due to errors installing schema. Try dropping all OWA tables and try again.');
			return false;
		endif;		
			
	}
	
	function createAdminUser() {
		
		//create user entity
		$u = owa_coreAPI::entityFactory('base.user');
		// check to see if an admin user already exists
		$u->getByColumn('role', 'admin');
		$id_check = $u->get('id');		
		// if not then proceed
		if (empty($id_check)) {
	
			//Check to see if user name already exists
			$u->getByColumn('user_id', 'admin');
	
			$id = $u->get('id');
	
			// Set user object Params
			if (empty($id)) {
			
				// get current user info from host application
				$cu = owa_coreAPI::getCurrentUser();
				
				$user_params = array();
				$user_params['user_id'] = 'admin';
				$user_params['real_name'] = $cu->getUserData('real_name');
				$user_params['role'] = 'admin';
				$user_params['email_address'] = $cu->getUserData('email_address');
							          
				$temp_passkey = $u->createNewUser($user_params);
				
				owa_coreAPI::debug("OWA admin user created successfully.");
			
			} else {				
				owa_coreAPI::debug($this->getMsg(3306));
			}
		} else {
			owa_coreAPI::debug("OWA admin user already exists.");
		}

	}
	
	function createDefaultSite() {
	
		// Check to see if default site already exists
			$this->e->notice('Embedded install: checking for existance of default site.');
			$site = owa_coreAPI::entityFactory('base.site');
			$site->getByColumn('site_id', $this->getParam('site_id'));
			$id = $site->get('id');
		
			if(empty($id)):
		    	// Create default site
				$site->set('site_id', $this->getParam('site_id'));
				$site->set('name', $this->getParam('name'));
				$site->set('description', $this->getParam('description'));
				$site->set('domain', $this->getParam('domain'));
				$site->set('site_family', $this->getParam('site_family'));
				$site_status = $site->create();
			
				if ($site_status == true):
					$this->e->notice('Embedded install: created default site.');
				else:
					$this->e->notice('Embedded install: creation of default site failed.');
				endif;
			else:
				$this->e->notice(sprintf("Embedded install:  default site already exists (id = %s). nothing to do here.", $id));
			endif;
	}
	
	
}

?>