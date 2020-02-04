<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics.com
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: v1.6.0
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

// Filter and Action hook assignments

add_action('wp_head', 'owa_insertPageTags',100);
add_filter('the_permalink_rss', 'owa_post_link');
add_action('init', 'owa_handleSpecialActionRequest');
add_filter('bloginfo_url', 'add_feed_sid');
add_action('admin_menu', 'owa_dashboard_menu');
add_action('admin_menu', 'owa_options_menu');
add_action('wpmu_new_blog', 'owa_createTrackedSiteForNewBlog', 10, 6);

// Hook package creation
add_action('plugins_loaded', array( 'owa_wp_plugin', 'getInstance'), 10 );


// Installation hook
register_activation_hook(__FILE__, 'owa_install');

/////////////////////////////////////////////////////////////////////////////////

// create a new tracked site.
function owa_createTrackedSiteForNewBlog($blog_id, $user_id, $domain, $path, $site_id, $meta) {
	
	$owa = owa_getInstance();
	$sm = owa_coreAPI::supportClassFactory( 'base', 'siteManager' );
	$sm->createNewSite( $domain, $domain, '', ''); 
}

/**
 * Singleton Method
 *
 * Returns an instance of OWA
 *
 * @return $owa object
 */
function owa_getInstance() {
	
	static $owa;
	
	if( empty( $owa ) ) {
		
		require_once(OWA_BASE_CLASSES_DIR.'owa_wp.php');
		
		// create owa instance w/ config
		$owa = new owa_wp();
		$owa->setSiteId( md5( get_option( 'siteurl' ) ) );
		$owa->setSetting( 'base', 'report_wrapper', 'wrapper_wordpress.tpl' );
		$owa->setSetting( 'base', 'link_template', '%s&%s' );
		$owa->setSetting( 'base', 'main_url', '../wp-admin/index.php?page=owa' );
		$owa->setSetting( 'base', 'main_absolute_url', get_bloginfo('url').'/wp-admin/index.php?page=owa' );
		$owa->setSetting( 'base', 'action_url', get_bloginfo('url').'/index.php?owa_specialAction' );
		$owa->setSetting( 'base', 'api_url', get_bloginfo('url').'/index.php?owa_apiAction' );
		$owa->setSetting( 'base', 'is_embedded', true );
			
		// register allowedSitesList filter
		$dispatch = owa_coreAPI::getEventDispatch();
		// alternative auth method, sets auth status, role, and allowed sites list.
		$dispatch->attachFilter('auth_status', 'owa_wpAuthUser',0);
		//print_r( $current_user );
	}
	
	return $owa;
}

/**
 * OWA authentication filter method
 *
 * This filter function authenticates the user and populates the
 * the current user in OWA with the proper role, and allowed sites list.
 *
 * This method kicks in after all over OWA's built in auth methods have failed
 * in the owa_auth class.
 * 
 * @param 	$auth_status	boolean
 * @return	$auth_status	boolean
 */
function owa_wpAuthUser($auth_status) {

	$current_user = wp_get_current_user();
	
    if ( $current_user instanceof WP_User ) { 
    	// logged in, authenticated
    	$cu = owa_coreAPI::getCurrentUser();
    	
    	$cu->setAuthStatus(true);
    	
    	if (isset($current_user->user_login)) {
			$cu->setUserData('user_id', $current_user->user_login);
			owa_coreAPI::debug("Wordpress User_id: ".$current_user->user_login);
		}
		
		if (isset($current_user->user_email)) {	
			$cu->setUserData('email_address', $current_user->user_email);
		}
		
		if (isset($current_user->first_name)) {
			$cu->setUserData('real_name', $current_user->first_name.' '.$current_user->last_name);
			$cu->setRole(owa_translate_role($current_user->roles));
		}
		
		owa_coreAPI::debug("Wordpress User Role: ".print_r($current_user->roles, true));
		owa_coreAPI::debug("Wordpress Translated OWA User Role: ".$cu->getRole());
		
		// fetch the list of allowed blogs from WP
		$domains = array();
		$allowedBlogs = get_blogs_of_user($current_user->ID);
	
		foreach ( $allowedBlogs as $blog) {
			$domains[] = $blog->siteurl;		
		}
		
		// check to see if we are installing before trying to load sites
		// other wise you run into a race condition as config file
		// might not be created.
		if (! defined('OWA_INSTALLING') ) {
			// load assigned sites list by domain
    		$cu->loadAssignedSitesByDomain($domains);
    	}
    	
		$cu->setInitialized();
    
    	return true;
    
    } else {
    	// not logged in to WP and therefor not authenticated
    	return false;
    }	
}

