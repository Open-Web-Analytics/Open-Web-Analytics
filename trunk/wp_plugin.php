<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: v1.0
Author URI: http://www.openwebanalytics.com
*/

require_once 'owa_env.php';
require_once 'owa_wp.php';

/**
 * WORDPRESS Constants
 * You should not need to change these.
 */

// Check to see what version of wordpress is running
$owa_wp_version = owa_parse_version($wp_version);

// check to see if OWA is installed
$current_plugins = get_option('active_plugins');


//print md5(get_settings('siteurl'));
$owa_config = array();
// Caller Configuration overides
$owa_config['report_wrapper'] = 'wrapper_wordpress.tpl';

define('OWA_DB_TYPE', 'mysql');
define('OWA_DB_NAME', DB_NAME);
define('OWA_DB_HOST', DB_HOST);
define('OWA_DB_USER', DB_USER);
define('OWA_DB_PASSWORD', DB_PASSWORD);

$owa_config['fetch_config_from_db'] = true;     // The host of your db
$owa_config['images_url'] = '../wp-content/plugins/owa/public/i';
$owa_config['public_url'] = '../wp-content/plugins/owa/public';
$owa_config['reporting_url'] = $_SERVER['PHP_SELF'].'?page=owa/public/reports';
$owa_config['admin_url'] = $_SERVER['PHP_SELF'].'?page=owa/public/admin';
$owa_config['main_url'] = $_SERVER['PHP_SELF'].'?page=owa/public/main.php';
$owa_config['main_absolute_url'] = get_bloginfo('url').$owa_config['main_url'];
$owa_config['action_url'] = get_bloginfo('url').'/index.php?owa_specialAction';
$owa_config['log_url'] = get_bloginfo('url').'/index.php?owa_logAction';
$owa_config['inter_report_link_template'] = '%s/%s&%s';
//$owa_config['inter_admin_link_template'] = '%s/%s&%s';
$owa_config['link_template'] = '%s&%s';
$owa_config['authentication'] = 'wordpress';
$owa_config['site_id'] = md5(get_settings('siteurl'));

// Needed to avoid a fetch of configuration from db during installation
if (($_GET['action'] == 'activate') && ($_GET['plugin'] == 'owa/wp_plugin.php')):
	$owa_config['fetch_config_from_db'] = false;
endif;

// Needed for WP 1.x installs to avoid fetch of config from db duruign installation
if ($owa_wp_version[0] == '1'):
	if (isset($_GET['activate']) && $_GET['activate'] == 'true'):
		$owa_config['fetch_config_from_db'] = false;
	endif;
endif;

// Create new instance of caller class object
$owa_wp = &new owa_wp($owa_config);

// WORDPRESS Filter and action hook assignment

// Installation logic
if ($owa_wp_version[0] == '1'):
	
	if (isset($_GET['activate']) && $_GET['activate'] == 'true'):
		owa_install();
	endif;

elseif ($owa_wp_version[0] == '2'):

	add_action('activate_owa/wp_plugin.php', 'owa_install');

endif;

// Register Wordpress Event Handlers
add_action('template_redirect', 'owa_main');
add_action('wp_footer', array(&$owa_wp, 'placeHelperPageTags'));
add_filter('post_link', 'owa_post_link');
add_action('init', array(&$owa_wp, 'handleSpecialActionRequest'));
add_action('init', 'owa_set_user_level');
add_filter('bloginfo', 'add_feed_sid');
add_action('admin_menu', 'owa_dashboard_menu');
add_action('comment_post', array(&$owa_wp, 'logComment'));
add_action('admin_menu', 'owa_options_menu');

/**
 * Sets the user level in caller params for use in auth module.
 *
 */
function owa_set_user_level() {
	
	global $owa_wp, $user_level, $user_login, $user_ID, $user_email, $user_identity;
	
	$owa_wp->params['caller']['wordpress']['user_data'] = array(
	
	'user_level' 	=> $user_level, 
	'user_ID'		=> $user_ID,
	'user_login'	=> $user_login,
	'user_email'	=> $user_email,
	'user_identity'	=> $user_identity);
	
	return;	
}
	
