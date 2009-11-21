<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics.com
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: v1.2
Author URI: http://www.openwebanalytics.com
*/

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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

require_once('owa_env.php');

// Public folder URI
define('OWA_PUBLIC_URL', get_bloginfo('url').'/wp-content/plugins/owa/');

// Check to see what version of wordpress is running
$owa_wp_version = owa_parse_version($wp_version);

// hack for eliminating WP from printing db errors prior to WP v2.1 
if ($owa_wp_version[0] == '2'):
	if ($owa_wp_version[1] == '0'):
		$wpdb->hide_errors();
	endif;
endif;

// Filter and Action hook assignments
//if (!is_admin()) {
	add_action('template_redirect', 'owa_main');
//}

add_action('wp_footer', 'owa_footer');
add_filter('the_permalink_rss', 'owa_post_link');
add_action('init', 'owa_handleSpecialActionRequest');
add_filter('bloginfo_url', 'add_feed_sid');
add_action('admin_menu', 'owa_dashboard_menu');
add_action('comment_post', 'owa_logComment');
add_action('admin_menu', 'owa_options_menu');
// Installation hook
register_activation_hook(__FILE__,'owa_install');
/////////////////////////////////////////////////////////////////////////////////


/**
 * Singleton Method
 *
 * Returns an instance of OWA
 *
 * @return $owa object
 */

function &owa_getInstance($params = array()) {
	
	static $owa;
	
	if(!empty($owa)):
		return $owa;
	else:
	
		require_once(OWA_BASE_CLASSES_DIR.'owa_wp.php');
		
		// Build the OWA wordpress specific config overrides array
		$owa_config = array();
		
		// OWA DATABASE CONFIGURATION 
		// Will use Wordpress config unless there is a config file present.
		// OWA uses this to setup it's own DB connection seperate from the one
		// that Wordpress uses.
		$owa_config['db_type'] = 'mysql';
		$owa_config['db_name'] = DB_NAME;
		$owa_config['db_host'] = DB_HOST;
		$owa_config['db_user'] = DB_USER;
		$owa_config['db_password'] = DB_PASSWORD;
		
		$owa_config['report_wrapper'] = 'wrapper_wordpress.tpl';
		$owa_config['images_url'] = OWA_PUBLIC_URL.'i/';
		$owa_config['images_absolute_url'] = get_bloginfo('url').'/wp-content/plugins/owa/public/i/';
		$owa_config['main_url'] = '../wp-admin/index.php?page=owa';
		$owa_config['main_absolute_url'] = get_bloginfo('url').'/wp-admin/index.php?page=owa';
		$owa_config['action_url'] = get_bloginfo('url').'/index.php?owa_specialAction';
		$owa_config['log_url'] = get_bloginfo('url').'/index.php?owa_logAction=1';
		$owa_config['link_template'] = '%s&%s';
		$owa_config['site_id'] = md5(get_settings('siteurl'));
		$owa_config['is_embedded'] = true;
		$owa_config['delay_first_hit'] = true;
	
		$config = array_merge($owa_config, $params);
		
		$owa = new owa_wp($config);
		
		// Access WP current user object to check permissions
		$current_user = owa_getCurrentWpUser();
      	     
		// preemptively set OWA's current user info and mark as authenticated so that
		// downstream controllers don't have to authenticate
		$cu =&owa_coreAPI::getCurrentUser();
		$cu->setUserData('user_id', $current_user->user_login);
		owa_coreAPI::debug("Wordpress User_id: ".$current_user->user_login);
		$cu->setUserData('email_address', $current_user->user_email);
		$cu->setUserData('real_name', $current_user->user_identity);
		$cu->setRole(owa_translate_role($current_user->roles));
		owa_coreAPI::debug("Wordpress User Role: ".print_r($current_user->roles, true));
		owa_coreAPI::debug("Wordpress Translated OWA User Role: ".$cu->getRole());
		$cu->setAuthStatus(true);
		//owa_coreAPI::debug("Wordpress  User Object: ".print_r($current_user, true));		
		return $owa;
		
	endif;
	
}

