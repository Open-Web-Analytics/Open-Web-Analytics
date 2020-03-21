<?php 

/*
Plugin Name: Open Web Analytics
Plugin URI: http://www.openwebanalytics.com
Description: This plugin enables Wordpress blog owners to use the Open Web Analytics Framework.
Author: Peter Adams
Version: master
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


// Hook package creation
add_action('plugins_loaded', array( 'owa_wp_plugin', 'getInstance'), 10 );


/////////////////////////////////////////////////////////////////////////////////


/**
 * OWA WordPress Plugin Class
 *
 */
class owa_wp_plugin {
	
	// cmd array
	var $cmds = array();
	// plugin options
	var $options = array(
		
		'track_feed_links'			=> true,
		'feed_tracking_medium' 		=> 'feed',
		'feed_subscription_param' 	=> 'owa_sid'
	);
	
	/**
	 * Constructor
	 *
	 */	
	function __construct() {
		
		// needed???
		ob_start();
		
		// fetch plugin options from DB and combine them with defaults.
		$options = get_option( 'owa_wp_plugin' );
		
		if ( $options ) {
			
			$this->options = array_merge_recursive($this->options, $options);
		}
		
		/* register WordPress hooks and filters. */
		
		// insert javascript tracking tag
		add_action('wp_head', array( $this,'insertTrackingTag' ), 100 );
		
		// add tracking to feed entry permalinks
		add_filter('the_permalink_rss', array( $this, 'decorateFeedEntryPermalink' ) );
		
		// add tracking to feed subscription links
		add_filter('bloginfo_url', array($this, 'decorateFeedSubscriptionLink' ) );
		
		// register settings page
		//
		
		// Actions if OWA is available as a library
		if( $this->isOwaAvailable() ) {
			
			// handle API calls and other requests
			add_action('init', array( $this, 'handleSpecialActionRequest' ) );
			
			// @todo find a way for these methods to POST these to the OWA instance instead of via OWA's PHP Tracker
			$this->defineActionHooks();
			
			// Register admin pages
			add_action('admin_menu', array( $this, 'registerAdminPages' ) );
			
			// Create a new tracked site in OWA.
			// @todo move this to REST API call when it's ready.
			add_action('wpmu_new_blog', array($this, 'createTrackedSiteForNewBlog'), 10, 6);
			
			// Installation hook
			register_activation_hook(__FILE__, 'owa_install');
			
		}
	}
	
	/**
	 * Get an option value
	 */
	function getOption( $key ) {
		
		$options = array();
		$options = $this->options;
		if ( array_key_exists( $key, $options ) ) {
			
			return $this->options[ $key ];
		}
	}
	
	/**
	 * Set an option value
	 */
	function setOption( $key, $value ) {
		
		$this->options[ $key ] = $value;
	}
	
	/**
	 * Singelton
	 */
	static function getInstance() {
		
		static $o;
	
		if ( ! isset( $o ) ) {
			
			$o = new owa_wp_plugin();
		}
		
		return $o;
	}
	
	/**
	 * Callback for admin_menu hook
	 */
	function registerAdminPages() {

		if (function_exists('add_submenu_page')) {
			
			if ( $this->isOwaAvailable() ) {
	
				add_submenu_page('index.php', 'OWA Dashboard', 'OWA Dashboard', 1, dirname(__FILE__), array( $this, 'pageController') );
			}
				
			if (function_exists('add_options_page')) {
	
				add_options_page('OWA Options', 'OWA', 8, basename(__FILE__), array($this, 'options_page') );
			}
    	}
	}
	
	/**
	 * Callback for reporting dashboard/pages 
	 */
	function pageController() {
		
		if ( $this->isOwaAvailable() ) {
		
			$owa = $this->getOwaInstance();	
			echo $owa->handleRequest();
		}
	}
	
	/**
	 * Callback for OWA settings page
	 */
	function options_page() {
	
		$owa = $this->getOwaInstance();
		
		$params = array();
		
		$params['do'] = owa_coreAPI::getRequestParam( 'do' );
		
		if ( ! $params['do'] ) {
			
			$params['do'] = 'base.optionsGeneral';		
		}
	
		echo $owa->handleRequest($params);
		
	}
	
