<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A property must be set before anything that reads it.
 *
 * setTrackerProperties() walks the registered properties in INSERTION ORDER,
 * and a callback may read properties already on the event. So the order the
 * definitions appear in is part of the contract -- reorder two entries and a
 * derivation silently reads a value that has not been computed yet, producing a
 * wrong answer rather than an error.
 *
 * That was recorded by three "// must come after session_referer" comments.
 * Measured, it is far larger than three: 32 of 66 callbacks read another
 * property, giving 34 ordering constraints, of which those comments covered
 * three. The rest were holding by luck and convention.
 *
 * The graph is derived from the callback bodies rather than hand-listed, so a
 * new dependency is covered the moment it is written and cannot be forgotten
 * here.
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

    /** @return array{order: array, scope: array, deps: array} */
    private function registration(): array
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $reads   = $this->callbackReads();

        $order = array();
        $scope = array();
        $deps  = array();

        foreach ( self::SCOPES as $name ) {

            foreach ( (array) $service->getMap( 'tracking_properties_' . $name ) as $property => $definition ) {

                $order[ $property ] = count( $order );
                $scope[ $property ] = $name;

                foreach ( (array) ( $definition['callbacks'] ?? array() ) as $callback ) {

                    $short = substr( $callback, strrpos( $callback, ':' ) + 1 );

                    if ( isset( $reads[ $short ] ) ) {

                        $deps[ $property ] = array_unique(
                            array_merge( $deps[ $property ] ?? array(), $reads[ $short ] ) );
                    }
                }
            }
        }

        return array( 'order' => $order, 'scope' => $scope, 'deps' => $deps );
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

    public function testTheDocumentedConstraintStillHolds(): void
    {
        /*
         * Named explicitly as well as caught by the graph above, because these
         * three are the ones a comment warns about and the ones most likely to
         * be moved by someone tidying the list alphabetically -- which is
         * exactly what an earlier attempt at a generated config did.
         */
        $order = $this->registration()['order'];

        foreach ( array( 'source', 'medium', 'search_terms' ) as $dependant ) {

            $this->assertGreaterThan(
                $order['session_referer'], $order[ $dependant ],
                "$dependant derives from session_referer and must be registered after it." );
        }
    }
}
