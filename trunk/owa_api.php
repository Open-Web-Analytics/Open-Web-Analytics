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
 * Plugin API
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_api {
	
	/**
	 * Plugins
	 *
	 * @var array
	 */
	var $plugins = array();
	
	/**
	 * API calls from plugins
	 *
	 * @var array
	 */
	var $api_calls = array();
	
	/**
	 * Plugins Directory
	 *
	 * @var string
	 */
	var $plugins_dir;
	
	/**
	 * API Type
	 *
	 * @var string
	 */
	var $api_type;
	
	/**
	 * Class prefix
	 *
	 * @var string
	 */
	var $class_prefix;

	/**
	 * Constructor
	 *
	 * @return owa_api
	 */
	function owa_api() {

		return;
	}

	/**
	 * Get instance of API
	 *
	 * @param string $api_type
	 * @return object $api
	 * @access public
	 */
	function get_instance($api_type) {

		$api = new owa_api;
	
		switch ($api_type) {
		
			case "metric":
				require_once(OWA_BASE_DIR.'/owa_metric.php');
				$api->api_type = $api_type;
				$api->plugins_dir = OWA_METRICS_DIR;
				$api->class_prefix = 'owa_metric_';
				break;
			case "graph":
				require_once(OWA_BASE_DIR.'/owa_graph.php');
				$api->api_type = $api_type;
				$api->plugins_dir = OWA_GRAPHS_DIR;
				$api->class_prefix = 'owa_graph_';
				break;
		}
	
		$api->load_plugins();
		
		return $api;
	}

	/**
	 * Load Plugins
	 * 
	 * @access private
	 */
	function load_plugins() {
	
    	if ($dir = @opendir($this->plugins_dir)) {
    		while (($file = @readdir($dir)) !== false) {
        		if (strstr($file, '.php') &&
            		substr($file, -1, 1) != "~" &&
            		substr($file,  0, 1) != "#") {
          			if (require_once($this->plugins_dir . $file)) {
            			$this->plugins[] = substr($file, 0, -4);
						$class  = $this->class_prefix . substr($file, 0, -4);
            			$plugin = new $class;

						foreach ($plugin->api_calls as $api_call) {
              				if (!isset($this->api_calls[$api_call])) {
                				$this->api_calls[$plugin->api_type][$api_call] = $plugin;
              			} else {
                
							  sprintf(
								'API Call "%s" already registered.',
			
								$api_call
							  );
			              }
            			}
          			} else {
           
						  sprintf(
							'Cannot load plugin "%s".',
			
							substr($file, 0, -4)
						  );
					  }
					}
      			}

 		     @closedir($dir);
    		}
		return;
  	}
  
  	/**
  	 * Get object
  	 *
  	 * @param 	array $request_params
  	 * @return 	array $result
  	 */
	function get($request_params) {	
		
		//need to add error here incase the api call does not exist.
		$result = $this->api_calls[$this->api_type][$request_params['api_call']]->generate($request_params);
		
		switch ($request_params['result_format']) {
		
			case "assoc_array":
				return $result;
				break;
			case "inverted_array":
				return owa_lib::deconstruct_assoc($result);
				break;
		}
	
		return $result;
	}
	
}

?>
