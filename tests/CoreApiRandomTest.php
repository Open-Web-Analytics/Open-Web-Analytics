<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

final class CoreApiRandomTest extends TestCase
{
    /**
     * The first call in a process raises no deprecation. Its pool of random
     * hex started as null, and strlen(null) is deprecated on PHP 8.1+; which
     * test made the first call decided whether the suite reported it.
     *
     * In its own process, so the call is the first one.
     */
    public function testTheFirstCallRaisesNoDeprecation(): void
    {
        $code = 'error_reporting( E_ALL ); ini_set( "display_errors", "stderr" );'
              . ' require ' . var_export( OWA_DIR . 'Core/CoreAPI.php', true ) . ';'
              . ' echo \OWA\Core\CoreAPI::random( 0, 9 );';

        $out = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1' );

        $this->assertMatchesRegularExpression( '/^\d$/', trim( (string) $out ), (string) $out );
    }

    public function testAValueIsInItsRange(): void
    {
        for ( $i = 0; $i < 50; $i++ ) {

            $value = \OWA\Core\CoreAPI::random( 3, 7 );

            $this->assertGreaterThanOrEqual( 3, $value );
            $this->assertLessThanOrEqual( 7, $value );
        }
    }
}
