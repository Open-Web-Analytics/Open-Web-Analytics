<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The boolean flags must not be able to write NULL.
 *
 * A boolean column that also holds NULL has three values, and each groups
 * separately: a report counting a flag = 0 silently misses every NULL row --
 * a callback that falls off the end returns null.
 *
 * Two independent guards stop it:
 *
 *   - required + default_value false: a falsy default, applied rather than
 *     skipped by a truthy test.
 *   - data_type boolean, which re-resolves a null AFTER the callback runs.
 *
 * These tests pin both, and show the second one is not decoration: with the
 * default removed it is the only thing standing between a null derivation and
 * the column.
 */
final class BooleanPropertyTypeTest extends TestCase
{
    /** The three flags whose callback can fall off the end returning null. */
    /*
     * The session and visitor markers: they arrive FROM the wire, where a
     * boolean's three states actually bite.
     */
    private const FLAGS = [ 'is_new_session_start', 'is_new_visitor_created' ];

    private function runPipeline( array $definition )
    {
        $helpers = new Helpers();
        $event   = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        $helpers->setTrackerProperties( $event, [ 'probe' => $definition ] );

        return $event->get( 'probe' );
    }

    /** A callback with a branch that returns nothing, like isBrowser's. */
    public static function fallsOffTheEnd( $value, $event )
    {
        if ( $event->get( 'never_set' ) ) {

            return true;
        }
    }

    public function testEveryBooleanFlagDeclaresItsType(): void
    {
        /*
         * Every group, not just the derived one: the two flags are CLIENT
         * properties, and what is checked is the declaration of a boolean,
         * wherever it is declared.
         */
        $server = array_merge(
            Helpers::requestProperties(),
            Helpers::clientProperties(),
            Helpers::serverProperties() );

        foreach ( self::FLAGS as $flag ) {

            $this->assertArrayHasKey( $flag, $server );

            $this->assertSame(
                'boolean', $server[ $flag ]['data_type'] ?? null,
                "$flag is a two-state fact and must say so." );
        }
    }

    /**
     * The flags are all required with a false default, which is the guard that
     * actually closed the 2015 hole. Removing either is what this test exists
     * to catch.
     */
    public function testEveryBooleanFlagIsRequiredWithAFalseDefault(): void
    {
        /*
         * Every group, not just the derived one: the two flags are CLIENT
         * properties, and what is checked is the declaration of a boolean,
         * wherever it is declared.
         */
        $server = array_merge(
            Helpers::requestProperties(),
            Helpers::clientProperties(),
            Helpers::serverProperties() );

        foreach ( self::FLAGS as $flag ) {

            $this->assertTrue(
                $server[ $flag ]['required'] ?? false,
                "$flag must be required, or an absent value is simply not written." );

            $this->assertArrayHasKey(
                'default_value', $server[ $flag ],
                "$flag needs a default for a null derivation to fall back to." );

            $this->assertFalse(
                $server[ $flag ]['default_value'],
                "$flag defaults to false; anything else makes a missing derivation look true." );
        }
    }

    public function testANullDerivationBecomesFalseNotNull(): void
    {
        $value = $this->runPipeline( [
            'required'      => true,
            'data_type'     => 'boolean',
            'default_value' => false,
            'callbacks'     => [ [ self::class, 'fallsOffTheEnd' ] ],
        ] );

        $this->assertFalse( $value );
        $this->assertNotNull(
            $value, 'null is a third value for a two-state fact and groups on its own.' );
    }

    /**
     * The declared type is a SECOND guard, not the working one: with required
     * and the default in place the null is already false without it. Stated so
     * nobody reads the type as the thing that fixed is_repeat_visitor.
     */
    public function testTheDefaultAloneAlreadyHandlesTheNull(): void
    {
        $value = $this->runPipeline( [
            'required'      => true,
            'default_value' => false,
            'callbacks'     => [ [ self::class, 'fallsOffTheEnd' ] ],
        ] );

        $this->assertFalse( $value );
    }

    /**
     * ...and it is not decoration either. Take the default away and the type is
     * the only thing left between a null derivation and a boolean column.
     */
    public function testWithNoDefaultTheDeclaredTypeIsWhatStopsTheNull(): void
    {
        $typed = $this->runPipeline( [
            'required'  => true,
            'data_type' => 'boolean',
            'callbacks' => [ [ self::class, 'fallsOffTheEnd' ] ],
        ] );

        $untyped = $this->runPipeline( [
            'required'  => true,
            'callbacks' => [ [ self::class, 'fallsOffTheEnd' ] ],
        ] );

        $this->assertFalse( $typed, 'the declared type should have resolved the null' );
        $this->assertNull( $untyped, 'without either guard a null reaches the column' );
    }

    /**
     * A beacon's values arrive as strings: "false", "no", "off", "0" and ""
     * are false, in any case; any other string is true.
     */
    public function testABooleanReadsFalseSpellingsAsFalse(): void
    {
        foreach ( [ '0', 'false', 'FALSE', 'False', 'off', 'Off', 'no', 'NO', '', ' false ' ] as $string ) {

            $this->assertFalse( Helpers::setDataType( $string, 'boolean' ), var_export( $string, true ) . ' read as true' );
        }

        foreach ( [ '1', 'true', 'TRUE', 'yes', 'on', 'anything' ] as $string ) {

            $this->assertTrue( Helpers::setDataType( $string, 'boolean' ), var_export( $string, true ) . ' read as false' );
        }

        $this->assertFalse( Helpers::setDataType( null, 'boolean' ) );
        $this->assertFalse( Helpers::setDataType( 0, 'boolean' ) );
        $this->assertTrue( Helpers::setDataType( 1, 'boolean' ) );
        $this->assertTrue( Helpers::setDataType( true, 'boolean' ) );
    }
}
