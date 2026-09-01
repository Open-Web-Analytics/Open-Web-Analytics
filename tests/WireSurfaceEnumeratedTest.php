<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * Everything the tracker sends must be declared somewhere.
 *
 * An undeclared property is the pass-through: it reaches handlers with no
 * declared type, no default, and nothing the wire filter can have an opinion
 * about. That is how a request could set owa_is_browser=ludhiana, and how
 * campaign and ad rode the beacon for years without appearing in any map.
 *
 * The point of this test is that the enumeration cannot quietly fall behind
 * again. Add a property to the tracker and this fails until it is declared or
 * consciously added to the exceptions below with a reason.
 */
final class WireSurfaceEnumeratedTest extends TestCase
{
    /**
     * Names that ride the beacon but are deliberately NOT tracking properties.
     * Each needs a reason, not just an entry.
     */
    private const NOT_TRACKING_PROPERTIES = array(

        'event_type' =>
            'Structural, not a property. log.php reads it with getRequestParam '
            . 'and sets it through setEventType(); it routes the event rather '
            . 'than describing it, and never comes out of the property bag.',

    );

    /** @return array every name the tracker emits, across all event types */
    private function emitted(): array
    {
        $contracts = json_decode(
            (string) file_get_contents( OWA_DIR . 'tests/fixtures/beacon_contracts.json' ),
            true );

        $this->assertIsArray( $contracts, 'the beacon contract fixture is unreadable' );

        $names = array();

        foreach ( $contracts as $event_type => $fields ) {

            if ( $event_type === '_comment' ) {

                continue;
            }

            $names = array_merge( $names, $fields );
        }

        return array_values( array_unique( $names ) );
    }

    private function declared(): array
    {
        return array_merge(
            Helpers::requestProperties(),
            Helpers::clientProperties(),
            Helpers::serverProperties() );
    }

    public function testEveryPropertyOnTheWireIsDeclared(): void
    {
        $declared = $this->declared();

        $undeclared = array();

        foreach ( $this->emitted() as $name ) {

            if ( isset( $declared[ $name ] )
                 || array_key_exists( $name, self::NOT_TRACKING_PROPERTIES ) ) {

                continue;
            }

            $undeclared[] = $name;
        }

        $this->assertSame(
            array(), $undeclared,
            "The tracker sends these and nothing declares them:\n  "
            . implode( "\n  ", $undeclared )
            . "\n\nDeclare them in modules/Base/config/tracking_properties.json, or add "
            . 'them to NOT_TRACKING_PROPERTIES with a reason.' );
    }

    /**
     * The exceptions are the part that rots: it is easy to silence a failure by
     * adding a name here, and easy for one to outlive the tracker that sent it.
     */
    public function testEveryExceptionIsStillOnTheWireAndStillUndeclared(): void
    {
        $emitted  = $this->emitted();
        $declared = $this->declared();

        foreach ( self::NOT_TRACKING_PROPERTIES as $name => $reason ) {

            $this->assertNotEmpty( $reason, "$name is excepted without a reason." );

            $this->assertContains(
                $name, $emitted,
                "$name is excepted but the tracker no longer sends it -- drop the exception." );

            $this->assertArrayNotHasKey(
                $name, $declared,
                "$name is declared now, so it should not also be an exception." );
        }
    }

    /**
     * A floor, so the test cannot pass by reading an empty fixture or an empty
     * config and finding nothing to complain about.
     */
    public function testBothSidesAreActuallyPopulated(): void
    {
        $this->assertGreaterThan( 40, count( $this->emitted() ),
            'Far fewer beacon fields than expected -- the fixture is not being read.' );

        $this->assertGreaterThan( 100, count( $this->declared() ),
            'Far fewer declared properties than expected -- the config is not being read.' );
    }
    /**
     * The top-up, for slots the config does not declare.
     *
     * The pairs are in the config now, but how MANY of them there are is the
     * maxCustomVars setting rather than a constant -- FactTable builds its cv
     * columns from the same setting -- so an install that raises it would have
     * columns with no property definition. This covers those, and must not
     * overwrite what the config already says.
     */
    public function testTheGeneratedCustomVariablePropertiesKeepTheirShape(): void
    {
        $helpers = new Helpers();
        $max     = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );

        $this->assertGreaterThan( 0, $max, 'maxCustomVars is what bounds the loop.' );

        $generated = $helpers->addCustomVariableProperties( array() );

        $this->assertCount(
            $max * 2, $generated,
            'Each slot needs a name and a value property.' );

        /* The config is authoritative: a declared slot is left exactly alone. */
        $declared = array( 'cv1_name' => array( 'required' => 'untouched' ) );

        $this->assertSame(
            array( 'required' => 'untouched' ),
            $helpers->addCustomVariableProperties( $declared )['cv1_name'],
            'The top-up overwrote a definition the config had already made.' );

        for ( $slot = 1; $slot <= $max; $slot++ ) {

            foreach ( array( 'name', 'value' ) as $half ) {

                $property = $generated[ "cv{$slot}_{$half}" ] ?? null;

                $this->assertIsArray( $property, "cv{$slot}_{$half} is not generated." );

                $this->assertTrue( $property['required'] );
                $this->assertSame( 'string', $property['data_type'] );
                $this->assertSame( '(not set)', $property['default_value'],
                    'An unset slot must read as (not set), not as empty.' );
                $this->assertContains(
                    'owa_trackingEventHelpers::lowercaseString', $property['callbacks'],
                    "cv{$slot}_{$half} is lowercased so the same variable does not "
                    . 'split into two dimensions by case.' );
            }
        }
    }

    /** The slot itself must not survive the split, or it would ride on as junk. */
    public function testTheRawSlotIsConsumedBySplitting(): void
    {
        $helpers = new Helpers();
        $event   = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

        $event->setProperties( array( 'cv1' => 'Color=Blue Widget' ) );

        $helpers->translateCustomVariables( $event );

        $this->assertSame( 'Color', $event->get( 'cv1_name' ) );
        $this->assertSame( 'Blue Widget', $event->get( 'cv1_value' ) );
        $this->assertFalse( $event->get( 'cv1' ), 'the raw slot should be gone' );
    }
    /**
     * A custom variable is a claim the server unpacks, not two values a request
     * may assert.
     *
     * cv{n}_name and cv{n}_value are produced by splitting the cv{n} slot the
     * tracker sent, so they are server scope. While they were merged into the
     * regular map instead, a request could post owa_cv1_name directly: it
     * survived the wire filter, and ProcessEvent's sanitized-properties step
     * then re-applied it OVER the value the split had just produced -- the same
     * shape as the is_browser defect.
     */
    public function testTheSplitHalvesCannotBeSetFromTheWire(): void
    {
        $kept = Helpers::rejectServerOwnedParams( array(
            'cv1'       => 'Color=Blue',
            'cv1_name'  => 'forged',
            'cv1_value' => 'forged',
            'cv5_name'  => 'forged',
        ) );

        $this->assertSame(
            array( 'cv1' => 'Color=Blue' ), $kept,
            'A request set a custom variable half directly, bypassing the split.' );
    }

    public function testTheSlotItselfRemainsSettable(): void
    {
        /* The filter must not overreach: the slot IS what the tracker sends. */
        $slots = array( 'cv1' => 'a=1', 'cv2' => 'b=2', 'cv3' => 'c=3',
                        'cv4' => 'd=4', 'cv5' => 'e=5' );

        $this->assertSame(
            $slots, Helpers::rejectServerOwnedParams( $slots ),
            'The filter rejected the slots the tracker actually sends.' );
    }
}
