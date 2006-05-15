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

require_once 'owa_settings_class.php';
require_once 'owa_controller.php';
require_once 'owa_install.php';

/**
 * Abstract caller class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_caller {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Error handler
	 *
	 * @var object
	 */
	var $e;
	
	function owa_caller($config) {
		
		$this->config = &owa_settings::get_settings();
		
		$this->apply_caller_config($config);
		
		if ($this->config['fetch_config_from_db'] == 'true'):
			$this->load_config_from_db();
		endif;
	
		return;
	
	}
	
	function apply_caller_config($config) {
		
		if (!empty($config)):
			foreach ($config as $key => $value) {
				
				$this->config[$key] = $value;
				
			}

		endif;
					
		return;

	}
	
	function load_config_from_db() {
		
		$config_from_db = owa_settings::fetch($this->config['site_id']);
		
		if (!empty($config_from_db)):
			
			foreach ($config_from_db as $key => $value) {
			
				$this->config[$key] = $value;
			
			}
					
		endif;
		
		return;
	}
	
	function process_request($app_params) {
		
		$owa = new owa;
		$owa->process_request($app_params);
		return;
	}
	
	function install($type) {
		
	    $installer = &owa_install::get_instance($type);	    
	    $install_check = $installer->check_for_schema();
	    
	    if ($install_check == false):
		    //Install owa schema
	    	$installer->create_all_tables();
	    else:
	    	// owa already installed
	    	return;
	    endif;
	    
	    return;
		
	}
	
	function reset_config() {
			
		$config = $this->config->get_default_config();
		$this->config->save($config);
		return;
				
	}
	
	function options_page() {
	
		require_once 'template_class.php';
	
		//Setup templates
		$options_page = & new Template;
		$options_page->set_template($options_page->config['report_wrapper']);
		$body = & new Template; 
		$body->set_template('options.tpl');// This is the inner template
		$body->set('config', $this->config);
		$body->set('page_title', 'OWA Options');
		$options_page->set('content', $body);
		// Make Page
		echo $options_page->fetch();
		
		return;
	}
	
	function first_request_handler() {
		
		if (isset($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$owa = new owa;
			$owa->process_first_request();
		endif;
			
		header('Content-type: image/gif');
		header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');
		header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
				
		printf(
		  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
		  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
		);
			
		return;
	}
	
	function graph_request_handler() {
		
		$params = array(
				'api_call' 		=> $_GET['graph'],
				'period'			=> $_GET['period']
			
			);
			
		$owa = new owa;
		$owa->get_graph($params);
		return;
	}
	
	function save_config($form_data) {
		
		//create the new config array
		$new_config = array();
			
		// needed for following DB queries just in case the various 
		// implementations of the GUI does not allow you to set this.
		$new_config['site_id'] = $this->config['site_id'];
			
		foreach ($form_data as $key => $value) {
				
			if ($key != 'wa_update_options'):
				// update current config
				$this->config[$key] = $value;
				//add to config going to the db
				$new_config[$key] = $value;
				
			endif;
		}
		
		owa_settings::save($new_config);
		$this->e->info("Configuration changes saved to database.");
		
		return;
	}
	
}

?>
