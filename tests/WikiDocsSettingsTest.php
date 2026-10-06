<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Core\Module;

/**
 * The generated settings sections cover the whole registry: every declared
 * setting gets a row under its module, a setting that cannot be stored says so,
 * and a fieldset group lists the fieldsets of modules that are not active.
 *
 * Runs the generator as a separate process, the way the publish workflow does,
 * so a section that only works inside the test bootstrap fails here.
 */
final class WikiDocsSettingsTest extends TestCase
{
    private static function run_section( string $section, &$status = null ): string
    {
        $cmd = escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 '
             . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' ) . ' '
             . escapeshellarg( $section ) . ' 2>&1';

        exec( $cmd, $lines, $status );

        return implode( "\n", $lines );
    }

    private static function generate( string $section ): string
    {
        $out = self::run_section( $section, $status );

        self::assertSame( 0, $status, "generate.php $section failed:\n$out" );

        return $out;
    }

    /** @return array<string,string> module => that module's part of the full output */
    private static function byModule( string $out ): array
    {
        $parts = array();

        foreach ( preg_split( '/^### /m', $out ) as $chunk ) {

            if ( preg_match( '/^`([A-Za-z_]+)`/', $chunk, $m ) ) {
                $parts[ $m[1] ] = $chunk;
            }
        }

        return $parts;
    }

    private static function rowFor( string $table, string $key ): ?string
    {
        foreach ( explode( "\n", $table ) as $line ) {

            if ( strpos( $line, '| `' . $key . '` |' ) === 0 ) {
                return $line;
            }
        }

        return null;
    }

    public function testEveryDeclaredSettingHasARowMarkedWithWhereItIsStored(): void
    {
        $parts      = self::byModule( self::generate( 'settings' ) );
        $mechanical = Module::mechanicalSettings();
        $checked    = 0;

        foreach ( Module::settingsRegistry()['fields'] as $id => $args ) {

            list( $module, $key ) = explode( '|', $id, 2 );

            if ( isset( $mechanical[ $key ] ) || ! empty( $args['internal'] ) ) {
                continue;
            }

            $this->assertArrayHasKey( $module, $parts, "no table for module $module" );

            $row = self::rowFor( $parts[ $module ], $key );

            $this->assertNotNull( $row, "$id has no row" );

            $stored = ! empty( $args['storable'] ) || ! empty( $args['autoload'] );

            $this->assertStringContainsString( $stored ? '| database |' : '| config only |', $row,
                "$id is " . ( $stored ? 'storable' : 'config-file-only' ) . ' but its row says otherwise' );

            $checked++;
        }

        // A registry that came back empty would pass the loop vacuously.
        $this->assertGreaterThan( 100, $checked );
    }

    /**
     * Declared settings that nothing reads, so there is nothing true to say
     * about them. Each is a candidate for removal; a description added to one
     * fails the test below until it is taken off this list.
     */
    private const UNREAD = array(
        'base|action_url', 'base|base_url', 'base|clean_query_string', 'base|db_force_new_connections',
        'base|memcachedPersistantConnections', 'base|memcachedServers', 'base|modules', 'base|password_length',
        'base|plugin_dir', 'base|site_id', 'base|source_param',
    );

    public function testEveryListedSettingHasADescriptionUnlessNothingReadsIt(): void
    {
        $mechanical = Module::mechanicalSettings();
        $fields     = Module::settingsRegistry()['fields'];
        $checked    = 0;

        foreach ( $fields as $id => $args ) {

            list( , $key ) = explode( '|', $id, 2 );

            if ( isset( $mechanical[ $key ] ) || ! empty( $args['internal'] ) ) {
                continue;
            }

            $described = trim( (string) ( $args['description'] ?? '' ) ) !== '';

            if ( in_array( $id, self::UNREAD, true ) ) {

                $this->assertFalse( $described, "$id has a description now; take it off UNREAD" );

            } else {

                $this->assertTrue( $described, "$id has no description; the settings reference would show it blank" );
                $checked++;
            }
        }

        foreach ( self::UNREAD as $id ) {
            $this->assertArrayHasKey( $id, $fields, "$id is no longer declared; take it off UNREAD" );
        }

        $this->assertGreaterThan( 100, $checked );
    }

    public function testInternalSettingsAreLeftOut(): void
    {
        $out = self::generate( 'settings' );

        foreach ( array( 'install_complete', 'tracker_build', 'v1_tables_dropped', 'main_url', 'provisioned' ) as $key ) {
            $this->assertStringNotContainsString( '| `' . $key . '` |', $out, "$key is internal and should not be listed" );
        }

        $this->assertStringContainsString( 'are not listed', $out );
    }

    public function testConfigFileConstantsAreNamedOnTheirSetting(): void
    {
        $base = self::byModule( self::generate( 'settings' ) )['base'];

        // One from each of the two forms applyConfigConstants() pairs them in.
        foreach ( array(
            'timezone'                => 'OWA_TIMEZONE',
            'db_host'                 => 'OWA_DB_HOST',
            'partition_detail_months' => 'OWA_PARTITION_DETAIL_MONTHS',
            'tracker_ingest_drain'    => 'OWA_TRACKER_INGEST_DRAIN',
        ) as $key => $constant ) {

            $this->assertStringContainsString( '`' . $constant . '`', (string) self::rowFor( $base, $key ),
                "$key should name $constant" );
        }

        // Set only by a constant, declared by no module.
        $this->assertNotNull( self::rowFor( $base, 'data_dir' ) );
    }

    public function testAGroupListsEveryModulesFieldsetsIncludingInactiveOnes(): void
    {
        $out = self::generate( 'settings-tracking_tag' );

        // Base's, and Domstream's, which a configless boot does not load.
        foreach ( array( 'tracker_page_views', 'tracker_cookie_domain', 'record', 'sample_rate' ) as $key ) {
            $this->assertNotNull( self::rowFor( $out, $key ), "$key is missing from the tracking_tag group" );
        }

        $this->assertStringContainsString( '**Page interaction recording**', $out );
        $this->assertLessThan( strpos( $out, '**Page interaction recording**' ), strpos( $out, '**Page views**' ),
            "Base's fieldsets come before a module's" );

        // Nothing outside the group.
        $this->assertNull( self::rowFor( $out, 'timezone' ) );
    }

    public function testAModuleArgumentGivesThatModulesTable(): void
    {
        $out = self::generate( 'settings-maxmind_geoip' );

        $this->assertNotNull( self::rowFor( $out, 'db_edition' ) );
        $this->assertNull( self::rowFor( $out, 'timezone' ) );
    }

    public function testAnUnknownArgumentIsRefused(): void
    {
        $out = self::run_section( 'settings-no_such_thing', $status );

        $this->assertNotSame( 0, $status );
        $this->assertStringContainsString( 'no module or fieldset group', $out );
    }
}
