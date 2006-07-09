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

// Create Template Objects
$page = & new owa_template;
$body = & new owa_template; 


if (!empty($owa->config['db_name']) && 
	!empty($owa->config['db_password']) && 
	!empty($owa->config['db_host']) &&
	!empty($owa->config['db_user'])):
	
	//Load Installer Object
	$installer = new owa_installer;

	// Perform DB connection check
	if ($installer->db->connection_status == false):
		$db_state = false;
		$status_msg = "Could not connect to the database. Please check your database connection settings and try again.";
		$body_tpl = 'installer_error.tpl';
		
	else:
		$db_state = true;
		
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
	endif;
else: 
	$db_state = false;
	$status_msg = "Your database connection settings are not complete. Check the owa_config.php file and try again.";
	$body_tpl = 'installer_error.tpl';
endif;


$body->set('db_state', $db_state);

// Page Controlers

switch ($_GET['owa_page']) {
	
	case "db_info":
		$body_tpl = 'installer_db_info.tpl';
		$page->set('page_title', 'Site Information');
		$body->set('page_h1', 'Enter some information about your site');
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

// Form Handlers

switch ($_GET['action']) {
	
	case "install":
		
		$install_status = $owa->install($_GET['package']);
	
		if ($install_status != false):
		
			if ($install_status === true):
				// Stock success msg
				$status_msg = 'The installation was a success.';
			else:
				// Package specific msg
				$status_msg = $install_status;
			endif;
			$body->set('page_h1', 'Installation Complete');
			$body_tpl = 'installer_success.tpl';
		else:
			$status_msg = 'The installation failed. See error log for details.';
			$body->set('page_h1', 'Installation Problem');
			$body_tpl = 'installer_error.tpl';
		endif;

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