function owa_getCurrentWpUser() {

	// Access WP current user object to check permissions
    return wp_get_current_user();

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
	owa_coreAPI::debug("hello from WP special action handler");
	return $owa->handleSpecialActionRequest();
	
}

/**
 * Prints helper page tags to the <head> of pages.
 * 
 */
function owa_insertPageTags() {
	
	// Don't log if the page request is a preview - Wordpress 2.x or greater
	if ( function_exists( 'is_preview' ) ) {
		
		if ( is_preview() ) {
			
			return;
		}
	}
	
	// dont log customizer previews either.
	if ( function_exists( 'is_customize_preview' ) ) {
		
		if ( is_customize_preview() ) {
			
			return;
		}
	}
	
	// dont log requests for admin interface pages.
	if ( function_exists( ' is_admin' ) && is_admin() ) {
		
		return;
	}

	
	// get instance of OWA
	$owa = owa_getInstance();
	
	// create a cmds object
	$wp_cmds = owa_wp_plugin::getInstance();
	$wp_cmds->addTrackerToPage();
	
	// convert cmds to string and feed to tracking tag template	
	$options = array( 'cmds' => $wp_cmds->cmdsToString() );
	
	// place the tracking tag
	$owa->placeHelperPageTags(true, $options);	
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

	define('OWA_INSTALLING', true);
	
	$params = array();
	//$params['do_not_fetch_config_from_db'] = true;

	$owa = owa_getInstance($params);
	$owa->setSetting('base', 'cache_objects', false);	
	$public_url =  get_bloginfo('wpurl').'/wp-content/plugins/owa/';
	
	$install_params = array('site_id' => md5(get_option('siteurl')), 
							'name' => get_bloginfo('name'),
							'domain' => get_option('siteurl'), 
							'description' => get_bloginfo('description'),
							'action' => 'base.installEmbedded',
							'db_type' => 'mysql',
							'db_name' => DB_NAME,
							'db_host' => DB_HOST,
							'db_user' => DB_USER,
							'db_password' => DB_PASSWORD,
							'public_url' =>  $public_url
							);
	
	$owa->handleRequest($install_params);
}

/**
 * Adds Analytics sub tab to admin dashboard screens.
 *
 */
function owa_dashboard_menu() {

	if (function_exists('add_submenu_page')) {
	
		add_submenu_page('index.php', 'OWA Dashboard', 'Analytics', 1, dirname(__FILE__), 'owa_pageController');
    }
}

/**
 * Main page handler.
 *
 */
function owa_pageController() {

	$owa = owa_getInstance();	
	echo $owa->handleRequest();

}

/**
 * Adds Options page to admin interface
 *
 */
