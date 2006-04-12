<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: v1.0
Author URI: http://www.openwebanalytics.com
*/

require_once 'owa_controller.php';
require_once 'tables.php';
require_once 'wa_env.php';
require_once 'wa_settings_class.php';

/**
 * WORDPRESS Constants
 * You should not need to change these.
 */

// URL special requests can be intercepted on
define ('WA_BASE_URL', get_bloginfo('url').'/index.php');

// URL used for graph generation requests
define ('OWA_GRAPH_URL', WA_BASE_URL);

// URL stem used for inter report navigation
define ('WA_REPORTING_URL', $_SERVER['PHP_SELF'].'?page=owa/reports');

// Path to images used in reports
define ('OWA_IMAGES_PATH', '../wp-content/plugins/owa/reports/i/');

/**
 * These are set to pass wa the db connection params that wordpress uses. 
 * These are also persisted when in async mode.
 */
define('WA_DB_NAME', DB_NAME);     // The name of the database
define('WA_DB_USER', DB_USER);     // Your db username
define('WA_DB_PASSWORD', DB_PASSWORD); // ...and password
define('WA_DB_HOST', DB_HOST);     // The host of your db

/**
 * This is the main logger function that calls wa on each normal web request.
 * Application specific request data should be set here. as part of the $app_params array.
 */
function owa_main() {

	// WORDPRESS SPECIFIC DATA //
	
	// Get the type of page
	
	$app_params['page_type'] = owa_get_page_type();
	
	//Check to see if this is a Feed Reeder
	
	if(is_feed()):
		$app_params['is_feedreader'] = true;
	endif;
	
	// Track users by the email address of that they used when posting a comment
	$app_params['user_email'] = $_COOKIE['comment_author_email_'.COOKIEHASH]; 
	
	// Track users who have a named account
	$app_params['user_name'] = $_COOKIE['wordpressuser_'.COOKIEHASH];
	
	// Get Title of Page
	$app_params['page_title'] = owa_get_title($app_params['page_type']);
	
	// Get Feed Subscriber ID
	$app_params['feed_subscription_id'] = $_GET['wa_sid'];
	
	// Get Source Tracking Code
	
	$app_params['source'] = $_GET['from'];
	
	// Provide an ID for this instance in case you want to track multiple blogs/sites seperately
	//$app_params['site_id'] = '';
	
	// Process the request by calling wa
	owa::process_request($app_params);
	
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

	global $doing_rss;
	
	if (strstr($binfo, "feed=")):
		
		$guid = crc32(posix_getpid().microtime());
		$newbinfo = $binfo."&"."wa_sid"."=".$guid;
		
	else: 
	
		$newbinfo = $binfo;
	
	endif;
	
	return $newbinfo;

}

function owa_is_comment() {

	owa::process_comment();
	
	return;

}

/**
 * Adds tracking params to links in feeds
 *
 * @param string $link
 * @return string
 */
function owa_post_link($link) {

	global $doing_rss;
	if($doing_rss):
		if (!empty($_GET['wa_sid'])):
			$newlink = $link."&amp;"."from=feed"."&amp;"."wa_sid=".$_GET['wa_sid'];
			return $newlink;
		else:
			return $link;
		endif;
	else:
		return $link;
	endif;

}

/**
 * Schema and setting installation
 *
 */
function owa_install() {

	global $user_level, $wpdb;
	
	require_once 'wa_settings_class.php';
	
	$config = wa_settings::get_settings();

	//check to see if the user has permissions to install or not...
	get_currentuserinfo();
	
	if ($user_level < 8):
   
    	return;
	
	endif;
	
	// See if tables exist.
	$table_name = $config['ns'].$config['requests_table'];
	
	if($wpdb->get_var("show tables like '$table_name'") != $table_name):
		
		$sql = wa_schema::create_tables();
		
		require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
      	dbDelta($sql[0]);
	
	else:
	
		return;
	
	endif;
	
	update_option('wa_schema_version', $sql[1], 'Version of the Schema in use');
	
	return;
   
}

/**
 * Adds Analytics sub tab to admin dashboard screens.
 *
 */
function owa_dashboard_view() {

	if (function_exists('add_submenu_page')):
		add_submenu_page('index.php', 'WA Dashboard', 'Analytics', 8, dirname(__FILE__) . '/reports/dashboard_report.php');
    endif;
    
    return;

}
/**
 * Inserts a web bug for new visitors that will process the special first_hit cookie
 *
 */
function owa_tag() {

	if (empty($_COOKIE['wa_v'])):
		$bug = "<img src=\"".WA_BASE_URL."?first_hit=true\">";
		echo $bug;
	endif;
	
	return;

}

/**
 * Handler for various special http requests
 *
 */
function owa_intercept() {

	// First hit request handler
	if (isset($_GET['first_hit'])):
		
		if (isset($_COOKIE['wa_first_hit'])):
			owa_main();
		endif;

		header('Content-type: image/gif');
		header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');
		header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
		
		printf(
		  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
		  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
		);
		exit;
	endif;
	
	// Graph request handler
	if (isset($_GET['graph'])):
		
		$params = array(
				'api_call' 		=> $_GET['graph'],
				'period'			=> $_GET['period']
			
			);
			
		owa::get_graph($params);
		exit;
	endif;
	
	return;

}

/**
 * Special mail based error handler
 *
 * @param integer $errno
 * @param string $errmsg
 * @param string $filename
 * @param integer $linenum
 * @param unknown_type $vars
 */
function owa_err_mailer($errno, $errmsg, $filename, $linenum, $vars) {

	$vars2 = print_r($vars, false);

	print "Critical User Error" . $filename . " linenum: " . $linenum . " \n" . $errmsg . " \n" . $vars2;

	return;
}

/**
 * Adds Options page to admin interface
 *
 */
function owa_options() {
	
	if (function_exists('add_options_page')):
		add_options_page('Options', 'OWA', 8, basename(__FILE__), 'owa_options_page');
	endif;
    
    return;
}

function owa_options_page() {
	
	require_once 'template_class.php';
	require_once 'wa_settings_class.php';
	
	// Fetch config
	
	$config = &wa_settings::get_settings();
	
	//Setup templates
	$options_page = & new Template;
	$options_page->set_template($options_page->config['report_wrapper']);
	$body = & new Template; 
	$body->set_template('options.tpl');// This is the inner template
	$body->set('config', $config);
	$body->set('page_title', 'OWA Options');
	$options_page->set('content', $body);
	// Make Page
	echo $options_page->fetch();
	
	return;
}

// WORDPRESS Filter and action hooks.

if (isset($_GET['activate']) && $_GET['activate'] == 'true'):

	add_action('init', 'owa_install');
  
endif;

add_action('template_redirect', 'owa_main');
add_action('wp_footer', 'owa_tag');
add_filter('post_link', 'owa_post_link');
add_filter('bloginfo', 'add_feed_sid');
add_action('admin_menu', 'owa_dashboard_view');
add_action('init', 'owa_intercept');
add_action('comment_post', 'owa_is_comment');
add_action('admin_menu', 'owa_options');
?>
