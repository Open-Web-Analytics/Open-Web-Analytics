<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * The boolean flags must not be able to write NULL.
 *
 * A boolean column that also holds NULL has three values, and each groups
 * separately: a report counting a flag = 0 silently misses every NULL row.
 * is_repeat_visitor did exactly this from 2015 until 8d24fc65 -- its callback
 * fell off the end returning null, and nothing turned that into false.
 *
 * Two independent guards stop it now, and they are worth telling apart because
 * only one of them was ever the thing that broke:
 *
 *   - required + default_value false. This is what actually closed the hole,
 *     once a falsy default stopped being skipped by a truthy test.
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
     * is_entry_page and is_repeat_visitor were here. Both were v1 derivations
     * reading flags the tracker no longer sends -- and removing the flags
     * without them would have left each computing a wrong value rather than
     * none, which is the defect this whole file is about.
     */
    /*
     * is_browser and is_robot were here and are gone. Both were computed on
     * every beacon and read only by v1 handlers -- neither reached a raw column
     * or a cube pass -- so they went with the rest of the dead derivations.
     *
     * The two that remain are the session and visitor markers, and they are the
     * better subjects anyway: they arrive FROM the wire, where a boolean's
     * three states actually bite.
     */
    private const FLAGS = array( 'is_new_session_start', 'is_new_visitor_created' );

    private function runPipeline( array $definition )
    {
        $helpers = new Helpers();
        $event   = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        $helpers->setTrackerProperties( $event, array( 'probe' => $definition ) );

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
         * Every group, not just the derived one. The two flags that remain are
         * CLIENT properties -- they arrive from the wire -- and the derived
         * booleans that used to live here, is_browser and is_robot, are gone
         * with the v1 handlers that were their only readers. What is being
         * checked is the declaration of a boolean, wherever it is declared.
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
         * Every group, not just the derived one. The two flags that remain are
         * CLIENT properties -- they arrive from the wire -- and the derived
         * booleans that used to live here, is_browser and is_robot, are gone
         * with the v1 handlers that were their only readers. What is being
         * checked is the declaration of a boolean, wherever it is declared.
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
        $value = $this->runPipeline( array(
            'required'      => true,
            'data_type'     => 'boolean',
            'default_value' => false,
            'callbacks'     => array( array( self::class, 'fallsOffTheEnd' ) ),
        ) );

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
        $value = $this->runPipeline( array(
            'required'      => true,
            'default_value' => false,
            'callbacks'     => array( array( self::class, 'fallsOffTheEnd' ) ),
        ) );

        $this->assertFalse( $value );
    }

    /**
     * ...and it is not decoration either. Take the default away and the type is
     * the only thing left between a null derivation and a boolean column.
     */
    public function testWithNoDefaultTheDeclaredTypeIsWhatStopsTheNull(): void
    {
        $typed = $this->runPipeline( array(
            'required'  => true,
            'data_type' => 'boolean',
            'callbacks' => array( array( self::class, 'fallsOffTheEnd' ) ),
        ) );

        $untyped = $this->runPipeline( array(
            'required'  => true,
            'callbacks' => array( array( self::class, 'fallsOffTheEnd' ) ),
        ) );

        $this->assertFalse( $typed, 'the declared type should have resolved the null' );
        $this->assertNull( $untyped, 'without either guard a null reaches the column' );
    }
}
