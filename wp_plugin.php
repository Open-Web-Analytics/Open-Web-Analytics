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
// THIS PLUGIN IS NOW DEPRECATED.
// See: https://github.com/Open-Web-Analytics/owa-wordpress-plugin/wiki/Migrating-from-the-Old-Bundled-Plugin
//



// if this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define the plugin path constant
define('OWA_WP_PATH', plugin_dir_path( __FILE__ ) );

// Hook package creation
add_action('plugins_loaded', array( 'owa_wp_plugin', 'getInstance'), 10 );

// Installation hook
//register_activation_hook(__FILE__, array('owa_wp_plugin', 'install') );


/////////////////////////////////////////////////////////////////////////////////


/**
 * OWA WordPress Plugin Class
 *
 */
class owa_wp_plugin {
		
	/**
	 * Constructor
	 *
	 */	
	function __construct() {
		
		// needed???
		ob_start();
		
	}
	
	/**
	 * Singelton
	 */
	static function getInstance() {
		
		static $o;
	
		if ( ! isset( $o ) ) {
			
			$o = new owa_wp_plugin();
			$o->_init();
		}
		
		return $o;
	}
	
	
	function _init() {
		
		add_action('admin_notices', array($this, 'migrateNag') );
	}
		
	function updateNag() {
		
		echo '<BR><div class="notice notice-error "><p>'. '<b>Open Web Analytics</b> updates are required before tracking can continue. <a href="/wp-admin/admin.php?page=owa-analytics">Please update now!</a></p></div>';
	}
	
	function migrateNag() {
		
		$url = network_admin_url( 'plugin-install.php?s=padams&tab=search&type=author' );
    
		$template = '<BR><div class="notice notice-error "><p><b>This version of the Open Web Analytics plugin is now deprecated!</b> Please install the <a href="%s">new official OWA Integration Plugin</a> from the WordPress repository. <a href="https://github.com/Open-Web-Analytics/owa-wordpress-plugin/wiki/Migrating-from-the-Old-Bundled-Plugin">Learn more here!</a></p></div>';
		
		echo sprintf($template, $url);
		
	}
}

?>