<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A click's element anchor is kept only in range and with both of its points.
 *
 * The heatmap draws a click by where on its element it landed: the element as a
 * 53-bit hash (element_anchor) and the point as thousandths of its box
 * (anchor_x, anchor_y). The columns are a BIGINT and two SMALLINTs, so a value
 * off the wire that does not fit would have strict mode refuse the whole click.
 * A hash without its point is one the heatmap cannot place, so it goes too.
 */
final class ElementAnchorPropertyTest extends TestCase
{
    const PARTS = array( 'element_anchor', 'anchor_x', 'anchor_y' );

    /** The registry's order, and each one's callback: the hash reads both points. */
    const ORDER = array( 'anchor_x' => 'anchorX', 'anchor_y' => 'anchorY', 'element_anchor' => 'elementAnchor' );

    private static function click( array $properties )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->setEventType( 'dom.click' );
        $event->setProperties( $properties );

        return $event;
    }

    /**
     * What the three callbacks leave on the event, run in the registry's order
     * and written back the way setTrackerProperties() writes them: a null is
     * not written, so a value the beacon sent stays unless the callback took it
     * off the event.
     */
    private static function resolve( $event ): array
    {
        foreach ( self::ORDER as $name => $callback ) {
            $value = call_user_func( array( Helpers::class, $callback ), $event->get( $name ), $event );

            if ( $value !== null ) {
                $event->set( $name, $value );
            }
        }

        $out = array();

        foreach ( self::PARTS as $name ) {
            $out[ $name ] = $event->get( $name ) === false ? null : $event->get( $name );
        }

        return $out;
    }

    public function testEachOfTheThreeIsAClickColumnWithItsCallback(): void
    {
        $columns = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();

        foreach ( self::PARTS as $name ) {
            $this->assertContains( $name, $columns );
            $this->assertSame( $name, Helpers::columnFor( $name ) );
        }

        $registry = json_decode( file_get_contents( OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

        foreach ( self::ORDER as $name => $callback ) {
            $this->assertSame( array( Helpers::class . '::' . $callback ), $registry[ $name ]['callbacks'], $name );
            $this->assertSame( array( 'click' ), $registry[ $name ]['events'], $name );
        }

        $keys = array_keys( $registry );
        $this->assertGreaterThan( array_search( 'anchor_y', $keys ), array_search( 'element_anchor', $keys ),
            'element_anchor reads both points, so it comes after them' );
    }

    public function testAWholeAnchorInRangeIsKept(): void
    {
        $event = self::click( array( 'element_anchor' => '9007199254740991', 'anchor_x' => '0', 'anchor_y' => '1000' ) );

        $this->assertSame(
            array( 'element_anchor' => 9007199254740991, 'anchor_x' => 0, 'anchor_y' => 1000 ),
            self::resolve( $event ) );
    }

    /**
     * @dataProvider brokenAnchors
     */
    public function testAHashOutOfRangeOrWithoutItsPointIsDropped( array $properties, array $left ): void
    {
        $this->assertSame( $left, self::resolve( self::click( $properties ) ) );
    }

    public static function brokenAnchors(): array
    {
        $none = array( 'element_anchor' => null, 'anchor_x' => null, 'anchor_y' => null );

        return array(
            'x past the box'    => array( array( 'element_anchor' => '12345', 'anchor_x' => '99999', 'anchor_y' => '5' ),
                                          array( 'element_anchor' => null, 'anchor_x' => null, 'anchor_y' => 5 ) ),
            'y below it'        => array( array( 'element_anchor' => '12345', 'anchor_x' => '5', 'anchor_y' => '-1' ),
                                          array( 'element_anchor' => null, 'anchor_x' => 5, 'anchor_y' => null ) ),
            'hash past 53 bits' => array( array( 'element_anchor' => '9007199254740992', 'anchor_x' => '5', 'anchor_y' => '5' ),
                                          array( 'element_anchor' => null, 'anchor_x' => 5, 'anchor_y' => 5 ) ),
            'not a number'      => array( array( 'element_anchor' => '#main>a:1', 'anchor_x' => '5', 'anchor_y' => '5' ),
                                          array( 'element_anchor' => null, 'anchor_x' => 5, 'anchor_y' => 5 ) ),
            'a point missing'   => array( array( 'element_anchor' => '12345', 'anchor_x' => '5' ),
                                          array( 'element_anchor' => null, 'anchor_x' => 5, 'anchor_y' => null ) ),
            'none of it'        => array( array(), $none ),
        );
    }
}
