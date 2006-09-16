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
require_once(OWA_BASE_DIR.'/owa_site.php');
require_once(OWA_BASE_DIR.'/owa_user.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');

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

class owa_install_update_to_1_0 extends owa_install {
	
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
	var $package = 'update_to_1_0';
	
	/**
	 * Package Display Name
	 *
	 * @var string
	 */
	var $package_display_name = 'OWA Update to 1.0 for MySQL';
	
	/**
	 * Description of what is being installed
	 *
	 * @var string
	 */
	var $description = 'This is the update to 1.0 for MySQL 4 or greater.';
	
	/**
	 * Constructor
	 *
	 * @return owa_install_mysql
	 */
	function owa_install_update_to_1_0() {
		$this->owa_install();
		$this->tables = array(	$this->config['impressions_table'],
								$this->config['clicks_table'],
								$this->config['users_table'],
								$this->config['exits_table']
								);
		return;
	}
	
	/**
	 * Check to see if schema change is installed
	 *
	 * @return boolean
	 */
	function check_for_schema() {
		
		
		return false;
		/*
		$check ='';
		
		if (!empty($check)):
			$this->e->notice("Installation aborted. Schema already exists.");
			return true;
		else:
			return false;
		endif;
		*/
	}
	
	/**
	 * Interface to creation methods
	 *
	 * @param unknown_type $table
	 */
	function create($table) {
		
		switch ($table) {
			
			case $this->config['clicks_table']:
				return $this->create_clicks_table();
				break;
				
			case $this->config['impressions_table']:
				return $this->create_impressions_table();
				break;
				
			case $this->config['users_table']:
				return $this->create_users_table();
				break;
				
			case $this->config['exits_table']:
				return $this->create_exits_table();
				break;
				
		}
		
		return;		
	}
	
	function create_clicks_table() {
		
	/*	 $this->db->query(
			sprintf("
			alter table %s add column weekofyear INT
			",
			$this->config['ns'].$this->config['clicks_table']
			
			));
			
		$this->db->query(
			sprintf("
			alter table %s add column weekofyear INT
			",
			$this->config['ns'].$this->config['impressions_table']
			
			));
	*/
		$this->db->query(
			sprintf("
			alter table %s add column page_width INT
			",
			$this->config['ns'].$this->config['clicks_table']
			
			));
			
		$this->db->query(
			sprintf("
			alter table %s add column page_height INT
			",
			$this->config['ns'].$this->config['clicks_table']
			
			));
			
		$this->db->query(
			sprintf("
			alter table %s add column site VARCHAR(255)
			",
			$this->config['ns'].$this->config['referers_table']
			
			));
			
		return true;
		
	}
	
	function create_impressions_table() {
		
		$this->db->query(
			sprintf("
			alter table %s add column browser VARCHAR(255)
			",
			$this->config['ns'].$this->config['ua_table']
			
			));
		
		return true;
		
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
			$this->config['ns'].$this->config['referers_table'])
		);

	}
	
	function create_users_table() {
		
		$this->db->query(
			sprintf("
			CREATE TABLE %1\$s (
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
		$old_auth = $this->config['authentication'];
		$this->config['authentication'] = 'simple';
		$auth = & owa_auth::get_instance();
		$u = new owa_user;
		$u->user_id = $this->config['db_user'];
		$u->password = $auth->encryptPassword($this->config['db_password']);
		$u->role = 'admin';
		$u->save();
		$this->config['authentication'] = $old_auth;
		
		return;
		
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
				return $status;
			endif;
		}
		
		// Update schema version
		$this->update_schema_version();
			
			
		$this->e->notice(sprintf("Schema version %s Update complete.",
							$this->version));
			
		$status = sprintf("Update complete. <P>You can also configure yor installation by visiting
		the <a href=\"%s/admin/options.php\">Options page</a>", '1', $this->config['public_url']);					
		
		return $status;
	}
	
}

?>