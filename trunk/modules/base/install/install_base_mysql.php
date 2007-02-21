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

require_once(OWA_BASE_DIR.'/owa_install.php');


/**
 * OWA Base Schema Installation class for Mysql Database Platform
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_install_base_mysql extends owa_install {
	
	/**
	 * Version of the schema
	 *
	 * @var string
	 */
	var $version = '2';
	
	/**
	 * Array of tables that will be installed
	 *
	 * @var unknown_type
	 */
	var $tables;
	
	/**
	 * Constructor
	 *
	 * @return owa_installBase_mysql
	 */
	function owa_install_base_mysql($params = null) {
		
		$this->params = $params;
		$this->owa_install();
		$this->tables = array($this->config['requests_table'],
								$this->config['sessions_table'],
								$this->config['referers_table'],
								$this->config['documents_table'],
								$this->config['ua_table'],
								$this->config['hosts_table'],
								$this->config['os_table'],
								$this->config['sites_table'],
								$this->config['config_table'],
								$this->config['feed_requests_table'],
								$this->config['visitors_table'],
								$this->config['impressions_table'],
								$this->config['clicks_table'],
								$this->config['exits_table'],
								$this->config['users_table']
								);
		return;
	}
	
	
	
	/**
	 * Interface to creation methods
	 *
	 * @param unknown_type $table
	 */
	function create($table) {
		
		switch ($table) {
			
			case $this->config['requests_table']:
				return $this->create_requests_table();
				break;
			case $this->config['sessions_table']:
				return $this->create_sessions_table();
				break;
			case $this->config['documents_table']:
				return $this->create_documents_table();
				break;
			case $this->config['referers_table']:
				return $this->create_referers_table();
				break;
			case $this->config['hosts_table']:
				return $this->create_hosts_table();
				break;
			case $this->config['ua_table']:
				return $this->create_ua_table();
				break;
			case $this->config['os_table']:
				return $this->create_os_table();
				break;
			case $this->config['config_table']:
				return $this->create_config_table();
				break;
			case $this->config['sites_table']:
				return $this->create_sites_table();
				break;
			case $this->config['feed_requests_table']:
				return $this->create_feed_requests_table();
				break;
			case $this->config['visitors_table']:
				return $this->create_visitors_table();
				break;
			case $this->config['impressions_table']:
				return $this->create_impressions_table();
				break;
			case $this->config['clicks_table']:
				return $this->create_clicks_table();
				break;
			case $this->config['exits_table']:
				return $this->create_exits_table();
				break;
			case $this->config['users_table']:
				return $this->create_users_table();
				break;
				
		}
		
		return;		
	}
	
	/**
	 * Create requests table
	 * 
	 * @access private
	 *
	 */
	function create_requests_table() {

		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id bigint,
			inbound_visitor_id bigint, 
			inbound_session_id bigint,
			visitor_id bigint, 
			session_id bigint,
			user_name  varchar(255),
			user_email  varchar(255),
			timestamp bigint,
			month INT,
			day	  tinyint(2),
			dayofweek varchar(10),
			dayofyear INT,
			weekofyear INT,
			year  INT,
			hour  tinyint(2),
			minute   tinyint(2),
			second tinyint(2),
			msec int,
			last_req bigint,
			feed_subscription_id bigint,
			referer_id varchar(255),
			document_id varchar(255),
			site varchar(255),
			site_id varchar(255),
			ip_address varchar(255),
			host varchar(255),
			host_id varchar(255),
			os varchar(255),
			os_id varchar(255),
			ua_id varchar(255),
			is_new_visitor TINYINT(1), 
			is_repeat_visitor TINYINT(1), 
			is_comment TINYINT(1),
			is_entry_page tinyint(1),
			is_robot tinyint(1),
			is_browser tinyint(1),
			is_feedreader tinyint(1),
			
			PRIMARY KEY (id),
			KEY timestamp (timestamp))",
			$this->config['ns'].$this->config['requests_table'])
		);
		
		
	}
	
	function create_feed_requests_table() {
		
		//$this->config['error_handler'] = 'development';
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			visitor_id BIGINT,
			session_id BIGINT,
			document_id BIGINT,
			ua_id VARCHAR(255),
			site_id VARCHAR(255),
			site VARCHAR(255),
			timestamp bigint,
			month INT,
			day	  tinyint(2),
			dayofweek varchar(10),
			dayofyear INT,
			weekofyear INT,
			year  INT,
			hour  tinyint(2),
			minute   tinyint(2),
			second tinyint(2),
			msec int,
			last_req bigint,
			feed_reader_guid VARCHAR(255),
			subscription_id BIGINT,
			feed_format VARCHAR(255),
			ip_address VARCHAR(255),
			host VARCHAR(255),
			host_id BIGINT,
			os VARCHAR(255),
			os_id VARCHAR(255),
			PRIMARY KEY (id))
			",
			$this->config['ns'].$this->config['feed_requests_table']
			
			));
		
	}
	
	function create_visitors_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			user_name VARCHAR(255),
			user_email  varchar(255),
			first_session_id BIGINT,
			first_session_year INT,
			first_session_month varchar(255),
			first_session_day INT,
			first_session_dayofyear INT,
			first_session_timestamp BIGINT,
			last_session_id BIGINT,
			last_session_year INT,
			last_session_month varchar(255),
			last_session_day INT,
			last_session_dayofyear INT,
			PRIMARY KEY (id))
			",
			$this->config['ns'].$this->config['visitors_table']
			
			));
	}
	
	function create_impressions_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			visitor_id BIGINT,
			session_id BIGINT,
			tag_id BIGINT,
			placement_id BIGINT,
			campaign_id BIGINT,
			ad_group_id BIGINT,
			ad_id BIGINT,
			site_id VARCHAR(255),
			last_impression_id BIGINT,
			last_impression_timestamp BIGINT,
			timestamp BIGINT,
			year INT,
			month INT,
			day INT,
			dayofyear INT,
			weekofyear INT,
			hour TINYINT(2),
			minute TINYINT(2),
			msec BIGINT,
			url VARCHAR(255),
			ua_id BIGINT,
			ip_address VARCHAR(255),
			host VARCHAR(255),
			host_id VARCHAR(255),
			PRIMARY KEY (id))
			",
			$this->config['ns'].$this->config['impressions_table']
			
			));
	}
	
	function create_clicks_table() {

		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			last_impression_id BIGINT,
			visitor_id BIGINT,
			session_id BIGINT,
			document_id BIGINT,
			target_id BIGINT,
			target_url VARCHAR(255),
			timestamp BIGINT,
			year INT,
			month INT,
			day INT,
			dayofyear INT,
			weekofyear INT,
			hour TINYINT(2),
			minute TINYINT(2),
			second INT,
			msec VARCHAR(255),
			click_x INT,
			click_y INT,
			page_width INT,
			page_height INT,
			position BIGINT,
			approx_position BIGINT,
			dom_element_x INT,
			dom_element_y INT,
			dom_element_name VARCHAR(255),
			dom_element_id VARCHAR(255),
			dom_element_value VARCHAR(255),
			dom_element_tag VARCHAR(255),
			dom_element_text VARCHAR(255),
			tag_id BIGINT,
			placement_id BIGINT,
			campaign_id BIGINT,
			ad_group_id BIGINT,
			ad_id BIGINT,
			site_id VARCHAR(255),
			ua_id BIGINT,
			ip_address VARCHAR(255),
			host VARCHAR(255),
			host_id VARCHAR(255),
			PRIMARY KEY (id))
			",
			$this->config['ns'].$this->config['clicks_table']
			
			));
		
	}
	
	function create_sessions_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			visitor_id BIGINT,
			user_name VARCHAR(255),
			user_email  varchar(255),
			timestamp bigint,
			year INT,
			month INT,
			day TINYINT(2),
			dayofweek varchar(10),
			dayofyear INT,
			weekofyear INT,
			hour TINYINT(2),
			minute TINYINT(2),
			last_req BIGINT,
			num_pageviews INT,
			num_comments INT,
			is_repeat_visitor TINYINT(1),
			is_new_visitor TINYINT(1),
			prior_session_lastreq BIGINT,
			prior_session_id BIGINT,
			time_sinse_priorsession INT,
			prior_session_year INT(4),
			prior_session_month varchar(255),
			prior_session_day TINYINT(2),
			prior_session_dayofweek int,
			prior_session_hour TINYINT(2),
			prior_session_minute TINYINT(2),
			os VARCHAR(255),
			os_id varchar(255),
			ua_id varchar(255),
			first_page_id BIGINT,
			last_page_id BIGINT,
			referer_id BIGINT,
			ip_address varchar(255),
			host varchar(255),
			host_id varchar(255),
			source varchar(255),
			city varchar(255),
			country varchar(255),
			site varchar(255),
			site_id varchar(255),
			is_robot tinyint(1),
			is_browser tinyint(1),
			is_feedreader tinyint(1),
			PRIMARY KEY (id),
			KEY timestamp      (timestamp)
			)",
			$this->config['ns'].$this->config['sessions_table'])
		);
	
	}
	
	function create_referers_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			url varchar(255),
			site_name varchar(255),
			site VARCHAR(255),
			query_terms varchar(255),
			refering_anchortext varchar(255),
			page_title varchar(255),
			snippet TEXT,
			is_searchengine tinyint(1),
			PRIMARY KEY (id)
			)",
			$this->config['ns'].$this->config['referers_table'])
		);

	}
	
	function create_exits_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			url varchar(255),
			site_name varchar(255),
			site VARCHAR(255),
			exit_anchortext varchar(255),
			page_title varchar(255),
			PRIMARY KEY (id)
			)",
			$this->config['ns'].$this->config['exits_table'])
		);

	}
		
	
	function create_documents_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			url varchar(255),
			page_title varchar(255),
			page_type varchar(255),
			PRIMARY KEY (id)
			)",
			$this->config['ns'].$this->config['documents_table'])
		);
		
	}
	
	function create_users_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id SERIAL,
			user_id varchar(255),
			password VARCHAR(255),
			role VARCHAR(255),
			real_name VARCHAR(255),
			email_address VARCHAR(255),
			temp_passkey VARCHAR(255),
			creation_date BIGINT,
			last_update_date BIGINT,
			PRIMARY KEY (user_id)
			)",
			$this->config['ns'].$this->config['users_table'])
		);
		
	}
  	
	function create_hosts_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			ip_address VARCHAR(255),
			host VARCHAR(255),
			full_host VARCHAR(255),
			city VARCHAR(255),
			country VARCHAR(255),
			latitude VARCHAR(255),
			longitude VARCHAR(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['hosts_table'])
		);
	}
	
	function create_os_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			name varchar(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['os_table'])
		);
	
	}

	function create_ua_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			ua varchar(255),
			browser_type varchar(255),
			browser VARCHAR(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['ua_table'])
		);

	}
		
	function create_optinfo_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			request_id BIGINT,
			data_field VARCHAR(255),
			data_value VARCHAR(255),
			KEY (request_id)
			)",	
			$this->config['ns'].$this->config['optinfo_table'])
		);
		
	}
	
	function create_config_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			settings TEXT,
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['config_table'])
		);
		
	}
	
	function create_sites_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id SERIAL,
			site_id VARCHAR(255),
			domain VARCHAR(255) NOT NULL,
			name VARCHAR(255),
			description TEXT,
			site_family VARCHAR(255)
			
			)",	
			$this->config['ns'].$this->config['sites_table'])
		);

	}
	
}

?>