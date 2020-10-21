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
 * OWA Comand Line Interface (CLI)
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.2.1
 */

// Ensure we are being called as a CLI process before any other processing.
define('OWA_CLI', (php_sapi_name() == 'cli' || (is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0)));

if (!OWA_CLI)
{
    // Fail with 404 if called over HTTP so it looks like the script
    // just doesn't exist.
    if (isset($_SERVER['SERVER_PROTOCOL'])) {
        header("$_SERVER[SERVER_PROTOCOL] 404 Not Found");
    }
    exit();
}

require_once('owa_env.php');
require_once(OWA_DIR.'owa_caller.php');
require_once(OWA_BASE_CLASS_DIR.'cliController.php');

$params = [];
// get params from the command line args
// $argv is a php super global variable
for ($i=1; $i<count($argv); $i++)
{
    $it = explode("=",$argv[$i]);
    if (count($it) !== 2) {
        fwrite(STDERR, "Invalid argument '{$argv[$i]}'. Syntax is key=value\n");
        exit(1);
    }
    $params[$it[0]] = $it[1];
}
unset($params['action']);
unset($params['do']);
if (empty($params)) {
    fwrite(STDERR, "Arguments required\n");
    exit(1);
}

// Initialize owa
$owa = new owa_caller;

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    // setting CLI mode to true
    //$owa->setSetting('base', 'cli_mode', true);
    $owa->setSetting('base', 'request_mode', 'cli');
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
