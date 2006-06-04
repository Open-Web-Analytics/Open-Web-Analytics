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
	 * @return unknown
	 */
	function &get_instance() {
		
		//$this->config = &owa_settings::get_settings();
		
        //$classfile = $class_path . $plugin . '.php';
		//$classfile = $this->config['install_plugin_dir'].'/'.$this->config['db_type'].'/owa_install_'.$type. '.php';
        //$class = 'owa_install_'.$type;
        $class = new owa_installer;
        return $class;
        /*
         * Attempt to include our version of the named class, but don't treat
         * a failure as fatal.  The caller may have already included their own
         * version of the named class.
         */
        if (!class_exists($class)) {
            return $class;
        }

        /* If the class exists, return a new instance of it.
        if (class_exists($class)) {
            $obj = new $class;
            return $obj;
        }
		*/
        return null;
		
	}
	
	/**
	 * Load Plugins
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
	
  	
}

?>