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
 * OWA Installation class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_install_mysql extends owa_install {

	/**
	 * Version of the schema
	 *
	 * @var string
	 */
	var $version = '1.0';
	
	
	/**
	 * Constructor
	 *
	 * @return owa_install_mysql
	 */
	function owa_install_mysql() {
		
		$this->owa_install();
		return;
	}
	
	/**
	 * Check to see if schema is installed
	 *
	 * @return unknown
	 */
	function check_for_schema() {
		
		$check = $this->db->get_row(sprintf("show tables like '%s'",
				$this->config['ns'].$this->config['requests_table']));
		
		if (!empty($check)):
			$this->e->notice("Installation failed. Schema already exists.");
			return true;
		else:
			return false;
		endif;
	}
	
	/**
	 * Create requests table
	 * 
	 * @access private
	 *
	 */
	function create_requests_table() {

		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			request_id bigint,
			inbound_visitor_id bigint, 
			inbound_session_id bigint,
			inbound_first_hit_properties varchar(255),
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
			
			PRIMARY KEY (request_id),
			KEY timestamp (timestamp))",
			$this->config['ns'].$this->config['requests_table'])
		);
		
		return;
	}
	
	function create_sessions_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			session_id BIGINT,
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
			PRIMARY KEY (session_id),
			KEY timestamp      (timestamp)
			)",
			$this->config['ns'].$this->config['sessions_table'])
		);
		
		return;
	}
	
	function create_referers_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			url varchar(255),
			site_name varchar(255),
			query_terms varchar(255),
			refering_anchortext varchar(255),
			page_title varchar(255),
			snippet TEXT,
			is_searchengine tinyint(1),
			PRIMARY KEY (id)
			)",
			$this->config['ns'].$this->config['referers_table'])
		);

		return;
	}
		
	function create_documents_table() {
		
		$this->db->query(
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
		
		return;
	}
  	
	function create_hosts_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			url varchar(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['hosts_table'])
		);
		return;
	}
	
	function create_os_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			name varchar(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['os_table'])
		);
		return;
	}

	function create_ua_table() {
		
			$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			ua varchar(255),
			browser_type varchar(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['ua_table'])
		);
		
		
		return;
	}
		
	function create_optinfo_table() {
		
			$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			request_id BIGINT,
			data_field VARCHAR(255),
			data_value VARCHAR(255),
			KEY (request_id)
			)",	
			$this->config['ns'].$this->config['optinfo_table'])
		);
		
		return;
	}
	
	function create_config_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			settings TEXT,
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['config_table'])
		);
		
		return;
	}
	
	function create_version_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id VARCHAR(255),
			value VARCHAR(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['version_table'])
		);
		
		return;
	}
				
}

?>