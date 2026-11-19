<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A tracking request must not be able to set what the server computes.
 *
 * A parameter whose name matches a server-computed column must not reach it:
 * owa_ip_address would replace the observed value that IP exclusion and
 * geolocation depend on, and a boolean column would take whatever text was
 * sent.
 *
 * Two places enforce it, from ONE definition:
 *
 *   - log.php drops server-owned parameters before they reach the event;
 *   - ProcessEvent does not re-apply them over the derivation it just ran.
 */
final class ServerOwnedPropertyTest extends TestCase
{
    public function testAComputedPropertyCannotBeSetFromTheWire(): void
    {
        $kept = Helpers::admitRequestParams( [
            'browser' => 'ludhiana',
            'is_robot'   => '1',
        ] );

        $this->assertArrayNotHasKey(
            'browser', $kept,
            'A request could set browser, which is how a city name reached a derived column.' );

        $this->assertArrayNotHasKey( 'is_robot', $kept );
    }

    public function testTheObservedRequestCannotBeForged(): void
    {
        /*
         * The more serious half. A forged ip_address defeats IP exclusion and
         * sends geolocation somewhere else; a forged is_robot decides whether the
         * sender gets filtered at all.
         *
         * `timestamp` is not in this set: it is the TRACKER's own clock
         * -- declared client-set, because the tracker sends it and the device's
         * session-timeout comparison needs it -- and it reaches no column, so
         * forging it achieves nothing. Ordering rests on `ts`, the edge stamp,
         * which has no wire key and cannot be sent at all; `event_seq` is the
         * device's own counter and is client-set by design, because nothing else
         * knows the order in which a page built its events.
         */
        $kept = Helpers::admitRequestParams( [
            'ip_address' => '1.2.3.4',
            'ts'         => '999',
            'is_robot'   => '0',
        ] );

        $this->assertSame( [], $kept );
    }

    public function testLocationAndHostAreRefusedFromTheWire(): void
    {
        /*
         * No exceptions for geo or host:
         *
         *   - accepting geo lets a request choose the location its own traffic
         *     is reported under;
         *
         *   - host is the page's host, cut from page_location by deriveHost(),
         *     so accepting one lets a request report a page under a host it
         *     was not served from.
         */
        $kept = Helpers::admitRequestParams( [
            'country' => 'India',
            'city'    => 'Ludhiana',
            'state'   => 'Punjab',
            'host'    => 'example.com',
        ] );

        $this->assertSame(
            [], $kept,
            'Location and host must never be accepted from a request. The only properties a '
            . 'tracking request may set are the ones the tracker sends.' );
    }

    /**
     * An unregistered property is REFUSED. admitRequestParams() admits two
     * things: a name
     * some event declares and a client may set, and a custom value under one of
     * the four prefixes.
     *
     * A site's own values are free. They just have to say
     * which bag they are in, which the tracker already does.
     */
    public function testAnUnregisteredPropertyIsRefused(): void
    {
        $this->assertSame( [], Helpers::admitRequestParams( [
            'nobody_declared_this' => 'x',
            'cv1_name'             => 'forged',
        ] ), 'the gate is an allowlist; an undeclared name has no way in' );

        $this->assertSame(
            [ 'eps_plan' => 'pro', 'cv1' => 'k|v' ],
            Helpers::admitRequestParams( [ 'eps_plan' => 'pro', 'cv1' => 'k|v' ] ),
            'a custom value and an older generation\'s slot are both still admitted' );
    }

