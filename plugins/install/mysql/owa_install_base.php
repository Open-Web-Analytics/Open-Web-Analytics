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
 * OWA Base Schema Installation class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_install_base extends owa_install {
	
	/**
	 * Version of the schema
	 *
	 * @var string
	 */
	var $version = '1.0';
	
	/**
	 * Array of tables that will be installed
	 *
	 * @var unknown_type
	 */
	var $tables;
	
	/**
	 * Package Name
	 *
	 * @var string
	 */
	var $package = 'base_schema';
	
	/**
	 * Package Display Name
	 *
	 * @var string
	 */
	var $package_display_name = 'OWA Base Schema for MySQL';
	
	/**
	 * Description of what is being installed
	 *
	 * @var string
	 */
	var $description = 'This is the base OWA schema for MySQL 4 or greater.';
	
	/**
	 * Constructor
	 *
	 * @return owa_install_mysql
	 */
	function owa_install_base() {
		$this->owa_install();
		$this->tables = array($this->config['requests_table'],
								$this->config['sessions_table'],
								$this->config['referers_table'],
								$this->config['documents_table'],
								$this->config['ua_table'],
								$this->config['hosts_table'],
								$this->config['os_table'],
								$this->config['config_table'],
								$this->config['version_table']);
		return;
	}
	
	/**
	 * Check to see if schema is installed
	 *
	 * @return boolean
	 */
	function check_for_schema() {
		
		$check = $this->db->get_row(sprintf("show tables like '%s'",
				$this->config['ns'].$this->config['version_table']));
		
		if (!empty($check)):
			$this->e->notice("Installation aborted. Schema already exists.");
			return true;
		else:
			return false;
		endif;
	}
	
	/**
	 * Interface to creation methods
	 *
	 * @param unknown_type $table
	 */
	function create($table) {
		
		switch ($table) {
			
			case 'requests':
				return $this->create_requests_table();
				break;
			case 'sessions':
				return $this->create_sessions_table();
				break;
			case 'documents':
				return $this->create_documents_table();
				break;
			case 'referers':
				return $this->create_referers_table();
				break;
			case 'hosts':
				return $this->create_hosts_table();
				break;
			case 'ua':
				return $this->create_ua_table();
				break;
			case 'os':
				return $this->create_os_table();
				break;
			case 'configuration':
				return $this->create_config_table();
				break;
			case 'version':
				return $this->create_version_table();
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
		
		
	}
	
	function create_sessions_table() {
		
		return $this->db->query(
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
	
	}
	
	function create_referers_table() {
		
		return $this->db->query(
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
  	
	function create_hosts_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id BIGINT,
			url varchar(255),
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
	
	function create_version_table() {
		
		return $this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
			id VARCHAR(255),
			value VARCHAR(255),
			PRIMARY KEY (id)
			)",	
			$this->config['ns'].$this->config['version_table'])
		);

	}
	
	function update_schema_version() {
		
		$check = $this->db->get_row(sprintf("SELECT value from %s where id = 'packages'",
										$this->config['ns'].$this->config['version_table'],
										$this->config['site_id']
										));

		$packages = array();								
		
		if (empty($check)):
			
			$packages[$this->package] = $this->version;	
			$this->db->query(sprintf("INSERT into %s (id, value) VALUES ('packages', '%s')",
										$this->config['ns'].$this->config['version_table'],
										serialize($packages)
										));
		else:
			$packages = unserialize($check);
			$packages[$this->package] = $this->version;				
			$this->db->query(sprintf("UPDATE %s SET value = '%s' where id = 'packages'",
										$this->config['ns'].$this->config['version_table'],
										serialize($packages)));
		
		endif;
		
		return;
	}
	
	/**
	 * Creates all tables in base schema
	 *
	 */
	function install() {
	
		foreach ($this->tables as $table) {
		
			$status = $this->create($table);
			
			if ($status == true):
				$this->e->notice(sprintf("Created %s table.", $table));
			else:
				$this->e->err(sprintf("Creation of %s table failed. Aborting Installation...", $table));
				return;
			endif;
		}
	
			$this->update_schema_version();
			$this->e->notice(sprintf("Schema version %s installation complete.",
							$this->version));
		
		return $status;
	}
	
}

?>