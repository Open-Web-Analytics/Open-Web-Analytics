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
 * Settings
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class wa_settings {
	
	/**
	 * Returns the configuration
	 *
	 * @return 	array
	 * @access 	public
	 * @static 
	 */
	function &get_settings() {
	
		static $settings;
		
		if(!isset($settings)):
			
		$settings = Array(
	
			'log_errors'				 	=> true,
			'print_sql' 					=> true,
			'ns'							=> 'wa_',
			'visitor_param'					=> 'v',
			'session_param'					=> 's',
			'last_request_param'			=> 'last_req',
			'first_hit_param'				=> 'first_hit',
			'feed_subscription_param'		=> 'sid',
			'site_id'						=> '1',
			'session_length'				=> '1800',
			'debug_msgs'					=> true,
			'debug_to_screen'				=> false,
			'requests_table'				=> 'requests',
			'sessions_table'				=> 'sessions',
			'referers_table'				=> 'referers',
			'ua_table'						=> 'ua',
			'os_table'						=> 'os',
			'documents_table'				=> 'documents',
			'optinfo_table'					=> 'optinfo',
			'hosts_table'					=> 'hosts',
			'data_store'					=> 'db',
			'debug_level'					=> '1',
			'db_type'						=> 'wordpress',
			'db_name'						=> WA_DB_NAME,
			'db_user'						=> WA_DB_USER,
			'db_password'					=> WA_DB_PASSWORD,
			'db_host'						=> WA_DB_HOST,
			'resolve_hosts'					=> true,
			'log_feedreaders'				=> true,
			'log_robots'					=> false,
			'log_sessions'					=> true,
			'delay_first_hit'				=> true,
			'async_db'						=> false,
			'restore_db_conn'				=> true,
			'async_log_dir'					=> WA_BASE_DIR . '/logs',
			'async_log_file'				=> WA_BASE_DIR . '/logs/events.txt',
			'async_error_log_file'			=> WA_BASE_DIR . '/logs/events_error.txt',
			'error_email'					=> true,
			'error_email_address'			=> 'peter@oncefuture.com',
			'error_log_file'				=> WA_BASE_DIR . '/logs/errors.txt',
			'search_engines.ini'			=> WA_BASE_DIR . '/conf/search_engines.ini',
			'query_strings.ini'				=> WA_BASE_DIR . '/conf/query_strings.ini',
			'os.ini'						=> WA_BASE_DIR . '/conf/os.ini',
			'db_class_dir'					=> WA_BASE_DIR . '/db',
			'templates_dir'					=> WA_BASE_DIR . '/reports/templates/',
			'plugin_dir'					=> WA_BASE_DIR . '/plugins/',
			'geolocation_service'			=> 'hostip',
			'report_wrapper'				=> 'wordpress.tpl'
			
			);
		
		endif;

		// look for debug flag on url	
		if (isset($_GET['debug'])):
			$settings['debug_to_screen'] = true;
		endif;
		
		return $settings;
	}

}

?>
