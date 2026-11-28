<?php

/**
 * Defines the owa-config.php constants a test names, before an entry point
 * loads: run as `php -d auto_prepend_file=<this file> <entry point>` with the
 * constants as a JSON object in OWA_PROBE_CONSTANTS (tests/ServerRoleBootTest.php).
 * The install's own owa-config.php still loads; what it defines too keeps its
 * first value.
 */
foreach ( (array) json_decode( (string) getenv( 'OWA_PROBE_CONSTANTS' ), true ) as $name => $value ) {

    if ( strpos( (string) $name, 'OWA_' ) === 0 && ! defined( $name ) ) {
        define( $name, $value );
    }
}

/*
 * With OWA_PROBE_STUB_HTTP set, cmd=server-check's HTTP requests are answered
 * here through ServerCheckCli::$fetch, as ServerCheckCliTest stubs them: the
 * REST API's two 401s, and log.php's image.
 */
if ( getenv( 'OWA_PROBE_STUB_HTTP' ) ) {

    // Composer's loader goes first, so this one is registered in front of it.
    if ( is_file( dirname( __DIR__, 2 ) . '/vendor/autoload.php' ) ) {

        require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
    }

    spl_autoload_register( static function ( $class ) {

        if ( $class !== 'OWA\Module\Base\Controller\ServerCheckCli' ) {

            return;
        }

        require_once dirname( __DIR__, 2 ) . '/modules/Base/Controller/ServerCheckCli.php';

        \OWA\Module\Base\Controller\ServerCheckCli::$fetch = static function ( $url, $headers ) {

            if ( strpos( $url, 'log.php' ) !== false ) {

                return [ 200, 'image/gif', '' ];
            }

            $msg = $headers
                ? \OWA\Core\Controller::refusedAppPasswordMessage()
                : \OWA\Core\Controller::notAuthenticatedMessage();

            return [ 401, 'application/json', json_encode( [ 'error' => [ [ 'headline' => 'Not authenticated.', 'msg' => $msg ] ] ] ) ];
        };
    }, true, true );
}