function owa_getCurrentWpUser() {

	// Access WP current user object to check permissions
	global $current_user;
    get_currentuserinfo();
    return $current_user;

}

// translates wordpress roles to owa roles
function owa_translate_role($roles) {
	
	if (!empty($roles)) {
	
		if (in_array('administrator', $roles)) {
			$owa_role = 'admin';
		} elseif (in_array('editor', $roles)) {
			$owa_role = 'viewer';
		} elseif (in_array('author', $roles)) {
			$owa_role = 'viewer';
		} elseif (in_array('contributor', $roles)) {
			$owa_role = 'viewer';
		} elseif (in_array('subscriber', $roles)) {
			$owa_role = 'everyone';
		} else {
			$owa_role = 'everyone';
		}
		
	} else {
		$owa_role = 'everyone';
	}
	
	return $owa_role;
}


function owa_handleSpecialActionRequest() {

	$owa = owa_getInstance();
	return $owa->handleSpecialActionRequest();
}

function owa_logComment() {

	$owa = owa_getInstance();
	return $owa->logComment();
}



/**
 * Prints helper page tags to the footers of templates.
 * 
 */
function owa_footer() {
	
	$owa = owa_getInstance();
	
	$owa->placeHelperPageTags();
	
	
	return;
	
}	

/**
 * This is the main logging controller that is called on each request.
 * 
 */
function owa_main() {
	
	global $user_level;
	
	$owa = owa_getInstance();
	$event = $owa->makeEvent();
	
	// Don't log if the page request is a preview - Wordpress 2.x or greater
	if (function_exists(is_preview)) {
		if (is_preview()) {
			$event->set('do_not_log',true);
		}
	}

	$event->setEventType('base.page_request');
	// Set the type of page
	$event->set('page_type', owa_get_page_type());
	
	//Check to see if this is a Feed Reeder
	if(is_feed()) {
		$event->setEventType('base.feed_request');
		$event->set('feed_format', $_GET['feed']);
	}
	
	$event->set($owa->getSetting('base', 'source_param'), $_GET[$owa->getSetting('base', 'ns').$owa->getSetting('base', 'source_param')]);
	
	$cu = &owa_coreAPI::getCurrentUser();
	
	// Track users by the email address of that they used when posting a comment
	//$app_params['user_email'] = $cu->getUserData('email_address'); 
	
	// Track users who have a named account
	//$app_params['user_name'] = $cu->getUserData('user_id');
	
	// Get Title of Page
	$event->set('page_title', owa_get_title($event->get('page_type')));
	
	// Create Site ID
	$event->set('site_id', $owa->createSiteId(get_settings('siteurl')));
	
	// Process the request by calling owa
	$owa->trackEvent($event);
	
	return;
}

/**
 * Determines the title of the page being requested
 *
 * @param string $page_type
 * @return string $title
 */
function owa_get_title($page_type) {

	if ($page_type == "Home"):
		$title = get_bloginfo('name');
	elseif ($page_type == "Search Results"):
		$title = "Search Results for \"".$_GET['s']."\"";	
	elseif ($page_type == "Page" || "Post"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Author"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Category"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Month"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Day"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Year"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Time"):
		$title = wp_title($sep = '', $display = 0);
	elseif ($page_type == "Feed"):
		$title = wp_title($sep = '', $display = 0);
	endif;	
	
	return $title;
}

/**
 * Determines the type of page being requested
 *
 * @return string $type
 */
