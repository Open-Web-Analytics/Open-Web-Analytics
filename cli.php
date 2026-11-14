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
// CliInvocation has no dependencies of its own, so requiring it here does not
// start OWA for a caller that is about to be turned away. See that class for
// why argc alone is not enough to tell a shell invocation from a request.
require_once(__DIR__ . '/Core/CliInvocation.php');

define('OWA_CLI', \OWA\Core\CliInvocation::detect(php_sapi_name(), $_SERVER));

if (!OWA_CLI)
{
    // Fail with 404 if called over HTTP so it looks like the script
    // just doesn't exist.
    if (isset($_SERVER['SERVER_PROTOCOL'])) {
        header("$_SERVER[SERVER_PROTOCOL] 404 Not Found");
    }
    exit();
}

require_once(__DIR__ . '/owa.php');

// Parse the command line. See owa_lib::parseCliArgs() for the accepted forms.
$params = \OWA\Core\Lib::parseCliArgs($argv);

if (!is_array($params)) {
    fwrite(STDERR, $params . "\n");
    exit(1);
}

unset($params['action']);
unset($params['do']);
if (empty($params)) {
    fwrite(STDERR, "Arguments required\n");
    exit(1);
}

// Initialize owa
$config = [

    'request_type' => 'cli'
];

$owa = new owa( $config );

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    // setting CLI mode to true
    //$owa->setSetting('base', 'cli_mode', true);
    
    // setting user auth
    $owa->setCurrentUser('admin', 'cli-user');
    // run controller or view and echo page content
    $s = \OWA\Core\CoreAPI::serviceSingleton();
    $s->loadCliCommands();

    if (array_key_exists('cmd', $params)) {

        $cmd = $s->getCliCommandClass($params['cmd']);

        // A command runs only on the roles it names (Controller\Cli::serverRoles()).
        $refused = $cmd ? \OWA\Core\ServerRole::current()->commandProblem($params['cmd'], $cmd) : null;

        if ($refused) {
            \OWA\Core\ServerRole::refuse('cli', array('status' => 404, 'message' => $refused));
        } elseif ($cmd) {
            $params['do'] = $cmd;
            echo $owa->handleRequest($params);
            $exit_code = \OWA\Core\Controller\Cli::$exit_code;
        } else {
            \OWA\Core\CoreAPI::notice( "Invalid command name.");
        }

    } else {
        \OWA\Core\CoreAPI::notice("Missing a command argument.");
    }

} else {
    // unload owa
    $owa->restInPeace();
}

if (!empty($exit_code)) {
    exit($exit_code);
}

?>
