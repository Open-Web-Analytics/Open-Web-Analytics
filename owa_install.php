<?

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

require_once (OWA_BASE_DIR.'/owa_settings_class.php');
require_once (OWA_BASE_DIR.'/owa_db.php');

/**
 * Installs core database schema
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_install {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Data access object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Version of string
	 *
	 * @var string
	 */
	var $version = 1.0;
	
	/**
	 * Error Handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Constructor
	 *
	 * @return owa_install
	 */

	function owa_install() {
		
		$this->config = &owa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->e = &owa_error::get_instance();
		
		return;
	}
	
	function &get_instance($type) {
		
		$this->config = &owa_settings::get_settings();
		
        //$classfile = $class_path . $plugin . '.php';
		$classfile = $this->config['install_plugin_dir'].'owa_install_'.$type. '.php';
        $class = 'owa_install'.$type;
        
        /*
         * Attempt to include our version of the named class, but don't treat
         * a failure as fatal.  The caller may have already included their own
         * version of the named class.
         */
        if (!class_exists($class)) {
            include_once $classfile;
        }

        /* If the class exists, return a new instance of it. */
        if (class_exists($class)) {
            $obj = new $class;
            return $obj;
        }

        return null;
		
	}
	
	function create_all_tables() {
	
		$this->create_requests_table();
		$this->e->notice("Created requests table.");
		$this->create_sessions_table();
		$this->e->notice("Created sessions table.");
		$this->create_referers_table();
		$this->e->notice("Created referers table.");
		$this->create_documents_table();
		$this->e->notice("Created documents table.");
		$this->create_ua_table();
		$this->e->notice("Created user agents table.");
		$this->create_hosts_table();
		$this->e->notice("Created hosts table.");
		$this->create_os_table();
		$this->e->notice("Created operating systems table.");
		//$this->create_optinfo_table();
		$this->create_config_table();
		$this->e->notice("Created configuration table.");
		$this->create_version_table();
		$this->e->notice("Created version table.");
		
		$this->update_schema_version();
		$this->e->notice(sprintf("Schema version %s installation complete.",
							$this->version));
		
		return;
	}
	
	function update_schema_version() {
		
		$check = $this->db->get_row(sprintf("SELECT schema_version from %s",
										$this->config['ns'].$this->config['version_table']
										));

		if (empty($check)):
		
			$this->db->query(sprintf("INSERT into %s (schema_version) VALUES ('%s')",
										$this->config['ns'].$this->config['version_table'],
										$this->version));
		else:
										
			$this->db->query(sprintf("UPDATE %s SET schema_version = '%s'",
										$this->config['ns'].$this->config['version_table'],
										$this->version));
		
		endif;
	}
}

?>