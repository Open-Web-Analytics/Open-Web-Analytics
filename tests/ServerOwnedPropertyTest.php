<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A tracking request must not be able to set what the server computes.
 *
 * log.php copied every owa_* parameter onto the event, so any parameter whose
 * name matched a column was written to that column. A request carrying
 * owa_is_browser=ludhiana put a city name into a boolean column -- which is how
 * this was found, in a production log -- and owa_ip_address or owa_timestamp
 * would have replaced the observed values that IP exclusion, geolocation and
 * event ordering all depend on.
 *
 * Two places enforce it, from ONE definition:
 *
 *   - log.php drops server-owned parameters before they reach the event;
 *   - ProcessEvent no longer re-applies them over the derivation it just ran.
 *
 * That second one was the actual defect. The derivation always worked: it
 * computed is_browser correctly and was then overwritten by a "re-apply
 * sanitized properties" step that treated every non-regular property as
 * unregistered input.
 */
final class ServerOwnedPropertyTest extends TestCase
{
    public function testAComputedPropertyCannotBeSetFromTheWire(): void
    {
        $kept = Helpers::admitRequestParams( array(
            'browser' => 'ludhiana',
            'is_robot'   => '1',
        ) );

        $this->assertArrayNotHasKey(
            'browser', $kept,
            'A request could set browser, which is how a city name reached a derived column.' );

        $this->assertArrayNotHasKey( 'is_robot', $kept );
    }

    public function testTheObservedRequestCannotBeForged(): void
    {
        /*
         * The more serious half. A forged ip_address defeats IP exclusion and
         * sends geolocation somewhere else; a forged timestamp reorders events;
         * a forged is_robot decides whether the sender gets filtered at all.
         */
        $kept = Helpers::admitRequestParams( array(
            'ip_address' => '1.2.3.4',
            'timestamp'  => '999',
            'is_robot'   => '0',
        ) );

        $this->assertSame( array(), $kept );
    }

    public function testLocationAndHostAreRefusedFromTheWire(): void
    {
        /*
         * No exceptions for geo or host. An earlier version of this fix
         * declared country, city, state and host client-settable, on the
         * grounds that LocationHandlers accepts client geo and that a caller
         * may supply a host. Both are wrong to allow:
         *
         *   - accepting geo lets a request choose the location its own traffic
         *     is reported under, and the tracker only ever sends city/state/
         *     country as an ecommerce BILLING address -- which is a different
         *     fact that happens to share these names;
         *
         *   - host is not the site's host. getHostDomain() derives it from a
         *     reverse-DNS lookup of the visitor's IP through the Public Suffix
         *     List, so accepting one lets a request forge the visitor's
         *     resolved hostname.
         */
        $kept = Helpers::admitRequestParams( array(
            'country' => 'India',
            'city'    => 'Ludhiana',
            'state'   => 'Punjab',
            'host'    => 'example.com',
        ) );

        $this->assertSame(
            array(), $kept,
            'Location and host must never be accepted from a request. The only properties a '
            . 'tracking request may set are the ones the tracker sends.' );
    }

    /**
     * An unregistered property is REFUSED, which is the opposite of what this
     * asserted.
     *
     * The gate was a denylist: it refused the names the server computed and let
     * everything else through, and its own note said so -- "it does not
     * restrict what a site may send". So it was open for exactly the inputs
     * nobody had thought about. admitRequestParams() admits two things: a name
     * some event declares and a client may set, and a custom value under one of
     * the four prefixes.
     *
     * A site's own values are as free as they ever were. They just have to say
     * which bag they are in, which the tracker already does.
     */
    public function testAnUnregisteredPropertyIsRefused(): void
    {
        $this->assertSame( array(), Helpers::admitRequestParams( array(
            'nobody_declared_this' => 'x',
            'cv1_name'             => 'forged',
        ) ), 'the gate is an allowlist; an undeclared name has no way in' );

        $this->assertSame(
            array( 'ep_plan' => 'pro', 'cv1' => 'k|v' ),
            Helpers::admitRequestParams( array( 'ep_plan' => 'pro', 'cv1' => 'k|v' ) ),
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

        foreach ( array( 'browser_type', 'country', 'ip_address', 'timestamp' ) as $name ) {

            $this->assertArrayHasKey( $name, $serverOwned );
        }

        /*
         * full_host is gone with the v1 handlers that read it -- the reverse-DNS
         * name reached no raw column and no cube pass. host, which does, stays.
         */
        foreach ( array( 'country', 'city', 'state', 'host' ) as $name ) {

            $this->assertArrayHasKey(
                $name, $serverOwned,
                "'$name' must be server-owned. Location comes from the visitor's IP and host "
                . 'from a reverse-DNS lookup of it; neither may be supplied by the request '
                . 'whose location is being determined.' );
        }
    }

    public function testLogPhpUsesTheFilter(): void
    {
        /*
         * Read from the file because log.php is an endpoint, not a class: it
         * runs on a real request and cannot be exercised here. Without this,
         * the filter could be removed from the one place that needs it while
         * every test above still passed.
         */
        $source = file_get_contents( OWA_DIR . 'log.php' );

        /*
         * admitRequestParams(), not rejectServerOwnedParams(): the gate is an
         * allowlist now. It subsumes the old refusal -- a server-computed
         * property is not client-settable, so it is not admitted -- and closes
         * the half the denylist left open, which was every name nobody had
         * registered.
         */
        $this->assertStringContainsString( 'admitRequestParams', $source );

        $this->assertStringNotContainsString(
            '$event->setProperties($service->request->getAllOwaParams());', $source,
            'log.php is copying every request parameter onto the event again.' );
    }
}
