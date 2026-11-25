<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Ingest;

/**
 * Ingest has a pre and a post filter point at every stage.
 *
 * WHY THIS MATTERS ENOUGH TO TEST. A layer may need to act in a stage other
 * than its own: the beacon compat layer renames names an older tracker sends in
 * the PROPERTY stage, and the gate that must admit those names first is in the
 * REQUEST stage. A point that stops being wired fails no other test, because
 * no other test sends an old name.
 *
 * These cases hold the two things that can rot silently: that a point is still
 * WIRED at the site it names, and that the pairing means what it says.
 */
final class IngestHookPointsTest extends TestCase
{
    public function testEveryStageHasItsPoints(): void
    {
        $points = Ingest::points();

        $this->assertCount( 7, $points,
            'request, edge and property have a pre and a post; store has one' );

        foreach ( [ 'request', 'edge', 'property' ] as $stage ) {

            foreach ( [ 'pre', 'post' ] as $half ) {

                $this->assertContains( 'ingest.' . $stage . '.' . $half, $points,
                    $stage . ' is missing its ' . $half . ' point' );
            }
        }

        $this->assertSame( 'tracking_events_pre_save', end( $points ),
            'the store point is last: it is where the events are saved' );
    }

    /**
     * log.php runs the request stage's points, then logEvent() the edge
     * stage's first: run it with a listener on every point that records its
     * name. A constant nothing calls would enumerate cleanly and never run.
     *
     * The beacon's site id holds spaces, which no site id may, so it stops at
     * the site check without a lookup.
     * IngestPointsReachedTest follows one beacon through the rest.
     */
    public function testLogPhpRunsTheRequestPointsInOrder(): void
    {
        $dir = sys_get_temp_dir() . '/owa_points_' . getmypid() . '_' . bin2hex( random_bytes( 4 ) );
        $this->assertTrue( mkdir( $dir, 0700, true ) );

        $record  = $dir . '/heard.txt';
        $prepend = $dir . '/prepend.php';

        // Listeners by name: EventDispatch::filter() logs the name of each.
        file_put_contents( $prepend, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [ 'owa_e_t' => 'page_view', 'owa_page_url' => 'https://www.example.com/a',
    'owa_site_id' => 'no such site' ];
// Called once the response has ended, before OWA boots, and before log.php loads the
// autoloader (after the install gate): PHP's own needs none, this stand-in does.
function fastcgi_finish_request() {
    require_once dirname( $_SERVER['SCRIPT_FILENAME'] ) . '/owa_env.php';
    foreach ( \OWA\Module\Base\Classes\Ingest::points() as $i => $point ) {
        eval( "function owa_test_point_$i( \$value ) {
            file_put_contents( getenv( 'OWA_POINTS_RECORD' ), '$point' . \"\\n\", FILE_APPEND );
            return \$value;
        }" );
        \OWA\Core\CoreAPI::registerFilter( $point, "owa_test_point_$i" );
    }
    return true;
}
PHP
        );

        try {
            exec( 'OWA_POINTS_RECORD=' . escapeshellarg( $record ) . ' ' . escapeshellarg( PHP_BINARY )
                . ' -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend )
                . ' ' . escapeshellarg( \OWA\Core\Env::root() . '/log.php' ) . ' > /dev/null 2>&1' );

            $heard = file_exists( $record ) ? file( $record, FILE_IGNORE_NEW_LINES ) : [];
        } finally {
            @unlink( $record );
            @unlink( $prepend );
            @rmdir( $dir );
        }

        $this->assertSame( [ Ingest::REQUEST_PRE, Ingest::REQUEST_POST, Ingest::EDGE_PRE ], $heard );
    }

    /**
     * No real point is left with a listener attached.
     *
     * There is no detach API, so anything this file attaches survives for the
     * rest of the process and reaches every other test that ingests an event.
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
        $value = [ 'untouched' => true ];

        foreach ( Ingest::points() as $point ) {

            $this->assertSame( $value, Ingest::at( $point, $value ),
                $point . ' altered a value with nothing attached to it' );
        }
    }

    /**
     * The value is chained; the context is not.
     *
     * ON A PROBE POINT, not a real one. There is no API to detach a filter, so
     * a listener attached to tracking_events_pre_save stays attached for the rest of
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
        \OWA\Core\CoreAPI::registerFilter( 'ingest.test.probe.context', 'owa_test_ingest_appends' );

        $row = Ingest::at( 'ingest.test.probe.context', [ 'a' => 1 ], 'the-context' );

        $this->assertSame( [ 'a' => 1, 'saw_context' => 'the-context' ], $row,
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
        \OWA\Core\CoreAPI::registerFilter( 'ingest.test.probe.chain', 'owa_test_ingest_first', 5 );
        \OWA\Core\CoreAPI::registerFilter( 'ingest.test.probe.chain', 'owa_test_ingest_second', 10 );

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
