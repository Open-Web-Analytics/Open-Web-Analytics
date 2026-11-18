<?php

/**
 * OWA Comand Line Interface (CLI)
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

// Parse the command line. See Lib::parseCliArgs() for the accepted forms.
$params = \OWA\Core\Lib::parseCliArgs($_SERVER['argv'] ?? []);

// Exit codes: 0 done, 1 the command failed, 2 the command line is wrong.
if (!is_array($params)) {
    fwrite(STDERR, $params . "\n");
    exit(2);
}

unset($params['action']);
unset($params['do']);
if (empty($params['cmd'])) {
    fwrite(STDERR, "Missing a command argument: cmd=<command>\n");
    exit(2);
}

// Initialize owa
$config = [

    'request_type' => 'cli'
];

$owa = new owa( $config );

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

    // setting CLI mode to true
    //$owa->setSetting('base', 'cli_mode', true);
    
    // An admin with no user_id. A login name here would reach nonces,
    // named-user logging and anything stored per user.
    $owa->setCurrentUser('admin');
    // run controller or view and echo page content
    $s = \OWA\Core\CoreAPI::serviceSingleton();
    $s->loadCliCommands();

    $cmd = $s->getCliCommandClass($params['cmd']);

    // A command runs only on the roles it names (Controller\Cli::serverRoles()).
    $refused = $cmd ? \OWA\Core\ServerRole::current()->commandProblem($params['cmd'], $cmd) : null;

    // A command takes only the arguments its usage() declares.
    $map        = $cmd ? $s->getMapValue('actions', $cmd) : null;
    $undeclared = is_array($map) && !empty($map['class_name'])
        ? \OWA\Core\Controller\Cli::undeclaredArguments($map['class_name'], $params)
        : [];

    if (!$cmd) {
        fwrite(STDERR, sprintf("No command named \"%s\".\n", $params['cmd']));
        $exit_code = 2;
    } elseif ($refused) {
        \OWA\Core\ServerRole::refuse('cli', ['status' => 404, 'message' => $refused]);
    } elseif ($undeclared) {
        fwrite(STDERR, sprintf("cmd=%s does not take %s. Nothing was done.\n",
            $params['cmd'], implode(', ', $undeclared)));
        $exit_code = 2;
    } else {
        $params['do'] = $cmd;
        echo $owa->handleRequest($params);
        $exit_code = \OWA\Core\Controller\Cli::$exit_code;
    }

}

if (!empty($exit_code)) {
    exit($exit_code);
}
