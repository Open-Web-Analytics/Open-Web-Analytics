<?php

use PHPUnit\Framework\TestCase;

/**
 * Constructing an object must not require a working settings system.
 *
 * Settings::__construct loads the config file, creates the base.configuration
 * ENTITY, and only afterwards applies the config constants as settings. So an
 * entity gets built while the settings object that will hold its values is
 * still half-constructed -- and Base::__construct used to ask for that settings
 * object, via configSingleton(), whose static is assigned only AFTER the
 * constructor it is calling returns.
 *
 * Nothing detected that. It stayed upright because Settings, Entity and Module
 * were each kept outside the Base hierarchy, which is a real constraint that
 * was written down nowhere: giving entities a $this->c "like every other class"
 * would have re-entered configSingleton() with its static still unset, building
 * a second Settings, a second entity, and so on until the stack ran out -- on
 * every request, not in some corner case.
 *
 * Base now reads those properties on first access instead, so construction
 * touches nothing. These tests pin both halves: that construction really is
 * inert, and that every reader still gets what it got before.
 */
final class BaseLazyConfigTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function subject() {

        return new class extends \OWA\Core\Base {};
    }

    /**
     * The actual fix. Uninitialised, not merely null -- an eager constructor
     * that assigned null would pass a null check and fail this.
     */
    public function testConstructionTouchesNeitherSettingsNorTheErrorLogger() {

        $obj = $this->subject();

        foreach ( [ 'e', 'c', 'config' ] as $property ) {

            $ref = new ReflectionProperty( \OWA\Core\Base::class, $property );

            $this->assertFalse(
                $ref->isInitialized( $obj ),
                sprintf(
                    '$%s was populated during construction. Building any object then requires '
                  . 'settings, which cannot be built without building objects.',
                    $property
                )
            );
        }
    }

    public function testEachPropertyIsBuiltOnFirstRead() {

        $obj = $this->subject();

        $this->assertSame(
            \OWA\Core\CoreAPI::configSingleton(),
            $obj->c,
            'reading $c must yield the settings singleton'
        );

        $this->assertSame(
            \OWA\Core\CoreAPI::errorSingleton(),
            $obj->e,
            'reading $e must yield the error singleton'
        );

        $this->assertIsArray( $obj->config, 'reading $config must yield the base settings array' );
        $this->assertSame(
            \OWA\Core\CoreAPI::configSingleton()->fetch( 'base' ),
            $obj->config,
            'the snapshot must match what the settings object holds'
        );
    }

    /**
     * Once read, the property is a real property again -- so the cost is paid
     * once per object and __get is not on the hot path.
     */
    public function testTheValueIsRetainedAfterTheFirstRead() {

        $obj = $this->subject();
        $first = $obj->c;

        $ref = new ReflectionProperty( \OWA\Core\Base::class, 'c' );

        $this->assertTrue( $ref->isInitialized( $obj ), 'the first read must materialise the property' );
        $this->assertSame( $first, $obj->c, 'a second read must return the same object' );
    }

    /**
     * isset() has to agree with __get. A lazy property with no __isset reads as
     * missing, and isset($this->config['key']) asks about the property before
     * it ever asks about the offset -- so a real caller silently changes answer.
     */
    public function testIssetAgreesWithLazyReads() {

        $obj = $this->subject();

        $this->assertTrue( isset( $obj->c ), 'isset must not report a lazy property as missing' );
        $this->assertTrue( isset( $obj->config ), 'isset must not report a lazy snapshot as missing' );

        // The shape used in modules/Base/View/Updates.php.
        $obj->config = [ 'is_embedded' => true ];
        $this->assertTrue(
            isset( $obj->config['is_embedded'] ),
            'isset() on an offset of a lazy property must still see the offset'
        );

        $this->assertFalse( isset( $obj->no_such_property ), 'unrelated properties stay unset' );
    }

    /**
     * __get must not turn every typo into a silent null.
     */
    public function testAnUnknownPropertyStillWarns() {

        $obj = $this->subject();
        $seen = null;

        set_error_handler( function ( $errno, $errstr ) use ( &$seen ) {
            $seen = $errstr;
            return true;
        } );

        $value = $obj->definitely_not_a_property;

        restore_error_handler();

        $this->assertNull( $value );
        $this->assertNotNull( $seen, 'an undefined property must still raise a warning' );
        $this->assertStringContainsString( 'definitely_not_a_property', (string) $seen );
    }

    /**
     * The constraint that used to hold the boot together on its own. It is no
     * longer load-bearing, but these classes sit on the path that builds
     * settings, so a future move into the hierarchy deserves to be a decision
     * rather than an accident -- and this test is where that decision surfaces.
     */
    public function testTheClassesOnTheSettingsPathStayOutsideTheHierarchy() {

        $roots = [
            \OWA\Core\Entity::class                     => 'entities are created by Settings::__construct',
            \OWA\Module\Base\Classes\Settings::class    => 'Settings is what configSingleton() builds',
            \OWA\Core\Module::class                     => 'modules are loaded while settings are assembled',
        ];

        foreach ( $roots as $class => $why ) {

            $this->assertFalse(
                is_subclass_of( $class, \OWA\Core\Base::class ),
                sprintf(
                    '%s now extends Base -- %s, so this is the re-entrancy that lazy reads make '
                  . 'survivable rather than safe. Check that construction is still inert before '
                  . 'accepting it.',
                    $class,
                    $why
                )
            );
        }
    }
}
