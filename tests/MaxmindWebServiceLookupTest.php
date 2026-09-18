<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Maxmind web service lookup, which could not run at all.
 */
final class MaxmindWebServiceLookupTest extends TestCase
{
    /**
     * The lookup declines instead of fatalling when the client class is absent.
     *
     * Setting the module's lookup_method to 'geoip_city_isp_org_web_service'
     * used to reach `new \Client(...)` with the import commented out, naming a
     * root-namespace class that has never existed. That is an uncaught Error on
     * every tracked request that reaches a geo lookup, and the setting has no UI
     * so nobody found it.
     *
     * geoip2/geoip2 is not a dependency of this module, so class_exists() is
     * false here -- which is the same state as any installation that set the
     * option without installing the package, and is therefore the case worth
     * asserting. The map must come back untouched.
     *
     * Asserted by CALLING it. An earlier version of this test scanned the source
     * for the offending string, which cannot work: the docblock above the fixed
     * method quotes `new \Client(...)` while explaining the bug, so a pattern
     * correct enough to catch the code also matches the documentation.
     */
    public function testTheLookupDeclinesRatherThanFatallingWithoutTheClient(): void
    {
        if ( class_exists( '\GeoIp2\WebService\Client' ) ) {
            $this->markTestSkipped( 'geoip2/geoip2 is installed, so the decline path is not reachable' );
        }

        $maxmind = new \OWA\Module\MaxmindGeoip\Classes\Maxmind;

        $map = array( 'ip_address' => '203.0.113.7', 'city' => '(not set)' );

        $this->assertSame( $map, $maxmind->getLocationFromWebService( $map ),
            'a lookup that cannot happen must hand the map back, not throw' );
    }

    /** No ip_address is declined the same way, and before anything else. */
    public function testALookupWithNoIpAddressIsDeclined(): void
    {
        $maxmind = new \OWA\Module\MaxmindGeoip\Classes\Maxmind;

        $map = array( 'city' => '(not set)' );

        $this->assertSame( $map, $maxmind->getLocationFromWebService( $map ) );
    }

}
