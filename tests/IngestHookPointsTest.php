<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Ingest;

/**
 * Ingest has a pre and a post filter point at every stage.
 *
 * WHY THIS MATTERS ENOUGH TO TEST. Ingest was four procedural stages with no
 * seams, and the beacon compat layer is what proved that insufficient: it
 * renames names an older tracker sends, apply() runs in the PROPERTY stage, and
 * the gate that must admit those names first is in the REQUEST stage. So the
 * layer needed to act in a stage it could not reach, and the moment that gate
 * became an allowlist three of its four bridges went unreachable -- with no test
 * failing, because no test sent an old name.
 *
 * These cases hold the two things that can rot silently: that a point is still
 * WIRED at the site it names, and that the pairing means what it says.
 */
final class IngestHookPointsTest extends TestCase
{
    /** Where each point must appear, so a rename cannot orphan it. */
    private const SITES = array(
        Ingest::REQUEST_PRE   => 'log.php',
        Ingest::REQUEST_POST  => 'log.php',
        Ingest::EDGE_PRE      => 'Core/CoreAPI.php',
        Ingest::EDGE_POST     => 'Core/CoreAPI.php',
        Ingest::PROPERTY_PRE  => 'modules/Base/Controller/ProcessEvent.php',
        Ingest::PROPERTY_POST => 'modules/Base/Controller/ProcessEvent.php',
        Ingest::STORE_PRE     => 'modules/Base/Handler/EventRawHandlers.php',
        Ingest::STORE_POST    => 'modules/Base/Handler/EventRawHandlers.php',
    );

    public function testEveryStageHasBothPoints(): void
    {
        $points = Ingest::points();

        $this->assertCount( 8, $points, 'four stages, a pre and a post at each' );

        foreach ( array( 'request', 'edge', 'property', 'store' ) as $stage ) {

            foreach ( array( 'pre', 'post' ) as $half ) {

                $this->assertContains( 'ingest.' . $stage . '.' . $half, $points,
                    $stage . ' is missing its ' . $half . ' point' );
            }
        }
    }

    /**
     * Each point is invoked at the stage it names.
     *
     * Read from the FILE, because a constant that nothing calls is the failure
     * mode here: the points would enumerate cleanly, a listener would attach
     * without error, and nothing would ever run it.
     */
    public function testEveryPointIsWiredWhereItBelongs(): void
    {
        $unwired = array();

        foreach ( self::SITES as $point => $file ) {

            $source = (string) file_get_contents( OWA_DIR . $file );

            // The constant's NAME, not its value: the call sites use
            // Ingest::STORE_POST so they stay greppable.
            $constant = strtoupper( str_replace( array( 'ingest.', '.' ), array( '', '_' ), $point ) );

            if ( strpos( $source, 'Ingest::' . $constant ) === false ) {

                $unwired[] = $point . ' is not invoked in ' . $file;
            }
        }

        $this->assertSame( array(), $unwired, implode( "\n", $unwired ) );
    }

    /**
     * No real point is left with a listener attached.
     *
     * There is no detach API, so anything this file attaches survives for the
     * rest of the process and reaches every other test that ingests an event.
     * That is not hypothetical: it errored two cases in
     * UnknownSiteRejectionTest before the probe points were split out.
     */
    public function testThisFileLeavesNoListenerOnARealPoint(): void
    {
        $value = new stdClass();

        foreach ( Ingest::points() as $point ) {

            $this->assertSame( $value, Ingest::at( $point, $value ),
                $point . ' has a listener attached, which will reach every test '
              . 'in this process that ingests an event' );
        }
    }

    /** A point with no listeners returns its value untouched. */
    public function testAPointWithNoListenersIsTransparent(): void
    {
        $value = array( 'untouched' => true );

        foreach ( Ingest::points() as $point ) {

            $this->assertSame( $value, Ingest::at( $point, $value ),
                $point . ' altered a value with nothing attached to it' );
        }
    }

    /**
     * The value is chained; the context is not.
     *
     * ON A PROBE POINT, not a real one. There is no API to detach a filter, so
     * a listener attached to ingest.store.post stays attached for the rest of
     * the process -- and the first version of this case did exactly that, which
     * made UnknownSiteRejectionTest error out when logEvent() reached
     * ingest.edge.pre and handed an Event to a listener expecting a string.
     *
     * The mechanism is shared, so exercising it on a name ingest does not use
     * proves the same thing without leaving a trap behind. That a REAL point is
     * wired is the case above, and that one attaches nothing.
     */
    public function testTheValueIsChainedAndTheContextIsNot(): void
    {
        owa_coreAPI::registerFilter( 'ingest.test.probe.context', 'owa_test_ingest_appends' );

        $row = Ingest::at( 'ingest.test.probe.context', array( 'a' => 1 ), 'the-context' );

        $this->assertSame( array( 'a' => 1, 'saw_context' => 'the-context' ), $row,
            'the listener must receive the context and be able to change the value' );
    }

    /**
     * Two listeners chain, in priority order.
     *
     * A single listener cannot tell chaining from replacement: whatever it
     * returns is the answer either way.
     */
    public function testListenersChainInPriorityOrder(): void
    {
        owa_coreAPI::registerFilter( 'ingest.test.probe.chain', 'owa_test_ingest_first', 5 );
        owa_coreAPI::registerFilter( 'ingest.test.probe.chain', 'owa_test_ingest_second', 10 );

        $this->assertSame( 'start|first|second', Ingest::at( 'ingest.test.probe.chain', 'start' ),
            'the second listener must see the first one\'s output' );
    }
}

function owa_test_ingest_appends( $row, $context = null )
{
    $row['saw_context'] = $context;

    return $row;
}

function owa_test_ingest_first( $v )
{
    return $v . '|first';
}

function owa_test_ingest_second( $v )
{
    return $v . '|second';
}