function owa_get_page_type() {	
	
	if (is_home()):
		$type = "Home";
	elseif (is_attachment()):
		$type = "Attachment";
	elseif (is_page()):
		$type = "Page";
	// general page catch, should be after more specific post types	
	elseif (is_single()):
		$type = "Post";
	elseif (is_feed()):
		$type = "Feed";
	elseif (is_author()):
		$type = "Author";
	elseif (is_category()):
		$type = "Category";
	elseif (is_search()):
		$type = "Search Results";
	elseif (is_month()):
		$type = "Month";
	elseif (is_day()):
		$type = "Day";
	elseif (is_year()):
		$type = "Year";
	elseif (is_time()):
		$type = "Time";
	elseif (is_tag()):
		$type = "Tag";
	elseif (is_tax()):
		$type = "Taxonomy";
	// general archive catch, should be after specific archive types	
	elseif (is_archive()):
		$type = "Archive";
	endif;
	
	return $type;
}

/**
 * Wordpress filter function adds a GUID to the feed URL.
 *
 * @param array $binfo
 * @return string $newbinfo
 */
function add_feed_sid($binfo) {
	
	$owa = owa_getInstance();
	
	$test = strpos($binfo, "feed=");
	
	if ($test == true):
		$newbinfo = $owa->add_feed_tracking($binfo);
	
	else: 
		
		$newbinfo = $binfo;
		
	endif;
	
	return $newbinfo;

}

/**
 * Adds tracking source param to links in feeds
 *
 * @param string $link
 * @return string
 */
function owa_post_link($link) {

	$owa = owa_getInstance();

	return $owa->add_link_tracking($link);
		
}

/**
 * Schema and setting installation
 *
 */
function owa_install() {

	global $user_level;
	
	$params = array();
	define('OWA_INSTALLING', true);
	//$params['do_not_fetch_config_from_db'] = true;

	$owa = owa_getInstance($params);
	
	//check to see if the user has permissions to install or not...
	get_currentuserinfo();
	
	if ($user_level < 8):
    	return;
    else:
    	$owa->config['fetch_config_from_db'] = false;
    	
    	$owa->config['db_type'] = 'mysql';
    	
    	$install_params = array('site_id' => md5(get_settings('siteurl')), 
    							'name' => get_bloginfo('name'),
    							'domain' => get_settings('siteurl'), 
    							'description' => get_bloginfo('description'),
    							'action' => 'base.installEmbedded');
    							
    	$owa->handleRequest($install_params);
	endif;

	return;
}

/**
 * Adds Analytics sub tab to admin dashboard screens.
 *
 */
function owa_dashboard_menu() {

	if (function_exists('add_submenu_page')):
		add_submenu_page('index.php', 'OWA Dashboard', 'Analytics', 1, dirname(__FILE__), 'owa_pageController');
    endif;
    
    return;

}

/**
 * Produces the analytics dashboard
 * 
 */
function owa_dashboard_report() {
	
	$owa = owa_getInstance();
	
	$params = array();
	$params['do'] = 'base.reportDashboard';
	echo $owa->handleRequest($params);
	
	return;
	
}

function owa_pageController() {

	$owa = owa_getInstance();
	
	$do = owa_coreAPI::getRequestParam('do');
	
	if (empty($do)) {
		$params = array();
		$params['do'] = 'base.reportDashboard';	
	}
	
	echo $owa->handleRequest($params);

}

/**
 * Adds Options page to admin interface
 *
 */
function owa_options_menu() {
	
	if (function_exists('add_options_page')):
		add_options_page('Options', 'OWA', 8, basename(__FILE__), 'owa_options_page');
	endif;
    
    return;
}

/**
 * Generates Options Management Page
 *
 */
function owa_options_page() {
	
	$owa = owa_getInstance();
	
	$params = array();
	$params['view'] = 'base.options';
	$params['subview'] = 'base.optionsGeneral';
	echo $owa->handleRequest($params);
	
	return;
}

/**
 * Parses string to get the major and minor version of the 
 * instance of wordpress that is running
 *
 * @param string $version
 * @return array
 */
function owa_parse_version($version) {
	
	$version_array = explode(".", $version);
   
   return $version_array;
	
}

?>