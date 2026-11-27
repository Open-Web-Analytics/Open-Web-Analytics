<?php

use OWA\Module\Base\Classes\Geolocation;
use PHPUnit\Framework\TestCase;

/**
 * One process looks up many addresses (a queue drain ingests many events): each
 * address gets its own location, and an address is looked up once.
 */
final class GeolocationPerAddressTest extends TestCase
{
    /** Lookups the test filter answered, by address. */
    private static $calls = [];

    private static $attached = false;

    private $was;

    protected function setUp(): void
    {

        $this->was = \OWA\Core\CoreAPI::getSetting( 'base', 'geolocation_lookup' );
        \OWA\Core\CoreAPI::setSetting( 'base', 'geolocation_lookup', true );

        self::$calls = [];

        if ( ! self::$attached ) {

            // Answers only this test's addresses (198.51.100.0/24); every other lookup passes through unchanged.
            \OWA\Core\CoreAPI::getEventDispatch()->attachFilter( 'geolocation', static function ( $geo ) {

                $ip = (string) ( $geo['ip_address'] ?? '' );

                if ( strpos( $ip, '198.51.100.' ) !== 0 ) {

                    return $geo;
                }

                self::$calls[ $ip ] = ( self::$calls[ $ip ] ?? 0 ) + 1;
                $geo['city'] = 'City ' . substr( $ip, strlen( '198.51.100.' ) );

                return $geo;
            } );

            self::$attached = true;
        }
    }

    protected function tearDown(): void
    {

        \OWA\Core\CoreAPI::setSetting( 'base', 'geolocation_lookup', $this->was );
    }

    public function testEachAddressGetsItsOwnLocation(): void
    {

        $geo = new Geolocation();

        $geo->getGeolocationFromIp( '198.51.100.1' );
        $this->assertSame( 'City 1', $geo->getCity() );
        $this->assertSame( '198.51.100.1', $geo->getProperty( 'ip_address' ) );

        $geo->getGeolocationFromIp( '198.51.100.2' );
        $this->assertSame( 'City 2', $geo->getCity() );
        $this->assertSame( '198.51.100.2', $geo->getProperty( 'ip_address' ) );

        $geo->getGeolocationFromIp( '198.51.100.1' );
        $this->assertSame( 'City 1', $geo->getCity() );
    }

    public function testAnAddressIsLookedUpOnce(): void
    {

        $geo = new Geolocation();

        foreach ( [ '198.51.100.7', '198.51.100.7', '198.51.100.8', '198.51.100.7' ] as $ip ) {

            $geo->getGeolocationFromIp( $ip );
        }

        $this->assertSame( [ '198.51.100.7' => 1, '198.51.100.8' => 1 ], self::$calls );
    }

    public function testRefreshLooksTheAddressUpAgain(): void
    {

        $geo = new Geolocation();

        $geo->getGeolocationFromIp( '198.51.100.9' );
        $geo->getGeolocationFromIp( '198.51.100.9', true );

        $this->assertSame( [ '198.51.100.9' => 2 ], self::$calls );
    }

    public function testTheCacheIsBounded(): void
    {

        $geo = new Geolocation();
        $max = Geolocation::MAX_CACHED_ADDRESSES;

        // 198.51.100.0 is the oldest, and is dropped once one more address than the limit is looked up.
        for ( $i = 0; $i <= $max; $i++ ) {

            $geo->getGeolocationFromIp( sprintf( '198.51.100.%d', $i % 256 ) . ( $i >= 256 ? '-' . $i : '' ) );
        }

        $geo->getGeolocationFromIp( '198.51.100.0' );

        $this->assertSame( 2, self::$calls['198.51.100.0'] );
        $this->assertSame( 1, self::$calls[ sprintf( '198.51.100.%d-%d', $max % 256, $max ) ] );
    }
}
