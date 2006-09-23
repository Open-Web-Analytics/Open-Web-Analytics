<?

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

//include_once('../set_env.php');
require_once(OWA_BASE_DIR.'/owa_php.php');
require_once(OWA_BASE_DIR.'/owa_template.php');
require_once(OWA_BASE_DIR.'/owa_installer.php');
require_once(OWA_BASE_DIR.'/owa_user.php');
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_site.php');

/**
 * OWA Installation Script
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Initialize OWA with db fetch off as there is no db yet.
$config['fetch_config_from_db'] = false;
$owa = new owa_php($config);

// Clean Input arrays
if ($_POST):
	$params = owa_lib::inputFilter($_POST);
else:
	$params = owa_lib::inputFilter($_GET);
endif;

// Create Template Objects
$page = & new owa_template;
$body = & new owa_template; 


if (!empty($owa->config['db_name']) && 
	!empty($owa->config['db_password']) && 
	!empty($owa->config['db_host']) &&
	!empty($owa->config['db_user'])):
	
	//Load Installer Object
	$installer = new owa_installer($params);

	
		
		// Perform schem fro base schema
		$check = $installer->plugins['base_schema']->check_for_schema();
		
		// Check for prior install
		if ($check == true):
			$status_msg = "OWA appears to already be installed. If you would like to re-install OWA, drop the tables and try again.";
			$db_state = false;
			$body_tpl = 'installer_error.tpl';
		else:
			$page->set('page_title', 'Installation Wizard');
			$body->set('page_h1', 'Welcome to the OWA Installer');
			$body_tpl = 'installer_welcome.tpl';
		endif;
	
else: 
	$db_state = false;
	$status_msg = "Your database connection settings are not complete. Check the owa_config.php file and try again.";
	$body_tpl = 'installer_error.tpl';
endif;


$body->set('db_state', $db_state);

// Form Handlers

switch ($params['action']) {
	
	case "env_check":
		$errors = array();
		// Perform DB connection check
		if ($installer->db->connection_status == false):
			$db_state = false;
			$env_status['db_status'] = array('status' => false, 'msg' => "Could not connect to the database. Please check your database connection settings and try again.");
			$body_tpl = 'installer_error.tpl';
		else:
			$db_state = true;
		endif;
	
		$params['owa_page'] = 'install_wizard';
		$params['step'] = 'env_status';
		break;
	
	case "install_base":
		
		$install_status = $installer->plugins['base_schema']->install();
	
		if ($install_status != false):
		
			if ($install_status === true):
				// Stock success msg
				$status_msg = 'The databse schema was installed successfully.';
			else:
				// Package specific msg
				$status_msg = $install_status;
			endif;
			$params['owa_page'] = 'install_wizard';
			$params['step'] = 'set_admin_user';
			
		else:
			$status_msg = 'The installation failed. See error log for details.';
			$params['owa_page'] = 'error';
		endif;

		break;
	
	case "install_package":
		
		$install_status = $installer->plugins[$params['package']]->install();
	
		if ($install_status != false):
		
			if ($install_status === true):
				// Stock success msg
				$status_msg = 'The installation was a success.';
			else:
				// Package specific msg
				$status_msg = $install_status;
			endif;
			$status_msg = 'Installation was a success.';
			$params['owa_page'] = 'package_selection';
		else:
			$status_msg = 'The installation failed. See error log for details.';
			$params['owa_page'] = 'error';
		endif;

		break;
		
	case "save_admin_user":
		$u = new owa_user;
		$auth = & owa_auth::get_instance();
		$u->user_id = $params['user_id'];
		$u->password = $auth->encryptPassword($params['password']);
		$u->real_name = $params['real_name'];
		$u->email_address = $params['email_address'];
		$u->role = 'admin';
		$u->save();
		$status_msg = 'Admin user created successfully.';
		$params['owa_page'] = 'install_wizard';
		$params['step'] = 'site_info';			
		break;
	case "save_site_info":	
		
		$installer->plugins['base_schema']->addDefaultSite();
		
		/*$site = new owa_site;
		
		$site->name = $params['name'];
		$site->description = $params['description'];
		$site->save();
		*/
		$status_msg = 'Site Profile was created successfully.';
		$params['owa_page'] = 'install_wizard';
		$params['step'] = 'finish';	
				
		break;
}


// Page Controlers

switch ($params['owa_page']) {
	
	case "install_wizard":
		
		switch ($params['step']) {
			
			case "env_status":
				$body_tpl = 'installer_env_status.tpl';
				$page->set('page_title', 'Environment Status');
				$body->set('page_h1', 'Server Environment Status');
				$body->set('env_status', $env_status);
				break;
			case "site_info":
				$body_tpl = 'installer_site_info.tpl';
				$page->set('page_title', 'Site Information');
				$body->set('page_h1', 'Enter some information about your site');
				break;
			case "set_admin_user":
				$body_tpl = 'installer_set_admin_user.tpl';
				$page->set('page_title', 'Administrator Account Profile Setup');
				$body->set('page_h1', 'Setup your Administration User Profile.');
				break;
			case "finish":
				$body_tpl = 'installer_finish.tpl';
				$page->set('page_title', 'Installation Complete');
				$body->set('page_h1', 'Open Web Analytics Installation Complete');
				$body->set('site_id', 1);				
				break;
		}
		break;
		
	case "package_selection":
		$body_tpl = 'installer_package_selection.tpl';
		$page->set('page_title', 'Package selection');
		$body->set('page_h1', 'Select a package to Install');
		$available_packages = $installer->get_available_packages();
		$body->set('available_packages', $available_packages);
		$installed_packages = $installer->get_installed_packages();
		$body->set('installed_packages', $installed_packages);
		break;
		
	case "success":
		$body_tpl = 'installer_success.tpl';
		$page->set('page_title', 'Installation Complete');
		$body->set('page_h1', 'Installation Complete');
		break;
		
	case "error":
		$body_tpl = 'installer_error.tpl';
		$page->set('page_title', 'Installation Error');
		$body->set('page_h1', 'There was an Error During Installation');
		break;
	
}

// Global Template assignments
$page->set_template($owa->config['report_wrapper']);// This is the outer template
$body->set_template($body_tpl);// This is the inner template
$body->set('config', $owa->config);
$body->set('status_msg', $status_msg);
$page->set('content', $body);

// Render Page
echo $page->fetch();

?>