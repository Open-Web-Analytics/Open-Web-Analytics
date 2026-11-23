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

// Automatic updates (updater.php `preboot`), before any OWA file loads: an
// install the marker says is under way is waited for, put back, or finished.
// Finishing boots the new release and runs Step 4 before the command.
$owa_update = (require __DIR__ . '/updater.php')['preboot'](__DIR__, (array) ($_SERVER['argv'] ?? []));

if (is_int($owa_update)) {
    exit($owa_update);
}

require_once(__DIR__ . '/owa.php');

if (is_array($owa_update)) {
    $owa = new owa(['request_type' => 'cli']);
    \OWA\Module\Base\Classes\AutoUpdate\Finish::run($owa, $owa_update['finish'], $owa_update['hold']);
}

unset($owa_update);

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

// A command that answers from the code alone runs here, and OWA is never
// booted: no owa-config.php, no database, any server role
// (Controller\Cli::needsBoot(), Core\CliWithoutBoot). Every other command
// carries on below.
$without_boot = \OWA\Core\CliWithoutBoot::commandClass((string) $params['cmd']);

if ($without_boot) {

    $undeclared = \OWA\Core\Controller\Cli::undeclaredArguments($without_boot, $params);

    if ($undeclared) {
        fwrite(STDERR, sprintf("cmd=%s does not take %s. Nothing was done.\n",
            $params['cmd'], implode(', ', $undeclared)));
        exit(2);
    }

    exit((int) $without_boot::runWithoutBoot($params));
}

// Initialize owa
$config = [

    'request_type' => 'cli'
];

$owa = $owa ?? new owa( $config );

// A failed install leaves its error in its install.json; the notices it calls
// for are raised once each, by whichever booted run comes next.
\OWA\Module\Base\Classes\AutoUpdate\InstallNotices::fromDisk();

if ( $owa->isEndpointEnabled( basename( __FILE__ ) ) ) {

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

    $renamed = \OWA\Core\Controller\Cli::RENAMED;

    if (!$cmd && isset($renamed[$params['cmd']])) {
        fwrite(STDERR, sprintf("cmd=%s is now cmd=%s.\n", $params['cmd'], $renamed[$params['cmd']]));
        $exit_code = 2;
    } elseif (!$cmd) {
        fwrite(STDERR, sprintf("No command named \"%s\".\n", $params['cmd']));
        $exit_code = 2;
    } elseif ($refused) {
        \OWA\Core\ServerRole::refuse('cli', ['status' => 404, 'message' => $refused]);
    } elseif ($undeclared) {
        fwrite(STDERR, sprintf("cmd=%s does not take %s. Nothing was done.\n",
            $params['cmd'], implode(', ', $undeclared)));
        $exit_code = 2;
    } else {
        // Weak secrets do not stop a command (cmd=reset-secrets fixes them), but are said.
        $warning = \OWA\Core\ServerRole::secretsWarning($params['cmd']);
        if ($warning !== null) {
            fwrite(STDERR, 'OWA: ' . $warning . "\n");
        }
        $params['do'] = $cmd;
        echo $owa->handleRequest($params);
        $exit_code = \OWA\Core\Controller\Cli::$exit_code;
    }

}

// An install cmd=core-update-install proved: the copy, last, once the
// command and every job have finished. It exits (Classes\AutoUpdate\Install).
\OWA\Module\Base\Classes\AutoUpdate\Install::run();

if (!empty($exit_code)) {
    exit($exit_code);
}
