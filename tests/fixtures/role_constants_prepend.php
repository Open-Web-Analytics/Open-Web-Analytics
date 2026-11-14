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
