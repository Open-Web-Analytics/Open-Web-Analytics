<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Maxmind web service lookup, which could not run at all.
 */
final class MaxmindWebServiceLookupTest extends TestCase
{
    /**
     * The lookup declines instead of fatalling when the client class is absent:
     * the map comes back untouched.
     */
    public function testTheLookupDeclinesRatherThanFatallingWithoutTheClient(): void
    {
        $maxmind = new MaxmindWebServiceLookupTestNoClient;

        $map = [ 'ip_address' => '203.0.113.7', 'city' => '(not set)' ];

        $this->assertSame( $map, $maxmind->getLocationFromWebService( $map ),
            'a lookup that cannot happen must hand the map back, not throw' );
    }

    /**
     * The module's own client is the default and is there on every install.
     * It named geoip2/geoip2's client, which the module does not require, so
     * every web service lookup found no location.
     */
    public function testTheDefaultClientIsTheModulesOwn(): void
    {
        $property = new \ReflectionProperty( \OWA\Module\MaxmindGeoip\Classes\Maxmind::class, 'ws_client_class' );

        $class = $property->getValue( new \OWA\Module\MaxmindGeoip\Classes\Maxmind );

        $this->assertSame( \OWA\Module\MaxmindGeoip\Classes\WebServiceClient::class, $class );
        $this->assertTrue( class_exists( $class ) );
    }

    /** The client asks the City endpoint for the address, with the account id and key as basic auth. */
    public function testTheClientAsksTheCityEndpoint(): void
    {
        $history = [];
        $stack   = \GuzzleHttp\HandlerStack::create( new \GuzzleHttp\Handler\MockHandler( [
            new \GuzzleHttp\Psr7\Response( 200, [], (string) json_encode( MaxmindWebServiceLookupTestClient::RECORD ) ),
        ] ) );
        $stack->push( \GuzzleHttp\Middleware::history( $history ) );

        $client = new \OWA\Module\MaxmindGeoip\Classes\WebServiceClient( '42', 'secret', $stack );

        $this->assertSame( MaxmindWebServiceLookupTestClient::RECORD, $client->city( ' 203.0.113.7 ' ) );

        $request = $history[0]['request'];
        $this->assertSame( 'https://geoip.maxmind.com/geoip/v2.1/city/203.0.113.7', (string) $request->getUri() );
        $this->assertSame( 'Basic ' . base64_encode( '42:secret' ), $request->getHeaderLine( 'Authorization' ) );
    }

    /** A refused key is an exception the lookup catches: no location, the map untouched. */
    public function testARefusedKeyLeavesTheMapUntouched(): void
    {
        MaxmindWebServiceLookupTestMockedClient::$answer = new \GuzzleHttp\Psr7\Response( 401, [], '{"code":"AUTHORIZATION_INVALID"}' );

        $map = [ 'ip_address' => '203.0.113.7', 'city' => '(not set)' ];

        $this->assertSame( $map, ( new MaxmindWebServiceLookupTestMocked )->getLocationFromWebService( $map ) );
    }

    /** Through the module's own client, an answer is mapped onto the location. */
    public function testTheModulesClientAnswerIsMapped(): void
    {
        MaxmindWebServiceLookupTestMockedClient::$answer = new \GuzzleHttp\Psr7\Response( 200, [],
            (string) json_encode( MaxmindWebServiceLookupTestClient::RECORD ) );

        $map = ( new MaxmindWebServiceLookupTestMocked )->getLocationFromWebService( [ 'ip_address' => '203.0.113.7' ] );

        $this->assertSame( 'münchen', $map['city'] );
        $this->assertSame( 'DE', $map['country_code'] );
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

/** Names a client class that does not exist. */
class MaxmindWebServiceLookupTestNoClient extends \OWA\Module\MaxmindGeoip\Classes\Maxmind
{
    protected $ws_client_class = '\\Nowhere\\WebServiceClient';
}

/** The module's client, answering from a mock handler. */
class MaxmindWebServiceLookupTestMockedClient extends \OWA\Module\MaxmindGeoip\Classes\WebServiceClient
{
    /** @var \Psr\Http\Message\ResponseInterface */
    public static $answer;

    public function __construct( $account_id, $license_key )
    {
        parent::__construct( $account_id, $license_key, new \GuzzleHttp\Handler\MockHandler( [ self::$answer ] ) );
    }
}

class MaxmindWebServiceLookupTestMocked extends \OWA\Module\MaxmindGeoip\Classes\Maxmind
{
    protected $ws_client_class = MaxmindWebServiceLookupTestMockedClient::class;
}

/** Answers as GeoIp2\WebService\Client does: a model whose jsonSerialize() is the record. */
class MaxmindWebServiceLookupTestClient
{
    const RECORD = [
        'city'         => [ 'names' => [ 'en' => 'München' ] ],
        'country'      => [ 'iso_code' => 'DE', 'names' => [ 'en' => 'Germany' ] ],
        'subdivisions' => [ [ 'iso_code' => 'BY', 'names' => [ 'en' => 'Bavaria' ] ] ],
        'location'     => [ 'latitude' => 48.1, 'longitude' => 11.6 ],
    ];

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
