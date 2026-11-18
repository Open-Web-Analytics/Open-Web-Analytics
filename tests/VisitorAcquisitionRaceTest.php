<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * Two beacons of a new visitor's first session, ingested at once, both try to
 * create the visitor's acquisition row. The loser's insert hits the unique key.
 *
 * Ingest runs in a transaction (EventRawHandlers::store()), and under
 * REPEATABLE READ a row another connection committed after this transaction's
 * first read is invisible to its reads while still blocking its insert. So the
 * re-read that is meant to recognise the race can miss the row. The beacon is
 * then rolled back and retried, which heals it; what matters here is that this
 * is logged as the expected race and not as a fault.
 *
 * Reproduced for real: a second connection commits the row between this
 * transaction's first read and the handler's insert.
 */
final class VisitorAcquisitionRaceTest extends TestCase
{
    use CapturesLog;

    /** @var int */
    private $vid;

    /** @var \OWA\Core\Db|null */
    private $other;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $this->vid = 7770000000000000000 + random_int( 1, 999999 );
        $this->other = \OWA\Core\CoreAPI::dbFactory();
    }

    protected function tearDown(): void
    {
        if ( $this->other ) {
            $this->other->query( sprintf( 'DELETE FROM owa_visitor_acquisition WHERE visitor_id = %d', $this->vid ) );
        }
    }

    public function testALostInsertRaceIsLoggedAsANoticeNotAnError(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $event = new \OWA\Module\Base\Classes\Event();
        $event->set( 'num_prior_sessions', '0' );

        $row = [
            'visitor_id'          => $this->vid,
            'site_id'             => 'acquisition-race-site',
            'ts'                  => 1794900000,
            'yyyymmdd'            => 20261117,
            'tagged_source'       => null,
            'tagged_medium'       => null,
            'tagged_campaign'     => null,
            'tagged_ad'           => null,
            'tagged_search_terms' => null,
            'referer_url'         => null,
            'referer_host'        => null,
        ];

        $handlers = ( new ReflectionClass( \OWA\Module\Base\Handler\EventRawHandlers::class ) )->newInstanceWithoutConstructor();
        $write = new ReflectionMethod( $handlers, 'writeVisitorAcquisition' );

        $db->beginTransaction();

        try {
            // This transaction's first read fixes what its reads can see.
            $db->query( sprintf( 'SELECT visitor_id FROM owa_visitor_acquisition WHERE visitor_id = %d', $this->vid ) );

            // The other beacon's worker writes the row and commits.
            $this->assertNotFalse( $this->other->query( sprintf(
                "INSERT INTO owa_visitor_acquisition (visitor_id, site_id, acq_ts, last_seen) VALUES (%d, 'acquisition-race-site', 1794899999, 202611)",
                $this->vid ) ) );

            $result = null;
            $log = $this->logDuring( function () use ( $write, $handlers, $event, $row, &$result ) {
                $result = $write->invoke( $handlers, $event, $row, true );
            } );

        } finally {
            $db->rollbackTransaction();
        }

        $this->assertFalse( $result, 'the beacon is not written in this transaction; the retry writes it' );
        $this->assertStringContainsString( 'a concurrent beacon wrote the visitor acquisition row first', $log );
        $this->assertStringNotContainsString( 'writing the visitor acquisition row failed', $log );
    }
}
