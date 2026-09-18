<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Maxmind web service lookup, which could not run at all.
 */
final class MaxmindWebServiceLookupTest extends TestCase
{
    /**
     * The lookup no longer names a class that does not exist.
     *
     * Setting the module's lookup_method to 'geoip_city_isp_org_web_service'
     * used to reach `new \Client(...)` with the import commented out, naming a
     * root-namespace class that has never existed. That is an uncaught Error on
     * every tracked request that reaches a geo lookup, and the setting has no UI
     * so nobody found it. geoip2/geoip2 is not a dependency of this module, so
     * the lookup has to decline rather than fatal.
     */
    public function testTheWebServiceLookupCannotFatalOnAMissingClass(): void
    {
        $src = file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/Classes/Maxmind.php' );

        $this->assertDoesNotMatchRegularExpression(
            '/new\s+\\Client\s*\(/', $src,
            'the web service lookup instantiates a root-namespace \Client again' );

        // It must check the class is there before reaching for it, and hand the
        // location map back rather than throwing.
        $this->assertStringContainsString( 'class_exists(', $src );
        $this->assertStringContainsString( 'GeoIp2\\WebService\\Client', $src );
    }

}