    public function testTheTwoEnforcementPointsShareOneDefinition(): void
    {
        /*
         * Drift between them is the failure this whole thing exists to prevent:
         * a property protected in one place and not the other is protected
         * nowhere, and the symptom is a value silently in the wrong column.
         */
        $serverOwned = Helpers::serverOwnedProperties();

        $this->assertNotEmpty( $serverOwned );

        foreach ( [ 'browser_type', 'country', 'ip_address', 'ts' ] as $name ) {

            $this->assertArrayHasKey( $name, $serverOwned );
        }

        /*
         * is_outbound is NOT here. It is decided on the CLIENT --
         * only the client can see the DOM, and a non-web sender has no
         * page_location and target_url for the server to compare -- so the beacon
         * is the authority and admitRequestParams() takes it.
         *
         * That is not the hole the rest of this file guards. The properties above
         * are OBSERVATIONS THE SERVER MAKES about the request: the address it came
         * from, the agent, the instant it arrived, the location resolved from the
         * address. A site claiming its own clicks left its own pages is claiming
         * something about its own property, which is what a beacon is for.
         */
        $this->assertArrayNotHasKey( 'is_outbound', $serverOwned );

        $this->assertSame(
            [ 'target_url' => 'https://elsewhere.example/x', 'is_outbound' => '1' ],
            Helpers::admitRequestParams( [
                'target_url'  => 'https://elsewhere.example/x',
                'is_outbound' => '1',
            ] ),
            'the client decides outbound, so the gate has to let it through' );

        foreach ( [ 'country', 'city', 'state', 'host' ] as $name ) {

            $this->assertArrayHasKey(
                $name, $serverOwned,
                "'$name' must be server-owned. Location comes from the visitor's IP and host "
                . 'from the page URL; neither may be supplied by the request as a value of '
                . 'its own.' );
        }
    }

    /**
     * log.php passes the request through the gate: run it with a beacon that
     * also names server-owned properties, and read the event it builds at
     * Ingest::REQUEST_POST. The listener exits there, so nothing is stored,
     * and the database connect is disabled.
     */
    public function testLogPhpUsesTheFilter(): void
    {
        $dir = sys_get_temp_dir() . '/owa_log_gate_' . getmypid() . '_' . bin2hex( random_bytes( 4 ) );
        $this->assertTrue( mkdir( $dir, 0700, true ) );

        $record  = $dir . '/event.json';
        $prepend = $dir . '/prepend.php';

        // A filter listener by name: EventDispatch::filter() logs its name.
        file_put_contents( $prepend, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'owa_e_t' => 'page_view', 'owa_page_url' => 'https://www.example.com/a', 'owa_eps_plan' => 'gold',
    'owa_browser' => 'ludhiana', 'owa_is_robot' => '1', 'owa_ip_address' => '198.51.100.7',
];
function owa_test_built_event( $event ) {
    file_put_contents( getenv( 'OWA_LOG_TEST_RECORD' ), json_encode( $event->getProperties() ) );
    exit( 0 );
}
// Called once the response has ended, before OWA boots.
function fastcgi_finish_request() {
    \OWA\Core\CoreAPI::registerFilter( \OWA\Module\Base\Classes\Ingest::REQUEST_POST, 'owa_test_built_event' );
    return true;
}
PHP
        );

        try {
            exec( 'OWA_LOG_TEST_RECORD=' . escapeshellarg( $record ) . ' ' . escapeshellarg( PHP_BINARY )
                . ' -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend )
                . ' -d ' . escapeshellarg( 'disable_functions=mysqli_real_connect,mysqli_connect' )
                . ' ' . escapeshellarg( \OWA\Core\Env::root() . '/log.php' ) . ' > /dev/null 2>&1' );

            $this->assertFileExists( $record, 'log.php built no event' );
            $properties = (array) json_decode( (string) file_get_contents( $record ), true );
        } finally {
            @unlink( $record );
            @unlink( $prepend );
            @rmdir( $dir );
        }

        $this->assertSame( 'https://www.example.com/a', $properties['page_url'] ?? null );
        $this->assertSame( 'gold', $properties['eps_plan'] ?? null, 'a custom property is admitted' );

        foreach ( [ 'browser', 'is_robot', 'ip_address' ] as $name ) {

            $this->assertArrayNotHasKey( $name, $properties,
                "log.php let the request set $name, which the server computes." );
        }
    }
}