	/**
	 * Hooks for tracking WordPress Admin actions
	 */
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
	
	/**
	 * OWA Schema and setting installation
	 *
	 */
	function install() {
	
		define('OWA_INSTALLING', true);
		
		$params = array();
		//$params['do_not_fetch_config_from_db'] = true;
	
		$owa = $this->getOwaInstance($params);
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
	
	function handleSpecialActionRequest() {

		$owa = $this->getOwaInstance();
		
		owa_coreAPI::debug("hello from WP special action handler");
		
		return $owa->handleSpecialActionRequest();
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
	 * Determines the type of WordPress page
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
		
	// check to see if OWA is available as a php library on the same server
	function isOwaAvailable() {
		
		return true;
	}
	
	// gets an instance of your OWA as a php object
	function getOwaInstance() {
		
		static $owa;
		
		if( empty( $owa ) ) {
		
			if ( $this->isOwaAvailable() ) {
				
				require_once('owa_env.php');
				require_once(OWA_BASE_CLASSES_DIR.'owa_php.php');
				
				// create owa instance w/ config
				$owa = new owa_php();
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
				$dispatch->attachFilter('auth_status', 'owa_wp_plugin::wpAuthUser', 0);	
			}
		}
		
		return $owa;
	}
	
	/**
	 * OWA Authenication filter
	 *
	 * Uses WordPress priviledge system to determine OWA authentication levels.
	 *
	 * @return boolean
	 */
	static function wpAuthUser($auth_status) {

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
				$cu->setRole( owa_wp_plugin::translateAuthRole( $current_user->roles ) );
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
	
	/**
	 * Translate WordPress to OWA Authentication Roles
	 *
	 * @param $roles	array	array of WP roles
	 * @return	string
	 */ 
	static function translateAuthRole( $roles ) {
		
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
	
	/**
	 * Insert Tracking Tag
	 *
	 * Adds javascript tracking tag int <head> of all pages.
	 * 
	 */
	function insertTrackingTag() {
		
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
		$owa = $this->getOwaInstance();
		
		// set any cmds
		$this->setPageType();
		$this->setPageTitle();
		
		// convert cmds to string and pass to tracking tag template	
		$options = array( 'cmds' => $this->cmdsToString() );
		
		// place the tracking tag
		$owa->placeHelperPageTags(true, $options);	
	}	
	
	/**
	 * Adds tracking source param to links in feeds
	 *
	 * @param string $link
	 * @return string
	 */
	function decorateFeedEntryPermalink($link) {
		
		// check for presence of '?' which is not present under URL rewrite conditions
	
		if ( $this->getOption( 'track_feed_links' ) ) {
		
			if ( strpos($link, "?") === false ) {
				// add the '?' if not found
				$link .= '?';
			}
			
			// setup link template
			$link_template = "%s&amp;%s=%s&amp;%s=%s";
				
			return sprintf($link_template,
						   $link,
						   'owa_medium',
						   $this->getOption( 'feed_tracking_medium' ),
						   $this->getOption( 'feed_subscription_param' ),
						   $_GET[ $this->getOption( 'feed_subscription_param' ) ] 
			);
		}
	}
	
	/**
	 * Wordpress filter function adds a GUID to the feed URL.
	 *
	 * @param array $binfo
	 * @return string $newbinfo
	 */
	function decorateFeedSubscriptionLink( $binfo ) {
		
		$is_feed = strpos($binfo, "feed=");
		
		if ( $is_feed && $this->getOption( 'track_feed_links' ) ) {
			
			$guid = crc32(getmypid().microtime());
		
			$newbinfo = $binfo . "&amp;" . $this->getOption('feed_subscription_param') . "=" . $guid;
		
		} else { 
			
			$newbinfo = $binfo;
		}
		
		return $newbinfo;
	}
	
	// create a new tracked site.
	function createTrackedSiteForNewBlog($blog_id, $user_id, $domain, $path, $site_id, $meta) {
	
		$owa = $this->getOwaInstance();
		$sm = owa_coreAPI::supportClassFactory( 'base', 'siteManager' );
		$sm->createNewSite( $domain, $domain, '', ''); 
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
	 *
	 * Trackes new and edited post actions. Including custom post types.
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
	
}

?>