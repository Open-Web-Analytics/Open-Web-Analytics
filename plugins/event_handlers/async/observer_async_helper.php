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
 * Async Event Queue Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 * @todo 		This class is far from working. Needs to be revisited.
 */


class Log_observer_async_helper extends Log_observer {

	var $processor_path = OWA_BASE_DIR;
	var $processor_script = 'process_log.php';
	var $shell_args;
	var $config;

    function Log_observer_async_helper($priority, $conf)
    {
        /* Call the base class constructor. */
        $this->Log_observer($priority);

        /* Configure the observer. */
		$this->_event_type = array('session_update');

		/* Setup configuration */
		return;
    }

    function notify($event) {
	
    	// This following line needs to be commented out to keep the handler from working. 
		// $this->execInBackground($this->processor_path, $this->processor_script, $this->shell_args);
	
		return;
	}
		
		
	function execInBackground($path, $exe, $args = "") {
   
   	//	if (file_exists($path ."/". $exe)):
       		chdir($path);
			print getcwd() . "\n";
       		if (substr(php_uname(), 0, 7) == "Windows"):
           		pclose(popen("start \"bla\" \"" . $exe . "\" " . escapeshellarg($args), "r"));    
       		else:
			//	print "php " . $path . "/" . $exe . " " . escapeshellarg($args) . " > /dev/null &";
           	exec("php " . $exe . " > /dev/null &");    
       		endif;
   		//endif;
		
		return;
	
		
		
    }
}

?>
