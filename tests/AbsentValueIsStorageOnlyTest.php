<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * NO PROPERTY DECLARES A STORAGE SENTINEL.
 *
 * "(not set)" or "(unknown)" as a `default_value` does nothing on the v2 path:
 * setTrackerProperties() skips "(not set)" by name, applyStorageDefault() opts
 * out of any NULLABLE column and every v2 column is one, and the row builder's
 * text() maps "(unknown)" back to NULL. The reporting layer produces both labels
 * at render time: ResultSetManager::NOT_SET_LABEL for a row that carried nothing
 * and UNKNOWN_LABEL for a build that could not resolve one.
 *
 * A default that is a REAL value still belongs on the event.
 */
final class AbsentValueIsStorageOnlyTest extends TestCase
{
    private const SENTINELS = [ '(not set)', '(unknown)' ];

    public function testNoPropertyDeclaresAStorageSentinel(): void
    {
        $declared = [];

        foreach ( Helpers::allProperties() as $name => $definition ) {

            if ( ! array_key_exists( 'default_value', $definition ) ) {

                continue;
            }

            if ( in_array( $definition['default_value'], self::SENTINELS, true ) ) {

                $declared[] = $name . ' => ' . $definition['default_value'];
            }
        }

        $this->assertSame( [], $declared,
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

            ( new Helpers() )->setTrackerProperties( $event, [ $name => $definition ] );

            $this->assertFalse( $event->get( $name ),
                "$name should carry its own false default" );
        }

        $this->assertSame( 2, $checked,
            'Exactly two properties declare a default now -- the two new-visit flags. '
            . 'A change to that set should be deliberate.' );
    }
}
