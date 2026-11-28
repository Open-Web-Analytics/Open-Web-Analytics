<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Settings;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Base's defaults are declared once, in modules/Base/settings.php, and are the
 * values Settings::getDefaultSettingsArray() returned before that method was
 * removed (tests/fixtures/base_setting_defaults.php).
 */
final class BaseSettingDefaultsTest extends TestCase
{
    private const PROBE = __DIR__ . '/fixtures/static_config_boot_probe.php';

    /** Keys the defaults do not carry, because nothing reads them. */
    private const DROPPED = [ 'action_url', 'base_url', 'password_length', 'site_id' ];

    /**
     * Set during boot whatever the default says: setupPaths() fills the paths,
     * setMailerDomain() the From address, and the entry point the request type.
     */
    private const SET_AT_BOOT = [ 'async_log_dir', 'error_log_file', 'mailer-from', 'public_path', 'request_type' ];

    /** @return array<string, mixed> */
    private static function recorded(): array
    {
        $recorded = include __DIR__ . '/fixtures/base_setting_defaults.php';

        return array_diff_key( $recorded, array_flip( self::DROPPED ) );
    }

    public function testEveryBaseDefaultIsTheOneRecorded(): void
    {
        $defaults = Settings::baseDefaults()['base'];
        ksort( $defaults );

        $this->assertSame( self::recorded(), $defaults );
    }

    public function testADroppedKeyIsNeitherDefaultedNorDeclared(): void
    {
        $fields = \OWA\Core\Module::settingsRegistry()['fields'];

        foreach ( self::DROPPED as $key ) {

            $this->assertArrayNotHasKey( $key, Settings::baseDefaults()['base'] );
            $this->assertArrayNotHasKey( 'base|' . $key, $fields );
        }

        $this->assertNotEmpty( self::recorded() );
    }

    /**
     * A collector's boot: owa-config.php and no stored settings.
     * Base is active, and every Base setting nothing set during boot is its
     * recorded default.
     */
    public function testABootThatReadsNoStoredSettingsRunsOnTheDefaults(): void
    {
        $out     = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::PROBE ) . ' static collector 2>/dev/null' );
        $verdict = json_decode( trim( (string) $out ), true );

        $this->assertIsArray( $verdict, 'the boot probe printed no JSON' );
        $this->assertFalse( $verdict['connected'], 'the boot read nothing from the database' );
        $this->assertContains( 'base', $verdict['active_modules'] );

        $skip = array_flip( array_merge( self::SET_AT_BOOT, $verdict['constants'] ) );

        foreach ( array_diff_key( self::recorded(), $skip ) as $key => $value ) {

            $this->assertArrayHasKey( $key, $verdict['base'], "base.$key is missing after boot" );
            $this->assertSame( $value, $verdict['base'][ $key ], "base.$key after boot" );
        }

        foreach ( self::DROPPED as $key ) {

            $this->assertArrayNotHasKey( $key, $verdict['base'], "base.$key was dropped" );
        }
    }
}
