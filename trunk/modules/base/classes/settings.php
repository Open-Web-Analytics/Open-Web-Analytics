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

/**
 * Settings Class
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
 	 * Configuration Entity
 	 * 
 	 * @var object configuration entity
 	 */
 	var $config;
 	
 	var $default_config;
 	
 	/**
 	 * Constructor
 	 * 
 	 * @param string id the id of the configuration array to load
 	 */
 	function owa_settings() {
		
 		$this->config = owa_coreAPI::entityFactory('base.configuration');
 		$this->getDefaultConfig();
 		
 		return;
 	}
 	
 	function applyModuleOverrides($module, $config) {
 		
 		// merge default config with overrides 
 		
 		if (!empty($config)):
 		
 			$in_place_config = $this->config->get('settings');
 			
 			$old_array = $in_place_config[$module];
 			
	 		$new_array = array_merge($old_array, $config);
 		
			$in_place_config[$module] = $new_array; 
			 		
		 	$this->config->set('settings', $in_place_config);
		 	
		 	//print_r($this->config->get('settings'));
		 	
	 	endif;
	 	
	 	
	 	
	 	return;
 		
 	}
 	
 	/**
 	 * Loads configuration from data store
 	 * 
 	 * @param string id  the id of the configuration array to load
 	 */
 	function load($id = 1) {
 
	 		if (!file_exists(OWA_BASE_MODULE_DIR.'config'.DIRECTORY_SEPARATOR.'base.php')):
	 		
	 			$db_config = owa_coreAPI::entityFactory('base.configuration');
	 			$db_config->getByPk('id', $id);
	 			$db_settings = unserialize($db_config->get('settings'));
	 			
	 			if (!empty($db_settings)):
	 			
	 				//$db_settings = unserialize($db_settings);
	 				
		 			$default = $this->config->get('settings');
		 			
		 			// merge default config with overrides fetched from data store
		 			
		 			$new_config = array();
		 			
		 			foreach ($db_settings as $k => $v) {
		 			
			 			$new_config[$k] = array_merge($default[$k], $db_settings[$k]);
			 			
		 			}
		 			
			 		$this->config->set('settings', $new_config);	
		 			
	 			endif;
	 			
	 			$db_id = $db_config->get('id');
	 			$this->config->set('id', $db_id);
	 			
	 		else:
	 			; // load config from file
	 		endif;
	 	
	 	
 		return;
 		
 	}
 	
 	/**
 	 * Fetches a modules entire configuration array
 	 * 
 	 * @param string $module The name of module whose configuration values you want to fetch
 	 * @return array Config values
 	 */
 	function fetch($module = '') {
	 	$v = $this->config->get('settings');
	 	
 		if (!empty($module)):
 		
 			return $v[$module];
		else:
			return $v['base'];
		endif;
 	}
 	
 	/**
 	 * updates or creates configuration values
 	 * 
 	 * @return boolean 
 	 */
 	/*function update() {
 		
 		// serialize array of values prior to update
 		$s = $this->config->get('settings');
 		$ss = serialize($s);
 		
		//check for id
 		$id = $this->config->get('id');
 		
 		// set id in case this is the first install
 		if (empty($id)):
 			
 			$cid = $this->get('base', 'configuration_id');
 			$this->config->set('id', $cid);
 			// persist entity
 			$this->config->set('settings', $ss);
 			$status = $this->config->create();
 		else:
 			// persist entity
 			$this->config->set('settings', $ss);
 			$status = $this->config->update();
 		endif;
 		
 		// revert back to unserialized version
 		$this->config->set('settings', $s);
 		
 		return $status;
 		
 	}*/
 	
 	/**
 	 * Accessor Method
 	 * 
 	 * @param string $module the name of the module
 	 * @param string $key the configuration key
 	 * @return unknown
 	 */
 	function get($module, $key) {
 		
 		$values = $this->config->get('settings');
 		
 		return $values[$module][$key];
 		
 	}
 	
 	/**
 	 * Sets configuration value
 	 * 
 	 * @param string $module the name of the module
 	 * @param string $key the configuration key
 	 * @param string $value the configuration value
 	 * @return boolean
 	 */
 	function set($module, $key, $value) {
 		
 		$values = $this->config->get('settings');
 		
 		$values[$module][$key] = $value;
 		
 		$this->config->set('settings', $values);
 		
 		return;
 	}
 	
 	/**
 	 * Replaces all values of a particular module's configuration
 	 * 
 	 */
 	function replace($module, $values) {
 		
 		$settings = $this->config->get('settings');
 		
 		$settings[$module] = $values;
 		
 		$this->config->set('settings', $settings);
 		
 		return;
 	}
 	
 	/**
 	 * Alternate Constructor for base module settings
 	 * Needed for backwards compatability with older classes
 	 * 
 	 */
 	function &get_settings($id = 1) {
 		
 		
 		static $config2;
 		
 		if (!isset($config2)):
 			print 'hello from alt constructor';
 			$config2 = &owa_coreAPI::configSingleton();
 		endif;
 		
 		return $config2->fetch('base');
 		
 	}
 	
 	function getDefaultConfig() {
 		
 		$config =  array('base' => array(
	
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
			'site_id'						=> '',
			'configuration_id'				=> '1',
			'session_length'				=> '1800',
			'requests_table'				=> 'request',
			'sessions_table'				=> 'session',
			'referers_table'				=> 'referer',
			'ua_table'						=> 'ua',
			'os_table'						=> 'os',
			'documents_table'				=> 'document',
			'sites_table'					=> 'site',
			'hosts_table'					=> 'host',
			'config_table'					=> 'configuration',
			'version_table'					=> 'version',
			'feed_requests_table'			=> 'feed_request',
			'visitors_table'				=> 'visitor',
			'impressions_table'				=> 'impression',
			'clicks_table'					=> 'click',
			'exits_table'					=> 'exit',
			'users_table'					=> 'user',
			'db_type'						=> OWA_DB_TYPE,
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
			'error_handler'					=> 'development',
			'error_log_file'				=> OWA_BASE_DIR . '/logs/errors.txt',
			'browscap.ini'					=> OWA_BASE_DIR . '/modules/base/data/php_browscap.ini',
			'search_engines.ini'			=> OWA_BASE_DIR . '/conf/search_engines.ini',
			'query_strings.ini'				=> OWA_BASE_DIR . '/conf/query_strings.ini',
			'db_class_dir'					=> OWA_BASE_DIR . '/plugins/db/',
			'templates_dir'					=> OWA_BASE_DIR . '/templates/',
			'plugin_dir'					=> OWA_BASE_DIR . '/plugins/',
			'module_dir'					=> OWA_BASE_DIR . '/modules',
			'geolocation_lookup'            => true,
			'geolocation_service'			=> 'hostip',
			'report_wrapper'				=> 'wrapper_default.tpl',
			'do_not_fetch_config_from_db'	=> false,
			'announce_visitors'				=> false,
			'public_url'					=> '',
			'action_url'					=> '',
			'images_url'					=> '',
			'reporting_url'					=> '',
			'p3p_policy'					=> 'NOI NID ADMa OUR IND UNI COM NAV',
			'graph_link_template'			=> '%s?owa_action=graph&name=%s&%s', //action_url?...
			'link_template'					=> '%s?%s', // main_url?key=value....
			'owa_user_agent'				=> 'Open Web Analytics Bot '.OWA_VERSION,
			'fetch_owa_news'				=> true,
			'owa_rss_url'					=> 'http://www.openwebanalytics.com/?feed=rss2',
			'use_summary_tables'			=> false,
			'summary_framework'				=> '',
			'click_drawing_mode'			=> 'center_on_page',
			'log_clicks'					=> true,
			'authentication'				=> 'simple',
			'owa_wiki_link_template'		=> 'http://wiki.openwebanalytics.com/index.php?title=%s',
			'password_length'				=> 4,
			'modules'						=> array('base'),
			'mailer-from'					=> '',
			'mailer-fromName'				=> 'OWA Mailer',
			'mailer-host'					=> '',
			'mailer-port'					=> '',
			'mailer-smtpAuth'				=> '',
			'mailer-username'				=> '',
			'mailer-password'				=> '',
			'cookie_domain'					=> $_SERVER['SERVER_NAME']
			
			));
			
			$base_url  = "http";
		
			if(isset($_SERVER['HTTPS'])):
				$base_url .= 's';
			endif;
						
			$base_url .= '://'.$_SERVER['SERVER_NAME'];
				
			if($_SERVER['SERVER_PORT'] != 80):
				$base_url .= ':'.$_SERVER['SERVER_PORT'];
			endif;
								
			$config['base']['public_url'] = OWA_PUBLIC_URL;
			$config['base']['main_url'] = 'main.php';
			$config['base']['main_absolute_url'] = OWA_PUBLIC_URL.$config['base']['main_url'];
			$config['base']['action_url'] = $config['base']['main_absolute_url'];
			$config['base']['images_url'] =  'i/';
			$config['base']['images_absolute_url'] = OWA_PUBLIC_URL.$config['base']['images_url'];
			$config['base']['log_url'] = OWA_PUBLIC_URL.'log.php';
			
			// set default values
			$this->config->set('settings', $config);
			
			
			return;
 		
 	}
 	
 }
 
 
?>