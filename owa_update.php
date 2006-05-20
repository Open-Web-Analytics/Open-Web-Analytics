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
include_once('owa_env.php');
require_once (OWA_BASE_DIR.'/owa_settings_class.php');
require_once (OWA_BASE_DIR.'/owa_db.php');

/**
 * Updates OWA software and schema
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_update {
	
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

	function owa_update() {
		
		$this->config = &owa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->config['error_handler'] = 'development';
		$this->e = &owa_error::get_instance();
		
		return;
	}
	
	function &get_instance($type) {
		
		$this->config = &owa_settings::get_settings();
		
        //$classfile = $class_path . $plugin . '.php';
		$classfile = $this->config['update_plugin_dir'].'owa_update_'.$type. '.php';
        $class = 'owa_update_'.$type;
        
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
	
	function to_1_rc2() {
	
		// Rename month col to month_label
		
		$step1 = $this->db->query(sprintf("
							ALTER TABLE %s CHANGE month month_label varchar(255)",
							$this->config['ns'].$this->config['requests_table']
							));
		if ($step1 == true):
			$this->e->notice("Modified month col in requests table.");
		else:
			$this->e->notice("error is step 1");
			exit;
		endif;
		
		$step1b = $this->db->query(sprintf("
							ALTER TABLE %s CHANGE month month_label varchar(255)",
							$this->config['ns'].$this->config['sessions_table']
							));
		if ($step1b == true):
			$this->e->notice("Modified month col in sessions table.");
		else:
			$this->e->notice("error in step 1b");
			exit;
		endif;
		
		// make new month col with int type
		
		$step2 = $this->db->query(sprintf("
							ALTER TABLE %s ADD column month INT",
							$this->config['ns'].$this->config['requests_table']
							));
		if ($step2 == true):
			$this->e->notice("added month col in requests table.");
		else:
			$this->e->notice("error in step 2");
			exit;
		endif;
		
		
		$step2b = $this->db->query(sprintf("
							ALTER TABLE %s ADD column month INT",
							$this->config['ns'].$this->config['sessions_table']
							));
		if ($step2b == true):
			$this->e->notice("added month col in sessions table.");
		else:
			$this->e->notice("error is step 2b");
			exit;
		endif;
	
		// update month based on month label
		
		$months = array('Jan' => 1,
						'Feb' => 2,
						'Mar' => 3,
						'Apr' => 4,
						'May' => 5
						);
		
		foreach ($months as $month => $value) {
			
			$step3 = $this->db->query(sprintf("
							UPDATE %s SET month = '%d' where month_label = '%s'",
							$this->config['ns'].$this->config['requests_table'],
							$value,
							$month
							));
							
			$step3b = $this->db->query(sprintf("
							UPDATE %s SET month = '%d' where month_label = '%s'",
							$this->config['ns'].$this->config['sessions_table'],
							$value,
							$month
							));
			
		if ($step3 == true):
			$this->e->notice(sprintf("Updated month col to %d for rows with lables of %s",
									$value,
									$month));
		else:
			$this->e->notice("error is step 3");
			exit;
		endif;
		}	
		
		$step4 = $this->db->query(sprintf("
							ALTER TABLE %s drop column month_label",
							$this->config['ns'].$this->config['requests_table']
							));
		if ($step4 == true):
			$this->e->notice("dropping month_label col in requests table.");
		else:
			$this->e->notice("error in step 4");
			exit;
		endif;
		
		$step4b = $this->db->query(sprintf("
							ALTER TABLE %s drop column month_label",
							$this->config['ns'].$this->config['sessions_table']
							));
		if ($step4b == true):
			$this->e->notice("dropping month_label col in sessions table.");
		else:
			$this->e->notice("error in step 4b");
			exit;
		endif;
			
		
		$this->version = '1.1';
		$this->update_schema_version();
		
		return;
	}
		
	
	function update_schema_version() {
		
		$check = $this->check_schema_version();

		if (empty($check)):
		
			$this->db->query(sprintf("INSERT into %s (id, value) VALUES ('schema_version', '%s')",
										$this->config['ns'].$this->config['version_table'],
										$this->version));
		else:
										
			$this->db->query(sprintf("UPDATE %s SET value = '%s' where id = 'schema_version'",
										$this->config['ns'].$this->config['version_table'],
										$this->version));
		
		endif;
	}
	
	function check_schema_version() {
		
			return $this->db->get_row(sprintf("SELECT value from %s where id = 'schema_version'",
										$this->config['ns'].$this->config['version_table']
										));
								
	}
}

?>