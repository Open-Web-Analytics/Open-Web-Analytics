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
 * Installer Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_installer {
	
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
	 * Plugin Directory
	 *
	 * @var unknown_type
	 */
	var $plugin_dir;
	
	/**
	 * Array of plugins
	 *
	 * @var array
	 */
	var $plugins;
	
	/**
	 * Constructor
	 *
	 * @return owa_install
	 */
	function owa_installer() {
		
		$this->config = &owa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->e = &owa_error::get_instance();
		$this->plugins_dir = $this->config['install_plugin_dir'].$this->config['db_type'];
		$this->load_plugins();
		
		return;
	}
	
	/**
	 * Installation factory
	 *
	 * @param string $type
	 * @return object
	 */
	function &get_instance() {
		
        $class = new owa_installer;
        return $class;

	}
	
	/**
	 * Loads Package Plugins
	 * 
	 * @access private
	 */
	function load_plugins() {
		
    	if ($dir = opendir($this->plugins_dir)):
    		while (($file = readdir($dir)) !== false) {
        		if (strstr($file, '.php') &&
            		substr($file, -1, 1) != "~" &&
            		substr($file,  0, 1) != "#"):
          			if (require_once($this->plugins_dir . '/'. $file)):
            			//$this->plugins[] = substr($file, 0, -4);
						$class  = substr($file, 0, -4);
						
            			$plugin = new $class;
					
              			if (!isset($this->plugins[$package])):
                			$this->plugins[$plugin->package] = $plugin;
              			else:
                			$this->e->err(sprintf('Package "%s" already registered.', $package));
			            endif;
            		endif;
          		else:
           			$this->e->err(sprintf('Cannot load plugin "%s".', substr($file, 0, -4)));
				endif;
			}
      	endif;

 		@closedir($dir);
    		
		return;
  	}
	
  	/**
  	 * Builds an array of available package plugins
  	 *
  	 * @return array
  	 */
  	function get_available_packages() {
  		
  		$packages = '';
  		
  		foreach ($this->plugins as $plugin => $value) {
  			
  			$packages[$plugin] = array('package_display_name' => $value->package_display_name, 'description' => $value->description);
  			
  		}
  		
  		return $packages;
  	}
  	
  	/**
  	 * Builds an array of packages that are already installed
  	 *
  	 * @return array
  	 */
  	function get_installed_packages() {
  		
  		$installed_packages = $this->db->get_row(sprintf("SELECT value from %s where id = '%s'",
										$this->config['ns'].$this->config['version_table'],
										$this->config['site_id']
										));
										
  		return unserialize($installed_packages);
  	}
  	
  	/**
  	 * Writes on the owa_config file
  	 *
  	 * @todo Need to design this
  	 * @param array $config
  	 */
  	function write_config_file($config) {
  		
  		return;
  		
  	}
}

?>