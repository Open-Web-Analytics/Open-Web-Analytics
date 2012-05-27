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

require_once('owa_env.php');
require_once(OWA_DIR.'owa_caller.php');
require_once(OWA_BASE_CLASS_DIR.'cliController.php');

/**
 * OWA Comand Line Interface (CLI)
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.1
 */

define('OWA_CLI', true);

if (!empty($_POST)) {
	exit();
} elseif (!empty($_GET)) {
	exit();
} elseif (!empty($argv)) {
	$params = array();
	// get params from the command line args
	// $argv is a php super global variable
	
	   for ($i=1; $i<count($argv);$i++)
	   {
		   $it = explode("=",$argv[$i]);
		   $params[$it[0]] = $it[1];
	   }
	 unset($params['action']);
	 unset($params['do']);
	
} else {
	// No params found
	exit();
}

// Initialize owa
$owa = new owa_caller;

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

	// setting CLI mode to true
	$owa->setSetting('base', 'cli_mode', true);
	// setting user auth
	$owa->setCurrentUser('admin', 'cli-user');
	// run controller or view and echo page content
	$s = owa_coreAPI::serviceSingleton();
	$s->loadCliCommands();
	
	if (array_key_exists('cmd', $params)) {
		
		$cmd = $s->getCliCommandClass($params['cmd']);
		
		if ($cmd) {
			$params['do'] = $cmd;
			echo $owa->handleRequest($params);
		} else {
			echo "Invalid command name.";
		}
		
	} else {
		echo "Missing a command argument.";
	}

} else {
	// unload owa
	$owa->restInPeace();
}

?>