/**
 * This is the main logger function that calls owa on each normal web request.
 * Application specific request data should be set here. as part of the $app_params array.
 */

function owa_main() {
	
	global $user_level;
	
	// Wordpress 2.x check to see if the page request is a preview
	if (function_exists(is_preview)):
		if (is_preview()):
			return;
		endif;
	endif;
	
	// Check to see if user is an admin
	if($user_level == '10'):
		return;
	endif;
	
	owa_log();
	
	return;
	
}


function owa_log() {

	global $owa_wp;
	
	// WORDPRESS SPECIFIC DATA //
	
	// Get the type of page
	$app_params['page_type'] = owa_get_page_type();
	
	//Check to see if this is a Feed Reeder
	if(is_feed()):
		$app_params['is_feedreader'] = true;
		$app_params['feed_format'] = $_GET['feed'];
	endif;
	
	// Track users by the email address of that they used when posting a comment
	$app_params['user_email'] = $_COOKIE['comment_author_email_'.COOKIEHASH]; 
	
	// Track users who have a named account
	$app_params['user_name'] = $_COOKIE['wordpressuser_'.COOKIEHASH];
	
	// Get Title of Page
	$app_params['page_title'] = owa_get_title($app_params['page_type']);
	
	// Get Feed Tracking Code
	//$app_params['feed_subscription_id'] = ''
	
	// Get Source Tracking code
	//$app_params['source'] = '';
	
	// Provide an ID for this instance in case you want to track multiple blogs/sites seperately
	//$app_params['site_id'] = '';
	
	// Process the request by calling owa
	
	$owa_wp->log($app_params);
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
	elseif (is_single()):
		$type = "Post";
	elseif (is_page()):
		$type = "Page";
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
	elseif (is_archive()):
		$type = "Archive";
	elseif (is_feed()):
		$type = "Feed";
	endif;
	
	return $type;
}

/**
 * Adds a GUID to the feed URL.
 *
 * @param array $binfo
 * @return string $newbinfo
 */
function add_feed_sid($binfo) {
	
	global $owa_wp;
	
	if (strstr($binfo, "feed=")):
	
		$newbinfo = $owa_wp->add_feed_tracking($binfo);
	
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

	global $owa_wp;
	global $doing_rss;
	
	if($doing_rss):
	
		$tracked_link = $owa_wp->add_link_tracking($link);
		return $tracked_link;
	else:
		return $link;
	endif;
	

}

/**
 * Schema and setting installation
 *
 */
function owa_install() {

	global $user_level;
	global $owa_wp;
	
	//check to see if the user has permissions to install or not...
	get_currentuserinfo();
	
	if ($user_level < 8):
    	return;
    else:
    	//$conf = &owa_settings::get_settings();
		//['fetch_config_from_db'] = false;
    	
    	$owa_wp->config['fetch_config_from_db'] = false;
    	
    	$owa_wp->config['db_type'] = 'mysql';
    	
    	$install_params = array('site_id' => $conf['site_id'], 
    							'name' => get_bloginfo('name'),
    							'domain' => get_settings('siteurl'), 
    							'description' => get_bloginfo('description'),
    							'action' => 'base.installEmbedded');
    							
    	$owa_wp->handleRequest($install_params);
	endif;

	return;
}



/**
 * Adds Analytics sub tab to admin dashboard screens.
 *
 */
function owa_dashboard_menu() {

	if (function_exists('add_submenu_page')):
		add_submenu_page('index.php', 'OWA Dashboard', 'Analytics', 1, dirname(__FILE__), 'owa_dashboard_report');
    endif;
    
    return;

}

function owa_dashboard_report() {
	
	global $owa_wp;
	
	$params = array();
	$params['do'] = 'base.reportDashboard';
	echo $owa_wp->handleRequest($params);
	
	return;
	
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
	
	global $owa_wp;
	
	$params = array();
	$params['view'] = 'base.options';
	$params['subview'] = 'base.optionsGeneral';
	echo $owa_wp->handleRequest($params);
	
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