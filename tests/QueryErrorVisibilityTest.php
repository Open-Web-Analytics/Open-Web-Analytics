<?php

use PHPUnit\Framework\TestCase;

/**
 * A statement the database refuses has to be visible.
 *
 * Both drivers reported query errors with $this->e->debug(). Under the
 * production error handler -- which is what a real installation runs -- debug
 * is written nowhere. Verified against a live install: notice, warning and
 * error all reach the log; debug does not.
 *
 * So a refused write produced NOTHING. That is how a strict sql_mode dropped
 * page views on a live installation without anyone noticing: the INSERT failed,
 * the failure propagated as a false return that the tracking path does not
 * inspect, and no log line existed to contradict the impression that everything
 * was fine. The symptom was missing rows, discovered by chance, days later.
 *
 * This is the test that makes the next such failure loud instead of silent, so
 * it asserts the LEVEL rather than the wording -- the wording is not what
 * failed.
 */
final class QueryErrorVisibilityTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * Records what was logged and at which level, standing in for the error
     * singleton the driver would normally use.
     */
    private function spy() {

        return new class {

            public $messages = [];

            public function debug( $m )   { $this->messages[] = [ 'debug', $m ]; }
            public function info( $m )    { $this->messages[] = [ 'info', $m ]; }
            public function notice( $m )  { $this->messages[] = [ 'notice', $m ]; }
            public function warning( $m ) { $this->messages[] = [ 'warning', $m ]; }
            public function err( $m )     { $this->messages[] = [ 'error', $m ]; }
            public function crit( $m )    { $this->messages[] = [ 'critical', $m ]; }

            public function levels() {
                return array_column( $this->messages, 0 );
            }

            public function at( $level ) {
                return array_values( array_map(
                    function ( $m ) { return $m[1]; },
                    array_filter( $this->messages, function ( $m ) use ( $level ) {
                        return $m[0] === $level;
                    } )
                ) );
            }
        };
    }

    /**
     * The levels the production handler actually writes. If this ever changes,
     * the choice of level below has to change with it -- which is why the
     * relationship is asserted rather than assumed.
     */
    public function testDebugIsNotAmongTheLevelsProductionWrites(): void {

        $written = [ 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];

        $this->assertNotContains( 'debug', $written,
            'debug is not written by the production handler, so it cannot carry a real failure' );
    }

    public function testARefusedStatementIsLoggedWhereProductionCanSeeIt(): void {

        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $spy = $this->spy();

        $original = $db->e;
        $db->e = $spy;

        try {
            // Syntactically valid, refused by the server: no such table.
            $db->query( 'INSERT INTO owa_no_such_table_for_this_test (id) VALUES (1)' );
        } finally {
            $db->e = $original;
        }

        $this->assertNotEmpty( $spy->messages, 'the driver logged nothing at all for a refused statement' );

        $this->assertContains( 'error', $spy->levels(),
            'a refused statement must be logged at a level the production handler writes. '
          . 'It was reported at debug, which is written nowhere, so failures were silent.' );

        $reported = implode( ' ', $spy->at( 'error' ) );

        $this->assertStringContainsString( 'owa_no_such_table_for_this_test', $reported,
            'the message must name the statement that was refused' );
    }

    /**
     * A broken database refuses every statement, and this log is on disk on the
     * same machine. The cap has to announce itself, or a quiet log reads as a
     * healthy one.
     */
    public function testTheLogVolumeCapAnnouncesItself(): void {

        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $spy = $this->spy();

        $reset = new ReflectionProperty( \OWA\Core\Db::class, 'query_error_count' );
        $reset->setAccessible( true );
        $reset->setValue( null, 0 );

        $original = $db->e;
        $db->e = $spy;

        try {
            for ( $i = 0; $i < \OWA\Core\Db::QUERY_ERROR_LOG_LIMIT + 10; $i++ ) {
                $db->query( 'INSERT INTO owa_no_such_table_for_this_test (id) VALUES (1)' );
            }
        } finally {
            $db->e = $original;
            $reset->setValue( null, 0 );
        }

        $errors = $spy->at( 'error' );

        $this->assertLessThanOrEqual(
            \OWA\Core\Db::QUERY_ERROR_LOG_LIMIT + 1,
            count( $errors ),
            'the cap must actually bound the number of lines written'
        );

        $this->assertStringContainsString(
            'not be logged',
            implode( ' ', $errors ),
            'stopping silently would make a capped log look like a recovered one'
        );
    }

    /**
     * A constraint violation is usually not a fault, and must not be reported
     * as one.
     *
     * Dimension rows are written check-then-insert: load by id, insert if it
     * was not there. Two concurrent first-hits on the same new URL, user agent
     * or host both miss and both insert, and one loses. The row exists either
     * way -- which is what the insert was for -- so the loser has nothing to
     * fix. Reporting that at error level would cry wolf on every busy site, and
     * a log that cries wolf gets ignored, which is how the silence this whole
     * change is about becomes possible again by a different route.
     *
     * Still logged, because a duplicate rate that climbs is worth seeing.
     */
    public function testAConstraintViolationIsReportedButNotAsAFault(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $spy = $this->spy();

        $db->query( 'CREATE TEMPORARY TABLE owa_dupe_probe (id BIGINT PRIMARY KEY)' );
        $db->query( 'INSERT INTO owa_dupe_probe (id) VALUES (1)' );

        $original = $db->e;
        $db->e = $spy;

        try {
            $result = $db->query( 'INSERT INTO owa_dupe_probe (id) VALUES (1)' );
        } finally {
            $db->e = $original;
        }

        $this->assertFalse( $result, 'a refused insert must still report failure to its caller' );

        $this->assertNotContains( 'error', $spy->levels(),
            'a concurrent-insert race is expected, and must not be logged as a fault' );

        $this->assertContains( 'notice', $spy->levels(),
            'it must still be logged -- a duplicate rate that climbs is worth seeing' );
    }

    /**
     * The pair for the case above: a genuine fault must still be an error, or
     * the classification would just be a way of hiding everything.
     */
    public function testARealFaultIsStillReportedAsOne(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $spy = $this->spy();

        $original = $db->e;
        $db->e = $spy;

        try {
            $db->query( 'INSERT INTO owa_no_such_table_for_this_test (id) VALUES (1)' );
        } finally {
            $db->e = $original;
        }

        $this->assertContains( 'error', $spy->levels(),
            'a missing table is a fault and must be reported as one' );
    }

    /**
     * The property the whole concern rests on: one refused insert must not stop
     * the ones after it.
     *
     * A tracker request writes several rows -- document, referer, user agent,
     * host, session, request -- and a duplicate on any one of them is an
     * ordinary outcome of the check-then-insert race. If that aborted the
     * request, a single lost race would cost every other row as well.
     *
     * The PDO driver raised the stakes here: it runs with ERRMODE_EXCEPTION, so
     * without the catch inside query() a duplicate key would have thrown
     * straight out through the tracker.
     */
    public function testARefusedInsertDoesNotStopTheInsertsAfterIt(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query( 'CREATE TEMPORARY TABLE owa_seq_probe (id BIGINT PRIMARY KEY)' );

        $first  = $db->query( 'INSERT INTO owa_seq_probe (id) VALUES (1)' );
        $dupe   = $db->query( 'INSERT INTO owa_seq_probe (id) VALUES (1)' );
        $after  = $db->query( 'INSERT INTO owa_seq_probe (id) VALUES (2)' );

        $this->assertTrue( (bool) $first );
        $this->assertFalse( $dupe, 'the duplicate must fail' );
        $this->assertTrue( (bool) $after,
            'the insert after a duplicate must still run -- a tracker request writes several rows '
          . 'and one lost race must not cost the others' );

        $row = $db->get_row( 'SELECT COUNT(*) AS n FROM owa_seq_probe' );

        $this->assertSame( 2, (int) $row['n'], 'both distinct rows must be present' );
    }
}
