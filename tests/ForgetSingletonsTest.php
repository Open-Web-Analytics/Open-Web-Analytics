<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\CoreAPI;

/**
 * CoreAPI::forgetSingletons() empties every per-process singleton and memo, so
 * the next call builds it again; without it, each accessor keeps answering
 * what it built first.
 *
 * Every static property of the classes below is saved before each test and
 * put back after it, so the rest of the suite keeps the booted instances.
 */
final class ForgetSingletonsTest extends TestCase
{
    /**
     * Each class's resettable static properties. Static tables that are not
     * memos (CoreAPI::$setting_scope_rank) are left out.
     */
    private const SLOTS = [
        \OWA\Core\CoreAPI::class => [
            'api', 'db', 'config', 'error', 'service', 'cache', 'request',
            'request_logged', 'event_queues', 'salts', 'warned_modules',
            'registered_sites', 'setting_row_cache', 'setting_scope_cache', 'setting_chain_cache',
        ],
        \OWA\Core\Auth::class                                  => [ 'instance' ],
        \OWA\Core\Module::class                                => [ 'settings_registry' ],
        \OWA\Core\Template::class                              => [ 'assets_built' ],
        \OWA\Module\Base\Classes\EventDispatch::class          => [ 'instance' ],
        \OWA\Module\Base\Classes\TrackingEventHelpers::class   => [
            'instance', 'property_config', 'property_descriptions', 'internal_properties', 'memo',
            'event_names', 'event_config', 'goal_trigger_names', 'landingTags', 'campaignKeys',
            'priorTouches',
        ],
        \OWA\Module\Base\Classes\GoalVocabulary::class         => [ 'registryDescriptions' ],
        \OWA\Module\Base\Classes\TrackerDefaults::class        => [ 'defaults' ],
        \OWA\Module\Base\Classes\Beacon\Compat::class          => [ 'renames', 'event_names', 'event_reshapes' ],
        \OWA\Module\Base\Classes\Cube\Dimensions::class        => [ 'capacity' ],
        \OWA\Module\Base\Classes\Cube\Cubes::class             => [ 'exists', 'property_of_site' ],
        \OWA\Module\Base\Entity\GoalEventDefinition::class     => [ 'property_of_site' ],
    ];

    /** @var array<string, array<string, mixed>> class => property => value before the test */
    private $saved = [];

    protected function setUp(): void
    {
        $this->saved = [];

        foreach ( array_keys( self::SLOTS ) as $class ) {

            foreach ( ( new ReflectionClass( $class ) )->getProperties( ReflectionProperty::IS_STATIC ) as $p ) {

                if ( $p->getDeclaringClass()->getName() === $class ) {

                    $this->saved[ $class ][ $p->getName() ] = $p->getValue();
                }
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->saved as $class => $values ) {

            foreach ( $values as $name => $value ) {

                ( new ReflectionProperty( $class, $name ) )->setValue( null, $value );
            }
        }
    }

    private static function set( string $class, string $name, $value ): void
    {
        ( new ReflectionProperty( $class, $name ) )->setValue( null, $value );
    }

    private static function get( string $class, string $name )
    {
        return ( new ReflectionProperty( $class, $name ) )->getValue();
    }

    /** Every slot holding something goes back to its declared default. */
    public function testEverySlotIsEmptied(): void
    {
        foreach ( self::SLOTS as $class => $names ) {

            foreach ( $names as $name ) {

                $default = ( new ReflectionProperty( $class, $name ) )->getDefaultValue();

                self::set( $class, $name, is_bool( $default ) ? ! $default : [ 'owatest-sentinel' => new stdClass() ] );
            }
        }

        CoreAPI::forgetSingletons();

        foreach ( self::SLOTS as $class => $names ) {

            foreach ( $names as $name ) {

                $this->assertSame( ( new ReflectionProperty( $class, $name ) )->getDefaultValue(),
                    self::get( $class, $name ), "$class::\$$name was not reset" );
            }
        }
    }

    /** @return array<string, array{0: callable, 1: class-string, 2?: bool}> */
    public static function singletons(): array
    {
        return [
            'api'       => [ [ CoreAPI::class, 'singleton' ], CoreAPI::class ],
            'config'    => [ [ CoreAPI::class, 'configSingleton' ], \OWA\Module\Base\Classes\Settings::class ],
            'error'     => [ [ CoreAPI::class, 'errorSingleton' ], \OWA\Module\Base\Classes\Error::class ],
            'service'   => [ [ CoreAPI::class, 'serviceSingleton' ], \OWA\Module\Base\Classes\Service::class ],
            'cache'     => [ [ CoreAPI::class, 'cacheSingleton' ], \OWA\Module\Base\Classes\Cache::class ],
            'request'   => [ [ CoreAPI::class, 'requestContainerSingleton' ], \OWA\Core\RequestContainer::class ],
            'auth'      => [ [ \OWA\Core\Auth::class, 'get_instance' ], \OWA\Core\Auth::class, true ],
            'dispatch'  => [ [ \OWA\Module\Base\Classes\EventDispatch::class, 'get_instance' ],
                             \OWA\Module\Base\Classes\EventDispatch::class ],
            'helpers'   => [ [ \OWA\Module\Base\Classes\TrackingEventHelpers::class, 'getInstance' ],
                             \OWA\Module\Base\Classes\TrackingEventHelpers::class ],
        ];
    }

    /**
     * The same instance on every call until the reset, a new one after it.
     *
     * @dataProvider singletons
     */
    public function testASingletonIsRebuiltOnlyAfterTheReset( callable $accessor, string $class, bool $needsBoot = false ): void
    {
        $first = $accessor();

        $this->assertInstanceOf( $class, $first );
        $this->assertSame( $first, $accessor(), 'a second call built another instance' );

        CoreAPI::forgetSingletons();

        if ( $needsBoot ) {
            // Auth reads the request off the service, which the reset left unbooted.
            CoreAPI::serviceSingleton()->initializeFramework();
        }

        $second = $accessor();

        $this->assertInstanceOf( $class, $second );
        $this->assertNotSame( $first, $second, 'the reset left the instance in place' );
        $this->assertSame( $second, $accessor() );
    }

    /** The database handle is built again too; asked only where there is a database. */
    public function testTheDatabaseHandleIsRebuiltOnlyAfterTheReset(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; dbFactory() connects.' );
        }

        $first = CoreAPI::dbSingleton();

        $this->assertSame( $first, CoreAPI::dbSingleton() );

        CoreAPI::forgetSingletons();

        $this->assertNotSame( $first, CoreAPI::dbSingleton() );
    }

