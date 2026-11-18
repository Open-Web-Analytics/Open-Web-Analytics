<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A config-file constant is the last word, and makes the setting static.
 *
 * Constants have to be applied EARLY -- the database credentials are among
 * them, so load() cannot run until they exist -- and load() then merges stored
 * values over whatever they set. So the stored values a constant supplied are
 * removed before the merge, and the constants are also re-applied as the last
 * pass of boot: a governed key is static, nothing stores a value for it and
 * nothing goes looking for one. The options form's disabled field is the
 * ordinary consequence of the declaration.
 *
 * SUBPROCESS, because a constant is a process-global define and the runner has
 * already booted OWA once against the live config.
 */
final class SettingsConfigConstantTest extends TestCase
{
    private const PROBE = __DIR__ . '/fixtures/config_constant_probe.php';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the probe writes a stored row for the constant to beat' );
        }
    }

    /** @return array<string,string> */
    private function probe(): array
    {
        $descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];

        $proc = proc_open(
            escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::PROBE ),
            $descriptors, $pipes, dirname( __DIR__ ) );

        $this->assertIsResource( $proc, 'could not spawn the constant probe' );

        $stdout = (string) stream_get_contents( $pipes[1] );

        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $proc );

        $out = [];

        foreach ( explode( "\n", $stdout ) as $line ) {

            if ( strpos( $line, '=' ) !== false && strpos( $line, 'PROBE ' ) === 0 ) {

                list( $k, $v ) = explode( '=', substr( $line, 6 ), 2 );

                $out[ trim( $k ) ] = trim( $v );
            }
        }

        $this->assertNotEmpty( $out, "probe produced no readings:\n" . $stdout );

        return $out;
    }

    public function testAConstantBeatsAStoredValueAndMakesTheKeyStatic(): void
    {
        $r = $this->probe();

        $this->assertSame( 'Pacific/Auckland', $r['effective'] ?? null,
            'the constant is the last pass of boot, so it wins over the merged row' );

        $this->assertSame( 'OWA_TIMEZONE', $r['constant'] ?? null,
            'and the NAME is recorded, because "set somewhere in owa-config.php" '
          . 'is not an actionable thing to tell an operator' );

        $this->assertSame( 'no', $r['persistable'] ?? null,
            'a governed key is static: nothing may store a value nothing would read' );

        $this->assertSame( 'no', $r['persistable_after_late_registration'] ?? null,
            'and a module registering AFTER boot cannot hand storability back -- '
          . 'constants are applied during boot, module registration happens later' );
    }
}
