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

require_once('owa_db.php');

/**
 * Settings
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_settings {
	
	/**
	 * Databse access object
	 *
	 * @var unknown_type
	 */
	var $db;
	
	/**
	 * Configuration properties
	 *
	 * @var array
	 */
	var $properties;
	
	/**
	 * Error Handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Constructor
	 *
	 * @return owa_settings
	 */
	function owa_settings() {
		
		$this->properties = $this->get_settings();
		$this->e = &owa_error::get_instance();
		return;
	}
	
	
	/**
	 * Returns the configuration
	 *
	 * @return 	array
	 * @access 	public
	 * @static 
	 */
	function &get_settings() {
	
		static $config;
		
		if(!isset($config)):
			
			// get base config
			$config = &owa_settings::get_default_config();
			
			//load overrides from config file
			if (file_exists($config['config_file_path'])):
				include_once ($config['config_file_path']);
				if (!empty($OWA_CONFIG)):
					foreach ($OWA_CONFIG as $key => $value) {
			
						// update current config
						$config[$key] = $value;
					
					}
				endif;
				// Setup special public URLs
				
				$base_url  = "http";
		
				if($_SERVER['HTTPS']=='on'):
					$base_url .= 's';
				endif;
						
				$base_url .= '://'.$_SERVER['SERVER_NAME'];
				
				if($_SERVER['SERVER_PORT'] != 80):
					$base_url .= ':'.$_SERVER['SERVER_PORT'];
				endif;
				
				$config['public_url'] = $base_url . $OWA_CONFIG['public_url'];
				
				$config['action_url'] = $OWA_CONFIG['public_url']."/action.php";
				$config['images_url'] = $OWA_CONFIG['public_url']."/i";
				$config['reporting_url'] = $OWA_CONFIG['public_url']."/reports/index.php";
				$config['admin_url'] = $OWA_CONFIG['public_url']."/admin/index.php";
				
			endif;	
		endif;

		return $config;
	}
	
	/**
	 * Returns default settings array
	 *
	 * @return array
	 */
	function get_default_config() {
		
		return array(
	
			'ns'							=> 'owa_',
			'visitor_param'					=> 'v',
			'session_param'					=> 's',
			'last_request_param'			=> 'last_req',
			'first_hit_param'				=> 'first_hit',
			'feed_subscription_param'		=> 'sid',
			'source_param'					=> 'from',
			'graph_param'					=> 'graph',
			'period_param'					=> 'period',
			'document_param'				=> 'document',
			'referer_param'					=> 'referer',
			'site_id'						=> '1',
			'configuration_id'				=> '1',
			'session_length'				=> '1800',
			'debug_to_screen'				=> false,
			'requests_table'				=> 'requests',
			'sessions_table'				=> 'sessions',
			'referers_table'				=> 'referers',
			'ua_table'						=> 'ua',
			'os_table'						=> 'os',
			'documents_table'				=> 'documents',
			'sites_table'					=> 'sites',
			'hosts_table'					=> 'hosts',
			'config_table'					=> 'configuration',
			'version_table'					=> 'version',
			'feed_requests_table'			=> 'feed_requests',
			'visitors_table'				=> 'visitors',
			'impressions_table'				=> 'impressions',
			'clicks_table'					=> 'clicks',
			'exits_table'					=> 'exits',
			'db_class'						=> '',
			'db_type'						=> '',
			'db_name'						=> OWA_DB_NAME,
			'db_user'						=> OWA_DB_USER,
			'db_password'					=> OWA_DB_PASSWORD,
			'db_host'						=> OWA_DB_HOST,
			'resolve_hosts'					=> true,
			'log_feedreaders'				=> true,
			'log_robots'					=> false,
			'log_sessions'					=> true,
			'log_dom_clicks'				=> true,
			'delay_first_hit'				=> true,
			'async_db'						=> false,
			'clean_query_string'			=> true,
			'fetch_refering_page_info'		=> true,
			'query_string_filters'			=> '',
			'async_log_dir'					=> OWA_BASE_DIR . '/logs/',
			'async_log_file'				=> 'events.txt',
			'async_lock_file'				=> 'owa.lock',
			'async_error_log_file'			=> 'events_error.txt',
			'notice_email'					=> '',
			'error_handler'					=> 'production',
			'error_log_file'				=> OWA_BASE_DIR . '/logs/errors.txt',
			'browscap.ini'					=> OWA_BASE_DIR . '/conf/browscap.ini',
			'browscap_supplemental.ini'		=> OWA_BASE_DIR . '/conf/browscap_supplemental.ini',
			'search_engines.ini'			=> OWA_BASE_DIR . '/conf/search_engines.ini',
			'query_strings.ini'				=> OWA_BASE_DIR . '/conf/query_strings.ini',
			'os.ini'						=> OWA_BASE_DIR . '/conf/os.ini',
			'robots.ini'					=> OWA_BASE_DIR . '/conf/robots.ini',
			'db_class_dir'					=> OWA_BASE_DIR . '/plugins/db/',
			'templates_dir'					=> OWA_BASE_DIR . '/templates/',
			'plugin_dir'					=> OWA_BASE_DIR . '/plugins/',
			'install_plugin_dir'			=> OWA_BASE_DIR . '/plugins/install/',
			'reporting_dir'					=> OWA_BASE_DIR . '/public/reports/',
			'geolocation_lookup'            => true,
			'geolocation_service'			=> 'hostip',
			'report_wrapper'				=> 'default_wrap.tpl',
			'config_file_path'				=> OWA_BASE_DIR . '/conf/owa_config.php',
			'fetch_config_from_db'			=> true,
			'announce_visitors'				=> false,
			'public_url'					=> '',
			'action_url'					=> '',
			'images_url'					=> '',
			'reporting_url'					=> '',
			'p3p_policy'					=> 'NOI NID ADMa OUR IND UNI COM NAV',
			'inter_report_link_template'	=> '%s?page=%s&%s', //base_url?report=report_name&get...
			'inter_admin_link_template'		=> '%s?admin=%s&%s', //base_url?report=report_name&get...
			'graph_link_template'			=> '%s?owa_action=graph&name=%s&%s', //action_url?...
			'owa_user_agent'				=> 'Open Web Analytics Bot '.OWA_VERSION,
			'owa_rss_url'					=> 'http://www.openwebanalytics.com/?feed=rss2',
			'use_summary_tables'			=> false,
			'summary_framework'				=> ''
			);
	}
	
	/**
	 * Save Config to database
	 * Site_id needs to be in the array so that it can be changed via the options GUI
	 *
	 * @param array $settings
	 */
	function save($new_config) {
		
		$config = &owa_settings::get_settings();
		
		$this->db = &owa_db::get_instance();
		//print sprintf("Saving new config for site id: %s", $new_new_config['site_id']);
		$check = $this->db->get_row(
			sprintf("SELECT settings from %s where id = '%s'",
					$config['ns'].$config['config_table'],
					$new_config['configuration_id']
					));
					
					
		if (empty($check)):			
		
			$this->db->query(
				sprintf("
				INSERT into %s (id, settings) VALUES ('%s', '%s')",
				$config['ns'].$config['config_table'],
				$new_config['configuration_id'],
				serialize($new_config))
				
			);
		
		else:
		
			$this->db->query(
				sprintf("
				UPDATE %s SET settings = '%s' where id = '%s'",
				$config['ns'].$config['config_table'],
				serialize($new_config),
				$new_config['configuration_id']
				)
				
			);
		
		endif;
		
		return;
	}
	
	/**
	 * Fetch Config from database
	 *
	 * @return array
	 */
	function &fetch($configuration_id = 1) {
		
		$config = &owa_settings::get_settings();
		
		static $settings;
		
		if (!isset($settings)):
		
			$this->db = &owa_db::get_instance();
			
			$sql = sprintf("
					SELECT 
						settings 
					from 
						%s
					WHERE
						id = '%s'",
					$config['ns'].$config['config_table'],
					$configuration_id);
			
			$settings = $this->db->get_row($sql);
			
			// Special Debug variable because error loggers will not have been initialized yet.
			$from_db = true;
			//$e = &owa_error::get_instance();
			//$e->debug(debug_backtrace());
		
		endif;
		
		return unserialize($settings['settings']);
		
	}

}

?>
