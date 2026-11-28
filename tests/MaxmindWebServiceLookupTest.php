<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Maxmind web service lookup, which could not run at all.
 */
final class MaxmindWebServiceLookupTest extends TestCase
{
    /** @var mixed the last failure as the test found it: a failed lookup keeps one */
    private $lastFailure;

    protected function setUp(): void
    {
        $this->lastFailure = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' );
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', $this->lastFailure );
    }

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

    /** What the error log is given while $fn runs. */
    private function logDuring( callable $fn ): string
    {
        $e = \OWA\Core\CoreAPI::errorSingleton();
        $p = new ReflectionProperty( $e, 'sinks' );
        $was = $p->getValue( $e );
        $stream = fopen( 'php://memory', 'w+' );
        $p->setValue( $e, [ [ 'path' => null, 'stream' => $stream ] ] );

        try {
            $fn();
        } finally {
            $p->setValue( $e, $was );
        }

        rewind( $stream );

        return (string) stream_get_contents( $stream );
    }

    /**
     * A rejected key is logged at notice and kept for System Health, once an
     * hour: every lookup fails alike until the key is fixed.
     */
    public function testAFailureIsLoggedAndKeptAtMostOnceAnHour(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' );
        \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', [] );

        $lookup = function () {
            MaxmindWebServiceLookupTestMockedClient::$answer = new \GuzzleHttp\Psr7\Response( 401, [], '{"code":"AUTHORIZATION_INVALID"}' );
            ( new MaxmindWebServiceLookupTestMocked )->getLocationFromWebService( [ 'ip_address' => '203.0.113.7' ] );
        };

        try {
            $first = $this->logDuring( $lookup );

            $this->assertStringContainsString( 'Maxmind web service lookup failed:', $first );
            $this->assertStringContainsString( 'No location is recorded while lookups fail', $first, 'at notice' );

            $kept = (array) \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' );
            $this->assertEqualsWithDelta( time(), (int) $kept['at'], 5 );
            $this->assertNotSame( '', (string) $kept['message'] );

            $this->assertStringNotContainsString( 'No location is recorded while lookups fail', $this->logDuring( $lookup ),
                'the second failure within the hour is not a notice' );
            $this->assertSame( $kept, \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' ) );

            $later = (int) $kept['at'] + \OWA\Module\MaxmindGeoip\Classes\Maxmind::FAILURE_NOTICE_INTERVAL;
            $this->assertTrue( \OWA\Module\MaxmindGeoip\Classes\Maxmind::recordWebServiceFailure( 'later', $later ), 'an hour on, it is' );
            $this->assertSame( [ 'at' => $later, 'message' => 'later' ], \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' ) );
        } finally {
            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', $was );
        }
    }

    /** System Health shows the last failure while lookups go to the web service: red within a day. */
    public function testSystemHealthShowsTheLastFailure(): void
    {
        $was = [ \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'lookup_method' ),
                 \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' ),
                 \OWA\Core\CoreAPI::getSetting( 'base', 'geolocation_lookup' ) ];

        try {
            $module = new \OWA\Module\MaxmindGeoip\Module();
            $now    = 1800000000;

            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'lookup_method', 'city_lite_db' );
            $this->assertSame( [], $module->systemHealthSection( [], $now ), 'the local database: no section' );

            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'lookup_method', 'geoip_city_isp_org_web_service' );
            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', [] );
            $none = $module->systemHealthSection( [], $now )['geoip'];
            $this->assertSame( 'green', $none['level'] );
            $this->assertSame( 'No lookup has failed.', $none['findings'][0]['detail'] );

            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', [ 'at' => $now - 3600, 'message' => 'AUTHORIZATION_INVALID' ] );
            $recent = $module->systemHealthSection( [], $now )['geoip'];
            $this->assertSame( 'red', $recent['level'] );
            $this->assertSame( 'Last failure', $recent['findings'][0]['label'] );
            $this->assertStringContainsString( 'AUTHORIZATION_INVALID', $recent['findings'][0]['detail'] );
            $this->assertStringContainsString( date( 'Y-m-d H:i', $now - 3600 ), $recent['findings'][0]['detail'] );

            $this->assertSame( 'green', $module->systemHealthSection( [], $now + 86400 )['geoip']['level'], 'a day on' );
        } finally {
            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'lookup_method', $was[0] );
            \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', $was[1] );
            \OWA\Core\CoreAPI::setSetting( 'base', 'geolocation_lookup', $was[2] );
        }
    }

    /**
     * The active module registers its geolocation filter, with no setting to
     * say so: filters are collected from active modules only.
     */
    public function testTheModuleRegistersItsGeolocationFilterWhateverIsStored(): void
    {
        $ed  = \OWA\Core\CoreAPI::getEventDispatch();
        $was = [ $ed->listenersByFilterType['geolocation'] ?? null, $ed->listenersByFilterType['system_health_sections'] ?? null,
                 \OWA\Core\CoreAPI::getSetting( 'base', 'geolocation_lookup' ) ];

        unset( $ed->listenersByFilterType['geolocation'], $ed->listenersByFilterType['system_health_sections'] );

        try {
            ( new \OWA\Module\MaxmindGeoip\Module() )->registerFilters();

            $this->assertTrue( (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'geolocation_lookup' ), 'lookups on' );
            $this->assertNotEmpty( $ed->listenersByFilterType['geolocation'] ?? [], 'the geolocation filter' );
            $this->assertNotEmpty( $ed->listenersByFilterType['system_health_sections'] ?? [], 'the System Health section' );
        } finally {
            foreach ( [ 'geolocation' => $was[0], 'system_health_sections' => $was[1] ] as $name => $listeners ) {
                if ( $listeners === null ) {
                    unset( $ed->listenersByFilterType[ $name ] );
                } else {
                    $ed->listenersByFilterType[ $name ] = $listeners;
                }
            }
            \OWA\Core\CoreAPI::setSetting( 'base', 'geolocation_lookup', $was[2] );
        }
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
