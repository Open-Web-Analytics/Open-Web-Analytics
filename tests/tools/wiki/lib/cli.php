<?php
/**
 * The CLI reference: every registered command, from the command map and each
 * controller's usage() declaration.
 *
 * The capability is read from the controller's source the way
 * ControllerCapabilityContractTest reads it, because constructing a controller
 * has side effects -- CoreUpdateSchemaCli defines OWA_UPDATING -- that would
 * leak into every section generated after this one.
 */

require_once __DIR__ . '/modules.php';

/**
 * @return array<string,array{module:string,class:string,capability:?string,roles:string[],description:string,arguments:array<string,array<string,mixed>>,deprecated:bool}>
 *         keyed by command, sorted; arguments are Core\Params::declarations(), retired ones left out
 */
function owa_wiki_cli_commands() {

    $s        = \OWA\Core\CoreAPI::serviceSingleton();
    $actions  = [];
    $commands = [];

    foreach ( owa_wiki_all_modules() as $module ) {
        $actions += (array) $module->cli_commands;
    }

    foreach ( $actions as $command => $action ) {

        $class = $s->getMapValue( 'actions', $action )['class_name'] ?? null;

        if ( ! $class || ! class_exists( $class ) ) {
            throw new RuntimeException( "cmd=$command names action $action, which resolves to no class." );
        }

        $usage = method_exists( $class, 'usage' ) ? $class::usage() : [];

        $commands[ $command ] = [
            'module'      => strstr( $action, '.', true ),
            'class'       => $class,
            'capability'  => owa_wiki_cli_capability( $class ),
            'roles'       => method_exists( $class, 'serverRoles' ) ? (array) $class::serverRoles() : \OWA\Core\Controller\Cli::serverRoles(),
            'description' => (string) ( $usage['description'] ?? '' ),
            'arguments'   => array_filter( \OWA\Core\Params::declarations( (array) ( $usage['arguments'] ?? [] ) ),
                static fn ( $d ) => ! isset( $d['retired'] ) ),
            'deprecated'  => ! empty( $usage['deprecated'] ),
        ];
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

/**
 * One argument's row of a command's table: how it is written, the value it
 * takes, its default (or Required), and its description.
 *
 * @param  array<string,mixed> $d a declaration from Core\Params::declarations()
 * @return array{string,string,string,string}
 */
function owa_wiki_cli_argument( array $d ) {

    $P     = \OWA\Core\Params::class;
    $range = static function ( $d ) {

        $min = $d['min'] ?? null;
        $max = $d['max'] ?? null;

        if ( $min !== null && $max !== null ) {
            return " from $min to $max";
        }

        return $min !== null ? " of at least $min" : ( $max !== null ? " of at most $max" : '' );
    };
    $code  = static fn ( array $values ) => implode( ', ', array_map( static fn ( $v ) => "`$v`", $values ) );

    switch ( $d['type'] ) {

        case 'flag':
            $value = 'A switch.';
            break;

        case 'integer':
            $value = 'A whole number' . $range( $d ) . '.';
            break;

        case 'duration':
            $value = 'A number of seconds' . $range( $d ) . '.';
            break;

        case 'select':
            $value = ( empty( $d['list'] ) ? 'One of ' : 'One or more of ' ) . $code( $P::options( $d ) )
                . ( empty( $d['list'] ) ? '.' : ', separated by commas.' );
            break;

        case 'date':
            $value = empty( $d['ago'] )
                ? 'A date, yyyymmdd.'
                : 'A date, yyyymmdd, or a period back from today, such as `90days`, `18m` or `2years`.';
            $value .= isset( $d['not_before'] ) ? " Not before `{$d['not_before']}=`." : '';
            break;

        case 'email':
            $value = 'An email address.';
            break;

        case 'property':
            $value = 'An existing Property\'s id.';
            break;

        case 'profile':
            $kind  = trim( ( ! empty( $d['live'] ) ? 'live ' : '' ) . ( $d['stream'] ?? '' ) );
            $value = 'An existing ' . ( $kind !== '' ? $kind . ' ' : '' ) . 'Profile\'s site id.';
            break;

        case 'module':
            $value = 'A module\'s name.';
            break;

        case 'user':
            $value = 'An existing user\'s user id.';
            break;

        default:
            $value = empty( $d['list'] ) ? 'Text.' : 'One or more, separated by commas.';
    }

    if ( ! empty( $d['required'] ) ) {

        $default = 'Required';

    } elseif ( array_key_exists( 'default', $d ) && $d['default'] !== null && $d['type'] !== 'flag' ) {

        $default = '`' . $d['default'] . '`';

    } else {

        $default = '';
    }

    return [ $P::written( $d ), $value, $default, (string) ( $d['description'] ?? '' ) ];
}
