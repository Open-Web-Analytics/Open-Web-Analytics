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

        'cv1' => 'Custom variable slot, deleted before the property pass.',
        'cv2' => 'Custom variable slot, deleted before the property pass.',
        'cv3' => 'Custom variable slot, deleted before the property pass.',
        'cv4' => 'Custom variable slot, deleted before the property pass.',
        'cv5' => 'Custom variable slot, deleted before the property pass.',
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

            /* cv4 and cv5 are real slots the fixture happens not to exercise. */
            if ( strpos( $name, 'cv' ) === 0 ) {

                continue;
            }

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
}