    /**
     * A memo of a config file answers what it holds until the reset, and the
     * file after it.
     */
    public function testAConfigMemoIsKeptUntilTheReset(): void
    {
        $defaults = \OWA\Module\Base\Classes\TrackerDefaults::class;
        $compat   = \OWA\Module\Base\Classes\Beacon\Compat::class;
        $module   = \OWA\Core\Module::class;

        self::set( $defaults, 'defaults', [ 'owatest' => [ 1 ] ] );
        self::set( $compat, 'event_names', [ 'owatest.old' => 'owatest.new' ] );
        self::set( $module, 'settings_registry', [ 'fields' => [], 'declared' => [], 'activation' => [] ] );

        $this->assertSame( [ 'owatest' => [ 1 ] ], $defaults::all() );
        $this->assertSame( 'owatest.new', $compat::eventName( 'owatest.old' ) );
        $this->assertSame( [], $module::settingsRegistry()['fields'] );

        CoreAPI::forgetSingletons();

        $this->assertArrayHasKey( 'features', $defaults::all(), 'defaults.json was not read again' );
        $this->assertSame( 'owatest.old', $compat::eventName( 'owatest.old' ) );
        $this->assertArrayHasKey( 'base|ns', $module::settingsRegistry()['fields'],
            'the modules\' settings.php files were not read again' );
    }

    /** The salts are read from the constants again after the reset. */
    public function testTheSaltsAreReadAgainAfterTheReset(): void
    {
        self::set( CoreAPI::class, 'salts', [ 'AUTH_KEY' => 'owatest-key', 'AUTH_SALT' => 'owatest-salt' ] );

        $this->assertStringContainsString( 'owatest-key', CoreAPI::getSalt( 'auth' ) );

        CoreAPI::forgetSingletons();

        $this->assertStringNotContainsString( 'owatest-key', CoreAPI::getSalt( 'auth' ) );
    }

    /** What assetsAreBuilt() found is kept until the reset, then looked up again. */
    public function testTheAssetsCheckIsKeptUntilTheReset(): void
    {
        $built = is_dir( \OWA\Core\Env::root() . '/public/base/dist' );

        self::set( \OWA\Core\Template::class, 'assets_built', ! $built );

        $this->assertSame( ! $built, \OWA\Core\Template::assetsAreBuilt() );

        CoreAPI::forgetSingletons();

        $this->assertSame( $built, \OWA\Core\Template::assetsAreBuilt() );
    }
}
