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
        $kept = Helpers::rejectServerOwnedParams( array(
            'is_browser' => 'ludhiana',
            'day'        => 'united states',
        ) );

        $this->assertArrayNotHasKey(
            'is_browser', $kept,
            'A request could set is_browser, which is how a city name reached a boolean column.' );

        $this->assertArrayNotHasKey( 'day', $kept );
    }

    public function testTheObservedRequestCannotBeForged(): void
    {
        /*
         * The more serious half. A forged ip_address defeats IP exclusion and
         * sends geolocation somewhere else; a forged timestamp reorders events;
         * a forged is_robot decides whether the sender gets filtered at all.
         */
        $kept = Helpers::rejectServerOwnedParams( array(
            'ip_address' => '1.2.3.4',
            'timestamp'  => '999',
            'is_robot'   => '0',
        ) );

        $this->assertSame( array(), $kept );
    }

    public function testDeclaredPropertiesAreStillAccepted(): void
    {
        /*
         * Membership is declared, not inferred from which map a property lives
         * in. LocationHandlers skips the IP lookup when an event already carries
         * country and city, and server-side callers supply their own host --
         * all three are registered as derived, so inferring would have broken
         * them.
         */
        $kept = Helpers::rejectServerOwnedParams( array(
            'country' => 'India',
            'city'    => 'Ludhiana',
            'host'    => 'example.com',
        ) );

        $this->assertSame(
            array( 'country' => 'India', 'city' => 'Ludhiana', 'host' => 'example.com' ),
            $kept );
    }

    public function testUnregisteredPropertiesPassThrough(): void
    {
        /*
         * This refuses to let a request OVERWRITE a derivation; it does not
         * restrict what a site may send. Custom variables and event parameters
         * are unaffected, and so is anything a module has not registered.
         */
        $kept = Helpers::rejectServerOwnedParams( array(
            'site_id'      => 'abc',
            'cv1_name'     => 'plan',
            'anything_new' => 'value',
        ) );

        $this->assertCount( 3, $kept );
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

        foreach ( array( 'is_browser', 'day', 'ip_address', 'timestamp' ) as $name ) {

            $this->assertArrayHasKey( $name, $serverOwned );
        }

        foreach ( array( 'country', 'city', 'host' ) as $name ) {

            $this->assertArrayNotHasKey(
                $name, $serverOwned,
                "'$name' declares itself client-settable, so it must not be treated as "
                . 'server-owned by either enforcement point.' );
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

        $this->assertStringContainsString( 'rejectServerOwnedParams', $source );

        $this->assertStringNotContainsString(
            '$event->setProperties($service->request->getAllOwaParams());', $source,
            'log.php is copying every request parameter onto the event again.' );
    }
}
