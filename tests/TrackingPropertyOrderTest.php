<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A property must be set before anything that reads it.
 *
 * setTrackerProperties() walks the registered properties in INSERTION ORDER,
 * and a callback may read properties already on the event.
 *
 * That only constrains the order for properties the pass itself PRODUCES --
 * ones with a callback or a default_value. A property that is merely carried
 * from the wire is already on the event before the pass starts, so nothing can
 * read it too early and its position is free. session_referer is the example:
 * three callbacks read it and three comments said "must come after
 * session_referer", but it has no callbacks and no default, so those three
 * entries can sit anywhere. The comments described a constraint that was never
 * there.
 *
 * Where the read value IS produced by the pass, the constraint is real and
 * silent: reorder two entries and the dependant derives from a value that has
 * not been computed yet, giving a wrong answer rather than an error. There are
 * 30 such pairs -- the date parts reading timestamp, the geo callbacks reading
 * ip_address, location_id reading city/country/state, host reading full_host.
 *
 * The graph is derived from the callback bodies rather than hand-listed, so a
 * dependency written tomorrow is covered without anyone remembering this file.
 */
final class TrackingPropertyOrderTest extends TestCase
{
    /** Scopes in the order the runtime applies them. */
    private const SCOPES = array( 'environmental', 'regular', 'derived' );

    /** @return array callback name => properties it reads off the event */
    private function callbackReads(): array
    {
        $source = file_get_contents( OWA_DIR . 'modules/Base/Classes/TrackingEventHelpers.php' );

        preg_match_all( '/static function (\w+)\s*\([^)]*\)\s*\{(.*?)\n    \}/s', $source, $matches,
            PREG_SET_ORDER );

        $reads = array();

        foreach ( $matches as $function ) {

            preg_match_all( "/\\\$event->get\(\s*'([a-zA-Z_0-9]+)'/", $function[2], $found );

            if ( $found[1] ) {

                $reads[ $function[1] ] = array_unique( $found[1] );
            }
        }

        return $reads;
    }

    /** @return array{order: array, scope: array, produced: array, deps: array} */
    private function registration(): array
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $reads   = $this->callbackReads();

        $order    = array();
        $scope    = array();
        $produced = array();
        $deps     = array();

        foreach ( self::SCOPES as $name ) {

            foreach ( (array) $service->getMap( 'tracking_properties_' . $name ) as $property => $definition ) {

                $callbacks = (array) ( $definition['callbacks'] ?? array() );

                $order[ $property ] = count( $order );
                $scope[ $property ] = $name;

                /* Does the pass change this value? If not, whatever the wire
                   sent is on the event before the pass starts and no reader
                   can be too early. */
                $produced[ $property ] =
                    $callbacks || array_key_exists( 'default_value', $definition );

                foreach ( $callbacks as $callback ) {

                    $short = substr( $callback, strrpos( $callback, ':' ) + 1 );

                    if ( isset( $reads[ $short ] ) ) {

                        $deps[ $property ] = array_unique(
                            array_merge( $deps[ $property ] ?? array(), $reads[ $short ] ) );
                    }
                }
            }
        }

        return array( 'order' => $order, 'scope' => $scope,
                      'produced' => $produced, 'deps' => $deps );
    }

    public function testNoPropertyIsReadBeforeItIsSet(): void
    {
        $r = $this->registration();

        $violations = array();
        $checked    = 0;

        foreach ( $r['deps'] as $property => $needs ) {

            foreach ( $needs as $need ) {

                /* Unregistered names are custom properties; nothing sets them
                   on a schedule, so there is no ordering to satisfy. */
                if ( ! isset( $r['order'][ $need ] ) ) {

                    continue;
                }

                /* Carried from the wire, not produced here -- see the note on
                   this class. Its position is free. */
                if ( ! $r['produced'][ $need ] ) {

                    continue;
                }

                $checked++;

                if ( $r['order'][ $need ] > $r['order'][ $property ] ) {

                    $violations[] = sprintf(
                        "%s (%s, position %d) reads %s (%s, position %d), which is set AFTER it",
                        $property, $r['scope'][ $property ], $r['order'][ $property ],
                        $need, $r['scope'][ $need ], $r['order'][ $need ] );
                }
            }
        }

        $this->assertGreaterThan(
            25, $checked,
            'Far fewer dependencies than expected were found, so this test is not looking at '
            . 'what it thinks it is -- most likely the callback parser stopped matching.' );

        $this->assertSame(
            array(), $violations,
            "A property is read before it is set:\n  " . implode( "\n  ", $violations ) );
    }
}
