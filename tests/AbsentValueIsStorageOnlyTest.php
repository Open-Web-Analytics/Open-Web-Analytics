<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * NO PROPERTY DECLARES A STORAGE SENTINEL, and this file is what keeps it that
 * way.
 *
 * "(not set)" and "(unknown)" were declared as `default_value` on twelve
 * properties, and on the v2 path they did nothing at all:
 *
 *   - "(not set)" was never applied to the event -- setTrackerProperties()
 *     skips it by name -- and never reached a raw column either, because
 *     applyStorageDefault() opts out of any NULLABLE column and every v2 column
 *     is one. Measured on the live table: zero rows carry it, in any column.
 *   - "(unknown)" WAS applied, to os, and the row builder's text() then mapped
 *     it straight back to NULL. A value invented at ingest so it could be
 *     removed before storage.
 *
 * Their only live consumer was v1's NOT NULL text columns, which nothing on this
 * branch writes -- and the reporting layer produces both labels at render time
 * anyway: ResultSetManager::NOT_SET_LABEL for a row that carried nothing and
 * UNKNOWN_LABEL for a build that could not resolve one. So the round trip was
 * ingest inventing a label, storage refusing it, and reporting re-creating it.
 *
 * What survives is the distinction the sentinels were confused with: a default
 * that is a REAL value still belongs on the event.
 */
final class AbsentValueIsStorageOnlyTest extends TestCase
{
    private const SENTINELS = array( '(not set)', '(unknown)' );

    public function testNoPropertyDeclaresAStorageSentinel(): void
    {
        $declared = array();

        foreach ( Helpers::allProperties() as $name => $definition ) {

            if ( ! array_key_exists( 'default_value', $definition ) ) {

                continue;
            }

            if ( in_array( $definition['default_value'], self::SENTINELS, true ) ) {

                $declared[] = $name . ' => ' . $definition['default_value'];
            }
        }

        $this->assertSame( array(), $declared,
            "These declare a label as their default. A label is what the reporting layer "
            . "renders for absence; on the event and in a v2 column, absence is absence:\n  "
            . implode( "\n  ", $declared ) );
    }

    /** The labels live in the reporting layer, which is where they are produced. */
    public function testTheLabelsBelongToReadTime(): void
    {
        $this->assertSame( '(not set)',
            \OWA\Module\Base\Classes\ResultSetManager::NOT_SET_LABEL );

        $this->assertSame( '(unknown)',
            \OWA\Module\Base\Classes\ResultSetManager::UNKNOWN_LABEL );
    }

    /**
     * A v1 text column no longer receives a label, and that is the deliberate
     * consequence rather than a broken substitution.
     *
     * Asserted because the two look identical from outside: "we removed the
     * convention" and "applyStorageDefault stopped working" both show up as a
     * NULL where a label used to be. The mechanism in Core\Entity is untouched --
     * it simply has nothing left to apply.
     */
    public function testAV1TextColumnNoLongerReceivesALabel(): void
    {
        $session = \OWA\Core\CoreAPI::entityFactory( 'base.session' );

        $session->setProperties( array( 'host' => null, 'user_name' => false ) );

        foreach ( array( 'host', 'user_name' ) as $column ) {

            $this->assertNotSame( Helpers::ABSENT_VALUE_LABEL, $session->get( $column ),
                "base.session.$column still receives the label, so something still declares it" );
        }
    }

    /**
     * A default that is a REAL value still applies on the event, and that is the
     * distinction the sentinels blurred.
     *
     * The two request-scoped flags default false -- which says something -- and
     * false is only applicable because the apply is guarded by array_key_exists
     * rather than by truthiness.
     */
    public function testARealDefaultStillAppliesOnTheEvent(): void
    {
        $definitions = Helpers::requestProperties();

        $checked = 0;

        foreach ( Helpers::allProperties() as $name => $definition ) {

            if ( ! array_key_exists( 'default_value', $definition ) ) {

                continue;
            }

            $checked++;

            $this->assertSame( 'boolean', $definition['data_type'],
                "$name declares a default; every remaining one is a boolean flag" );

            $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );

            ( new Helpers() )->setTrackerProperties( $event, array( $name => $definition ) );

            $this->assertFalse( $event->get( $name ),
                "$name should carry its own false default" );
        }

        $this->assertSame( 2, $checked,
            'Exactly two properties declare a default now -- the two new-visit flags. '
            . 'A change to that set should be deliberate.' );
    }
}
