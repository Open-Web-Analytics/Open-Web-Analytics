<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa::singleton() is a static accessor: every call answers the instance the
 * first call made, and the first call's config is the one applied.
 */
final class OwaSingletonTest extends TestCase
{
    /** @var mixed */
    private $requestType;

    protected function setUp(): void
    {
        $this->requestType = \OWA\Core\CoreAPI::getSetting( 'base', 'request_type' );

        self::forget();
    }

    /** Each test's first call is the first: the instance singleton() holds is dropped. */
    private static function forget(): void
    {
        if ( property_exists( owa::class, 'instance' ) ) {
            ( new ReflectionProperty( owa::class, 'instance' ) )->setValue( null, null );
        }
    }

    protected function tearDown(): void
    {
        self::forget();

        \OWA\Core\CoreAPI::setSetting( 'base', 'request_type', $this->requestType );
    }

    public function testItIsCalledOnTheClass(): void
    {
        $this->assertTrue( ( new ReflectionMethod( owa::class, 'singleton' ) )->isStatic() );

        $this->assertInstanceOf( owa::class, owa::singleton( [ 'request_type' => 'rest' ] ) );
    }

    public function testEveryCallAnswersTheFirstCallsInstance(): void
    {
        $first = owa::singleton( [ 'request_type' => 'rest' ] );

        $this->assertSame( $first, owa::singleton() );
        $this->assertSame( $first, owa::singleton( [ 'request_type' => 'cli' ] ) );

        // Called on an instance, as the method allowed before it was static.
        $this->assertSame( $first, $GLOBALS['owa_test_instance']->singleton() );
        $this->assertNotSame( $GLOBALS['owa_test_instance'], $first );
    }

    public function testTheFirstCallsConfigIsTheOneApplied(): void
    {
        owa::singleton( [ 'request_type' => 'rest' ] );

        $this->assertSame( 'rest', \OWA\Core\CoreAPI::getSetting( 'base', 'request_type' ) );

        owa::singleton( [ 'request_type' => 'cli' ] );

        $this->assertSame( 'rest', \OWA\Core\CoreAPI::getSetting( 'base', 'request_type' ),
            'a later call re-booted with its own config' );
    }
}
