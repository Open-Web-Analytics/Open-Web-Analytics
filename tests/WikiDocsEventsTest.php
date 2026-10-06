<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers;

/**
 * The generated event-types, event-properties and custom-properties wiki
 * sections cover the whole registry: every first-class event and every
 * tracking property has a description and a row, a tracker property's row
 * names its wire key, and maintainer notes stay out of the page.
 *
 * Runs the generator as a separate process, the way the publish workflow does,
 * so a section that only works inside the test bootstrap fails here.
 */
final class WikiDocsEventsTest extends TestCase
{
    private static function generate( string $section ): string
    {
        $cmd = escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 '
             . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' ) . ' '
             . escapeshellarg( $section ) . ' 2>&1';

        exec( $cmd, $lines, $status );
        $out = implode( "\n", $lines );

        self::assertSame( 0, $status, "generate.php $section failed:\n$out" );

        return $out;
    }

    private static function registry(): array
    {
        $config = json_decode( file_get_contents( OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

        self::assertIsArray( $config );
        self::assertNotEmpty( $config );

        return $config;
    }

    private static function events(): array
    {
        $declared = include OWA_DIR . 'modules/Base/config/tracking_events.php';

        self::assertIsArray( $declared );

        return $declared;
    }

    public function testEveryEventAndPropertyHasADescription(): void
    {
        foreach ( self::events() as $name => $attributes ) {
            self::assertNotSame( '', trim( (string) ( $attributes['description'] ?? '' ) ),
                "event $name has no description" );
        }

        foreach ( self::registry() as $name => $p ) {
            self::assertNotSame( '', trim( (string) ( $p['description'] ?? '' ) ),
                "property $name has no description" );
        }
    }

    public function testEveryFirstClassEventHasARowWithItsDescription(): void
    {
        $md     = self::generate( 'event-types' );
        $events = self::events();
        $names  = TrackingEventHelpers::eventNames();

        self::assertNotEmpty( $names );

        foreach ( $names as $name ) {
            self::assertStringContainsString(
                "\n| `$name` | " . str_replace( '|', '\\|', trim( $events[ $name ]['description'] ) ) . ' | ',
                $md, "event $name has no row, or its row does not carry its description" );
        }

        self::assertSame( count( $names ), preg_match_all( '/^\| `[a-z_]+` \| /m', $md ),
            'one row per event, and no others' );
    }

    public function testEveryPropertyHasARowWithWhatItIsSetFrom(): void
    {
        $md = self::generate( 'event-properties' );

        foreach ( self::registry() as $name => $p ) {

            $from = (array) ( $p['from'] ?? array() );
            self::assertNotEmpty( $from, "$name declares no `from`" );

            // For a tracker property `from` is the wire key; for the others it is
            // the request keys or the properties it derives from.
            $first = '`' . implode( '`, `', $from ) . '`';

            self::assertMatchesRegularExpression(
                '/^\| `' . preg_quote( $name, '/' ) . '` \| ' . preg_quote( $first, '/' ) . '/m',
                $md, "$name has no row, or its row does not lead with $first" );
        }

        self::assertSame( count( self::registry() ), preg_match_all( '/^\| `[^`]+` \| `/m', $md ),
            'one row per property, and no others' );

        foreach ( self::registry() as $name => $p ) {

            self::assertMatchesRegularExpression(
                '/^\| `' . preg_quote( $name, '/' ) . '` \|.*\| ' . preg_quote( $p['description'], '/' ) . ' \|$/m',
                $md, "$name's row does not end with its description" );

            if ( ! empty( $p['note'] ) ) {
                self::assertStringNotContainsString( trim( $p['note'] ), $md, "$name's maintainer note is published" );
            }
        }
    }

    public function testEachEventsSectionListsExactlyWhatThatEventCarries(): void
    {
        foreach ( TrackingEventHelpers::eventNames() as $event ) {

            $md       = self::generate( "event-properties-$event" );
            $expected = TrackingEventHelpers::propertiesForEvent( $event );

            preg_match_all( '/^\| `([^`]+)` \| `/m', $md, $m );

            $listed = $m[1];
            sort( $listed );
            sort( $expected );

            self::assertSame( $expected, $listed, "event-properties-$event" );
        }
    }

    public function testAnUnknownEventIsRefused(): void
    {
        $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' )
             . ' event-properties-no_such_event 2>&1';

        exec( $cmd, $lines, $status );

        self::assertSame( 1, $status, implode( "\n", $lines ) );
    }

    public function testCustomPropertyPrefixesAreTheOnesTheGateAdmits(): void
    {
        $md = self::generate( 'custom-properties' );

        foreach ( TrackingEventHelpers::CUSTOM_PREFIXES as $prefix ) {
            self::assertMatchesRegularExpression( '/^\| `' . preg_quote( $prefix, '/' ) . '` \| /m', $md );
        }

        self::assertSame( count( TrackingEventHelpers::CUSTOM_PREFIXES ), preg_match_all( '/^\| `[a-z]+_` \| /m', $md ) );
        self::assertStringContainsString(
            (string) \OWA\Module\Base\Handler\EventRawHandlers::MAX_CUSTOM_PROPERTIES, $md );
    }

    public function testTrackerWireKeysAreTheCompatMapsWireEntries(): void
    {
        // The table's wire keys must be what the server actually renames, or the
        // page would document keys a beacon cannot use.
        $compat = (array) \OWA\Core\CoreAPI::loadConf( 'beacon_compat.php', 'beacon.compat' );
        $wire   = array();

        foreach ( (array) $compat['renames'] as $r ) {
            if ( $r['role'] === 'wire' ) {
                $wire[ $r['from'] ] = true;
            }
        }

        foreach ( self::registry() as $name => $p ) {

            if ( ( $p['set_by'] ?? '' ) !== 'client' ) {
                continue;
            }

            foreach ( (array) $p['from'] as $key ) {
                self::assertArrayHasKey( $key, $wire, "$name is sent as $key, which no wire rename reads" );
            }
        }
    }
}
