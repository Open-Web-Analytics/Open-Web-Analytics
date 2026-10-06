<?php
/**
 * The CLI reference: every registered command, from the command map and each
 * controller's usage() declaration.
 *
 * The capability is read from the controller's source the way
 * ControllerCapabilityContractTest reads it, because constructing a controller
 * has side effects -- InstallCli defines OWA_INSTALLING, UpdatesApplyCli
 * OWA_UPDATING -- that would leak into every section generated after this one.
 */

require_once __DIR__ . '/modules.php';

/**
 * @return array<string,array{module:string,class:string,capability:?string,description:string,arguments:array<string,string>}>
 *         keyed by command, sorted
 */
function owa_wiki_cli_commands() {

    $s        = \OWA\Core\CoreAPI::serviceSingleton();
    $actions  = array();
    $commands = array();

    foreach ( owa_wiki_all_modules() as $module ) {
        $actions += (array) $module->cli_commands;
    }

    foreach ( $actions as $command => $action ) {

        $class = $s->getMapValue( 'actions', $action )['class_name'] ?? null;

        if ( ! $class || ! class_exists( $class ) ) {
            throw new RuntimeException( "cmd=$command names action $action, which resolves to no class." );
        }

        $usage = method_exists( $class, 'usage' ) ? $class::usage() : array();

        $commands[ $command ] = array(
            'module'      => strstr( $action, '.', true ),
            'class'       => $class,
            'capability'  => owa_wiki_cli_capability( $class ),
            'description' => (string) ( $usage['description'] ?? '' ),
            'arguments'   => (array) ( $usage['arguments'] ?? array() ),
        );
    }

    ksort( $commands );

    return $commands;
}

/** The first setRequiredCapability() literal up the class chain, or null. */
function owa_wiki_cli_capability( $class ) {

    for ( $r = new ReflectionClass( $class ); $r; $r = $r->getParentClass() ) {

        if ( $r->getName() === 'OWA\\Core\\Controller' ) {
            break;
        }

        $src = (string) file_get_contents( $r->getFileName() );

        if ( preg_match( '/->setRequiredCapability\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $src, $m ) ) {
            return $m[1];
        }
    }

    return null;
}
