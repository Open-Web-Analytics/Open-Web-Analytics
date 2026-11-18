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

        $map = [ 'ip_address' => '203.0.113.7', 'city' => '(not set)' ];

        $this->assertSame( $map, $maxmind->getLocationFromWebService( $map ),
            'a lookup that cannot happen must hand the map back, not throw' );
    }

    /** No ip_address is declined the same way, and before anything else. */
    public function testALookupWithNoIpAddressIsDeclined(): void
    {
        $maxmind = new \OWA\Module\MaxmindGeoip\Classes\Maxmind;

        $map = [ 'city' => '(not set)' ];

        $this->assertSame( $map, $maxmind->getLocationFromWebService( $map ) );
    }

    /** The web service answers with a model object; its record is mapped as the database's is. */
    public function testAWebServiceAnswerIsMappedOntoTheLocation(): void
    {
        $maxmind = new MaxmindWebServiceLookupTestMaxmind;

        $map = $maxmind->getLocationFromWebService( [ 'ip_address' => ' 203.0.113.7 ', 'city' => '(not set)' ] );

        $this->assertSame( '203.0.113.7', MaxmindWebServiceLookupTestClient::$asked );
        $this->assertSame( 'münchen', $map['city'] );
        $this->assertSame( 'DE', $map['country_code'] );
        $this->assertSame( 'germany', $map['country'] );
        $this->assertSame( 'bavaria', $map['state'] );
        $this->assertSame( '48.1', $map['latitude'] );
    }
}

/** Names the stand-in client below instead of the GeoIp2 one. */
class MaxmindWebServiceLookupTestMaxmind extends \OWA\Module\MaxmindGeoip\Classes\Maxmind
{
    protected $ws_client_class = MaxmindWebServiceLookupTestClient::class;
}

/** Answers as GeoIp2\WebService\Client does: a model whose jsonSerialize() is the record. */
class MaxmindWebServiceLookupTestClient
{
    /** @var string|null the address the last lookup asked for */
    public static $asked = null;

    public function __construct( $user_name, $license_key ) {}

    public function city( $ip ) {

        self::$asked = $ip;

        return new class implements \JsonSerializable {

            public function jsonSerialize(): array {

                return [
                    'city'         => [ 'names' => [ 'en' => 'München' ] ],
                    'country'      => [ 'iso_code' => 'DE', 'names' => [ 'en' => 'Germany' ] ],
                    'subdivisions' => [ [ 'iso_code' => 'BY', 'names' => [ 'en' => 'Bavaria' ] ] ],
                    'location'     => [ 'latitude' => 48.1, 'longitude' => 11.6 ],
                ];
            }
        };
    }
}
