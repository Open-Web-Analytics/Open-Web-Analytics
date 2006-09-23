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
require_once(OWA_BASE_DIR.'/owa_site.php');
require_once(OWA_BASE_DIR.'/owa_news.php');
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_user.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');

/**
 * OWA Options Admin interface
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Create instance of OWA
$owa = new owa_php;

$auth = &owa_auth::get_instance();

// Clean Input arrays
if ($_POST):
	$params = owa_lib::inputFilter($_POST);
else:
	$params = owa_lib::inputFilter($_GET);
endif;

// Create Template Objects
$page = & new owa_template;
$body = & new owa_template; 

$body_tpl = 'options.tpl';// This is the inner template
$body->set('page_title', 'OWA Options');


switch ($params['action']) {
	
	case "add_site":
		
		$site = new owa_site;
		$site->name = $params['name'];
		$site->description = $params['description'];
		$site->site_family = $params['site_family'];
		$site_id = $site->addNewSite();
		
		if ($site_id != false):
			$status_msg = "Site added Successfully";
			$params['owa_page'] = 'manage_sites';
			
		else:
			$page_h1 = 'Error';
			$body_tpl = 'error.tpl';
			$status_msg = "Site could not be added. Perhaps a site by that name already exists.";
		endif;
		break;

	case "update_config":
						
		$owa->save_config($params);
		break;
		
	case "reset_config":
		
		$owa->reset_config();	
		break;
	
	case "get_tag":
		$status_msg = "";
		$body_tpl = 'options_new_site_success.tpl';
		$page_h1 = 'The tracking tag for your site is below.';
		$body->set('site_id', $params['site_id']);
		$tag = $owa->requestTag($site_id);
		$body->set('tag', $tag);
		break;
	case "edit_user_profile":
		$u = new owa_user;
		$u->getUserByPK($params['user_id']);
		$u->email_address = $params['email_address'];
		$u->real_name = $params['real_name'];
		$u->role = $params['role'];
		$u->update();
		//$t = new owa_template();
		$url = $page->make_admin_link('options.php', array('owa_page' => 'user_roster_success'));
		owa_lib::redirectBrowser($url);
		
		break;
	case "add_new_user":
		$auth->authenticateUser('admin');
		$u = new owa_user;
		
		//Check to see if user name already exists
		$u->getUserByPK($params['user_id']);
		
		// Set user object Params
		if (empty($u->user_id)):
			$u->user_id = $params['user_id'];
			//print $u->user_id.'|';
			//print $params['user_id'];
			$u->real_name = $params['real_name'];
			$u->role = $params['role'];
			$u->email_address = $params['email_address'];
			$u->save();
			//Generate Initial Passkey and new account email
			$auth->setInitialPasskey($u->user_id);
			// Redirect user to success page
			$url = $page->make_admin_link('options.php', array('owa_page' => 'add_user_success'));
			owa_lib::redirectBrowser($url);
		else:
			$body_tpl = 'options_edit_user_profile.tpl';
			$body->set('user', get_class_vars($u));
			$body->set('roles', $auth->roles);	
			$body->set('page_title', 'OWA - Edit User Profile');
			$body->set('headline', 'Edit User Profile');
			$body->set('error_msg', 'That user name already exists');
		endif;
		break;
	case "delete_user":
		$auth->authenticateUser('admin');
		$u = new owa_user;
		$u->user_id = $params['user_id'];
		$u->delete();
		$url = $page->make_admin_link('options.php', array('owa_page' => 'delete_user_success'));
		owa_lib::redirectBrowser($url);
		break;
		
}

	switch ($params['owa_page']) {
		
		case "manage_sites":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_manage_sites.tpl';
			$site = new owa_site;
			$sites = $site->getAllSites();
			$body->set('sites', $sites);
			break;
		case "user_roster":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_user_roster.tpl';
			$u = new owa_user;
			$users = $u->getAllUsers();
			$body->set('users', $users);
			break;
		case "user_roster_success":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_user_roster.tpl';
			$u = new owa_user;
			$users = $u->getAllUsers();
			$body->set('users', $users);
			$body->set('status', 'User profile Saved Successfully.');
			break;
		case "add_user_success":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_user_roster.tpl';
			$u = new owa_user;
			$users = $u->getAllUsers();
			$body->set('users', $users);
			$body->set('status', 'User Added Successfully.');
			break;
		case "delete_user_success":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_user_roster.tpl';
			$u = new owa_user;
			$users = $u->getAllUsers();
			$body->set('users', $users);
			$body->set('status', 'User Deleted Successfully.');
			break;
		case "edit_user_profile":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_edit_user_profile.tpl';
			$u = new owa_user;
			$u->getUserByPK($params['user_id']);
			$body->set('user', get_object_vars($u));
			$body->set('roles', $auth->roles);	
			$body->set('page_title', 'OWA - Edit User Profile');
			$body->set('headline', 'Edit User Profile');	
			$body->set('action', 'edit_user_profile');
			break;	
		case "add_new_user":
			$auth->authenticateUser('admin');
			$body_tpl = 'options_edit_user_profile.tpl';
			$body->set('page_title', 'OWA - Add New User');
			$body->set('headline', 'Add New User');
			$body->set('roles', $auth->roles);	
			$body->set('action', 'add_new_user');
			break;
		default:
			$auth->authenticateUser('admin');
			$page_title = 'Configuration';
			$page_h1 = 'options';
		
	}


//Fetch latest OWA news
$rss = new owa_news;
$news = $rss->Get($rss->config['owa_rss_url']);

// Global Template assignments
$page->set_template($owa->config['report_wrapper']);// This is the outer template
$page->set('news', $news);
$body->set_template($body_tpl);// This is the inner template
$body->set('config', $owa->config);
$body->set('status_msg', $status_msg);
$body->set('page_h1', $page_h1);
$body->set('page_title', $page_title);
$page->set('content', $body);

// Render Page
echo $page->fetch();

?>