function owa_options_menu() {
	
	if (function_exists('add_options_page')) {
	
		add_options_page('Options', 'OWA', 8, basename(__FILE__), 'owa_options_page');
	}
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

/**
 * Main Plugin Class
 *
 * Should have no dependancies on OWA when loaded.
 *
 */
class owa_wp_plugin {
	
	// cmd array
	var $cmds = array();
	
	/**
	 * Constructor
	 *
	 */	
	function __construct() {
		
		$this->defineActionHooks();
	}
	
	/**
	 * Singelton
	 *
	 */
	static function getInstance() {
		
		static $o;
	
		if ( ! isset( $o ) ) {
			
			$o = new owa_wp_plugin();
		}
		
		return $o;
	}
	
	
	function defineActionHooks() {
		
		
		// These hooks rely on accessing OWA server-side 
		// as a PHP object. 	
		
		if ( $this->isOwaAvailable() ) {
		
			// New Comment
			add_action( 'comment_post', array( $this, 'trackCommentAction' ), 10, 2);
			// Comment Edit
			add_action( 'transition_comment_status', array( $this, 'trackCommentEditAction' ), 10, 3);
			// User Registration
			add_action( 'user_register', array( $this, 'trackUserRegistrationAction' ) );
			// user login
			add_action( 'wp_login', array( $this, 'trackUserLoginAction' ) );
			// User Profile Update
			add_action( 'profile_update', array( $this, 'trackUserProfileUpdateAction' ), 10, 2);
			// Password Reset
			add_action( 'password_reset', array( $this, 'trackPasswordResetAction' ) );
			// Trackback
			add_action( 'trackback_post', array( $this, 'trackTrackbackAction' ) );
			// New Attachment
			add_action( 'add_attachment', array( $this, 'trackAttachmentCreatedAction' ) );
			// Attachment Edit
			add_action( 'edit_attachment', array( $this, 'trackAttachmentEditAction' ) );
			// Post Edit
			add_action( 'transition_post_status', array( $this, 'trackPostAction') , 10, 3);
			// New Blog (WPMU)
			add_action( 'wpmu_new_blog', array( $this, 'trackNewBlogAction') , 10, 5);
			
			
			// track feeds
			
			add_action('init', array( $this, 'addFeedTrackingQueryParams'));
			add_action( 'template_redirect', array( $this, 'trackFeedRequest'), 1 );
			
			

		}
		
		// These hooks do NOT rely on OWA being accessable via PHP
		
	}
	
	// Add query vars to WordPress
	function addFeedTrackingQueryParams() {
		
		global $wp; 
		
		// feed tracking param
		$wp->add_query_var('owa_sid'); 
		
	}
	
	/**
	 * Determines the title of the page being requested
	 *
	 * @param string $page_type
	 * @return string $title
	 */
	function getPageTitle() {
	
		$page_type = $this->getPageType();
		
		if ( $page_type == "Home" ) {
		
			$title = get_bloginfo( "name" );
		
		} elseif ( $page_type == "Search Results" ) {
			
			$title = "Search Results for \"" . get_search_query() . "\"";	
		
		} else {
			
			$title = wp_title($sep = '', $display = 0);
		}	
		
		return $title;
	}
	
	function setPageTitle() {
		
		$this->cmds[] = sprintf("owa_cmds.push(['setPageTitle', '%s' ]);", $this->getPageTitle() );
	}
	
	/**
	 * Determines the type of page being requested
	 *
	 * @return string $type
	 */
	function getPageType() {	
		
		if ( is_home() ) {
			$type = "Home";
		} elseif ( is_attachment() ){
			$type = "Attachment";
		} elseif ( is_page() ) {
			$type = "Page";
		// general page catch, should be after more specific post types	
		} elseif ( is_single() ) {
			$type = "Post";
		} elseif ( is_feed() ) {
			$type = "Feed";
		} elseif ( is_author() ) {
			$type = "Author";
		} elseif ( is_category() ) {
			$type = "Category";
		} elseif ( is_search() ) {
			$type = "Search Results";
		} elseif ( is_month() ) {
			$type = "Month";
		} elseif ( is_day() ) {
			$type = "Day";
		} elseif ( is_year() ) {
			$type = "Year";
		} elseif ( is_time() ) {
			$type = "Time";
		} elseif ( is_tag() ) {
			$type = "Tag";
		} elseif ( is_tax() ) {
			$type = "Taxonomy";
		// general archive catch, should be after specific archive types	
		} elseif ( is_archive() ) {
			$type = "Archive";
		} else {
			$type = '(not set)';
		}
		
		return $type;
	}
	
	function setPageType() {
		
		$this->cmds[] = sprintf("owa_cmds.push(['setPageType', '%s' ]);", $this->getPageType() );
	}
	
	function cmdsToString() {
		
		$out = '';
		
		foreach ( $this->cmds as $cmd ) {
			
			$out .= $cmd . " \n";	
		}
		
		return $out;
	}
	
	function getOption( $key ) {
		
		$options = get_option( 'owa_wp_plugin' );
		
		if ( $options && array_key_exists( $key, $options ) ) {
			
			return $options[ $key ];
		}
	}
	
	// check to see if OWA is available as a php library on the same server
	function isOwaAvailable() {
		
		return true;
	}
	
	// gets an instance of your OWA as a php object
	function getOwaInstance() {
		
		static $owa;
		
		
		if( empty( $owa ) ) {
		
			if ( $this->isOwaAvailable() ) {
		
				require_once(OWA_BASE_CLASSES_DIR.'owa_wp.php');
				
				// create owa instance w/ config
				$owa = new owa_wp();
				$owa->setSiteId( md5( get_option( 'siteurl' ) ) );
				$owa->setSetting( 'base', 'is_embedded', true );
			}
		}
		
		return $owa;
	}
	
	/**
	 * New Blog Action Tracker
	 */
	function trackNewBlogAction( $blog_id, $user_id, $domain, $path, $site_id ) {
	
		$owa = $this->getOwaInstance();
		$owa->trackAction('WordPress', 'Blog Created', $domain);
	}
	
	/**
	 * Edit Post Action Tracker
	 */
	function trackedPostEditAction( $post_id, $post ) {
		
		// we don't want to track autosaves...
		if( wp_is_post_autosave( $post ) ) {
			
			return;
		}
		
		$owa = $this->getOwaInstance();
		$label = $post->post_title;
		$owa->trackAction( 'WordPress', $post->post_type.' edited', $label );
	}
	
	/**
	 * Post Action Tracker
	 */
	function trackPostAction( $new_status, $old_status, $post ) {
		
		$action_name = '';
		
		// we don't want to track autosaves...
		if(wp_is_post_autosave( $post ) ) {
			
			return;
		}
		
		// or drafts
		if ( $new_status === 'draft' && $old_status === 'draft' ) {
			
			return;
		
		} 
		
		// set action label
		if ( $new_status === 'publish' && $old_status != 'publish' ) {
			
			$action_name = $post->post_type.' publish';
		
		} elseif ( $new_status === $old_status ) {
		
			$action_name = $post->post_type.' edit';
		}
		
		// track action
		if ( $action_name ) {	
		

			$owa = $this->getOwaInstance();
			owa_coreAPI::debug(sprintf("new: %s, old: %s, post: %s", $new_status, $old_status, print_r($post, true)));
			$label = $post->post_title;
			
			$owa->trackAction('WordPress', $action_name, $label);
		}
	}
	
	/**
	 * Edit Attachment Action Tracker
	 */
	function trackAttachmentEditAction( $post_id ) {
	
		$owa = $this->getOwaInstance();
		$post = get_post( $post_id );
		$label = $post->post_title;
		$owa->trackAction('WordPress', 'Attachment Edit', $label);
	}
	
	/**
	 * New Attachment Action Tracker
	 */
	function trackAttachmentCreatedAction( $post_id ) {
	
		$owa = $this->getOwaInstance();
		$post = get_post($post_id);
		$label = $post->post_title;
		$owa->trackAction('WordPress', 'Attachment Created', $label);
	}
	
	/**
	 * User Registration Action Tracker
	 */
	function trackUserRegistrationAction( $user_id ) {
		
		$owa = $this->getOwaInstance();
		$user = get_userdata($user_id);
		if (!empty($user->first_name) && !empty($user->last_name)) {
			$label = $user->first_name.' '.$user->last_name;	
		} else {
			$label = $user->display_name;
		}
		
		$owa->trackAction('WordPress', 'User Registration', $label);
	}
	
	/**
	 * User Login Action Tracker
	 */
	function trackUserLoginAction( $user_id ) {
	
		$owa = $this->getOwaInstance();
		$label = $user_id;
		$owa->trackAction('WordPress', 'User Login', $label);
	}
	
	/**
	 * Profile Update Action Tracker
	 */
	function trackUserProfileUpdateAction( $user_id, $old_user_data = '' ) {
	
		$owa = $this->getOwaInstance();
		$user = get_userdata($user_id);
		if (!empty($user->first_name) && !empty($user->last_name)) {
			$label = $user->first_name.' '.$user->last_name;	
		} else {
			$label = $user->display_name;
		}
		
		$owa->trackAction('WordPress', 'User Profile Update', $label);
	}
	
	/**
	 * Password Reset Action Tracker
	 */
	function trackPasswordResetAction( $user ) {
		
		$owa = $this->getOwaInstance();
		$label = $user->display_name;
		$owa->trackAction('WordPress', 'User Password Reset', $label);
	}
	
	/**
	 * Trackback Action Tracker
	 */
	function trackTrackbackAction( $comment_id ) {
		
		$owa = $this->getOwaInstance();
		$label = $comment_id;
		$owa->trackAction('WordPress', 'Trackback', $label);
	}
	
	function trackCommentAction( $id, $comment_data = '' ) {

		if ( $comment_data === 'approved' || $comment_data === 1 ) {
	
			$owa = $this->getOwaInstance();
			$label = '';
			$owa->trackAction('WordPress', 'comment', $label);
		}
	}
	
	function trackCommentEditAction( $new_status, $old_status, $comment ) {
		
		if ($new_status === 'approved') {
			
			if (isset($comment->comment_author)) {
				
				$label = $comment->comment_author; 
			
			} else {
			
				$label = '';
			}
			
			$owa = $this->getOwaInstance();
			$owa->trackAction('WordPress', 'comment', $label);
		}
	}
	
	// Tracks feed requests
	function trackFeedRequest() {
		
		if ( is_feed() ) {
		
			
			$owa = $this->getOwaInstance();
	
			if( $owa->getSetting( 'base', 'log_feedreaders') ) {
				
				owa_coreAPI::debug('Tracking WordPress feed request');			
				
				$event = $owa->makeEvent();
				// set event type
				$event->setEventType( 'base.feed_request' );
				// determine and set the type of feed
				$event->set( 'feed_format', get_query_var( 'feed' ) );
				$event->set( 'feed_subscription_id', get_query_var( 'owa_sid' ) );
				//$event->set( 'feed_subscription_id', $_GET['owa_sid'] );
				// track
				$owa->trackEvent( $event );
			}
		}
	}
	
	// adds the JavaScript Tracker cmds and script tag to the page.
	function addTrackerToPage() {
		
		$this->setPageType();
		$this->setPageTitle();
		
		//Output the script
		
	}
	
	function generateUniqueNumericId() {
		
		return crc32(getmypid().microtime());
	}
}

?>