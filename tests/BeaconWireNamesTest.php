<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Beacon\Compat;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The tracker's short wire keys and the server's renames are one table.
 *
 * The tracker translates in modules/Base/src/tracker/WireNames.js; the server
 * translates back from the `wire` entries in conf/beacon_compat.php, and the
 * registry's `from` declares the key each client property arrives under. Three
 * statements of one mapping, held to each other here in every direction.
 */
final class BeaconWireNamesTest extends TestCase
{
    /** @return array<string,string> tracker property => wire key, parsed from WireNames.js */
    private function trackerTable(): array
    {
        $js = (string) file_get_contents( OWA_DIR . 'modules/Base/src/tracker/WireNames.js' );

        $this->assertSame( 1, preg_match( '/export const WIRE_NAMES = \{(.*?)\n\};/s', $js, $m ),
            'WIRE_NAMES not found in WireNames.js' );

        preg_match_all( "/^\s*([A-Za-z_]+):\s*'([^']+)',?\s*$/m", $m[1], $pairs, PREG_SET_ORDER );

        $out = array();

        foreach ( $pairs as $pair ) {

            $out[ $pair[1] ] = $pair[2];
        }

        return $out;
    }

    /** @return array<string,string> wire key => property, the compat index's `wire` entries */
    private function wireRenames(): array
    {
        $out = array();

        foreach ( (array) ( include OWA_DIR . 'conf/beacon_compat.php' )['renames'] as $entry ) {

            if ( $entry['role'] === 'wire' ) {

                $out[ $entry['from'] ] = $entry['to'];
            }
        }

        return $out;
    }

    /** The tracker keeps one property under its 1.x short name; the server's name differs. */
    private static function propertyFor( string $tracker_name ): string
    {
        return $tracker_name === 'nps' ? 'num_prior_sessions' : $tracker_name;
    }

    public function testEveryTrackerKeyIsRenamedBackOnTheServer(): void
    {
        $table = $this->trackerTable();

        $this->assertCount( 54, $table, 'the parse found a different number of entries than WireNames.js holds' );

        $expected = array();

        foreach ( $table as $name => $wire ) {

            $expected[ $wire ] = self::propertyFor( $name );
        }

        ksort( $expected );
        $got = $this->wireRenames();
        ksort( $got );

        $this->assertSame( $expected, $got,
            'conf/beacon_compat.php `wire` entries and WireNames.js disagree' );
    }

    /** Every client property is in the table, and the registry declares its short key. */
    public function testTheRegistryDeclaresEachClientPropertysWireKey(): void
    {
        $table = array();

        foreach ( $this->trackerTable() as $name => $wire ) {

            $table[ self::propertyFor( $name ) ] = $wire;
        }

        $clients = array();

        foreach ( Helpers::allProperties() as $name => $definition ) {

            if ( ( $definition['set_by'] ?? '' ) === 'client' ) {

                $clients[ $name ] = Helpers::wireKeysFor( $name );
            }
        }

        $this->assertNotEmpty( $clients );

        foreach ( $clients as $name => $from ) {

            $this->assertArrayHasKey( $name, $table, "$name is client-set and has no wire key in WireNames.js" );
            $this->assertSame( array( $table[ $name ] ), $from, "$name's registry `from` is not its wire key" );
        }

        $this->assertSame( array(), array_values( array_diff( array_keys( $table ), array_keys( $clients ) ) ),
            'WireNames.js names a property the registry does not declare client-set' );
    }

    /**
     * No key collides with anything else on the wire: another key, a property
     * name the gate admits directly, or the custom prefixes.
     */
    public function testTheKeysAreDistinctFromEverythingElseOnTheWire(): void
    {
        $table = $this->trackerTable();
        $keys  = array_values( $table );

        $this->assertSame( $keys, array_values( array_unique( $keys ) ), 'two properties share a wire key' );

        $properties = array_keys( Helpers::allProperties() );

        foreach ( $keys as $key ) {

            $this->assertMatchesRegularExpression( '/^[a-z_][a-z0-9_]*$/', $key );
            $this->assertNotContains( $key, $properties, "$key is also a property name" );

            foreach ( Helpers::CUSTOM_PREFIXES as $prefix ) {

                $this->assertStringStartsNotWith( $prefix, $key );
            }
        }
    }

    /** A short-keyed beacon through the gate and the compat layer reaches the long names. */
    public function testAShortKeyedBeaconArrivesUnderThePropertyNames(): void
    {
        $params = Helpers::admitRequestParams( array(
            '_v'        => '2',
            'site'      => 'abc123',
            'v_nps'     => '3',
            's_new'     => '1',
            'v_new'     => '0',
            'p_l'       => 'https://example.test/a?b=c',
            'eps_plan'  => 'pro',
            'vpn_seats' => '4',
            'not_a_key' => 'refused',
        ) );

        $this->assertArrayNotHasKey( 'not_a_key', $params );

        $event = new \OWA\Module\Base\Classes\Event;
        $event->setProperties( $params );

        Compat::apply( $event );

        $this->assertSame( '2', $event->get( 'beacon_version' ) );
        $this->assertSame( 'abc123', $event->get( 'site_id' ) );
        $this->assertSame( '3', $event->get( 'num_prior_sessions' ) );
        $this->assertSame( 'https://example.test/a?b=c', $event->get( 'page_location' ) );
        $this->assertTrue( (bool) $event->get( 'is_new_session_start' ) );
        $this->assertFalse( (bool) $event->get( 'is_new_visitor_created' ), "'0' must read as false" );
        $this->assertSame( 'pro', $event->get( 'eps_plan' ) );
        $this->assertSame( '4', $event->get( 'vpn_seats' ) );
    }
}
