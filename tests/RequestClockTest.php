<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The request clock has two readings and they move together.
 *
 * $timestamp is seconds and $timestamp_usec is the same instant in microseconds.
 * The constructor takes both from ONE microtime() call, so they cannot straddle a
 * second boundary and disagree.
 *
 * WHAT WENT WRONG. Callers moved the clock by assigning ->timestamp, which is the
 * seconds field, and v2 reads the MICROSECOND one: `ts` is
 * edgeTimestampMicroseconds(), yyyymmdd is derived from ts, and the event id is a
 * hash over ts. So backdating moved a field the stored row does not read, and the
 * row was stamped with the real now -- no error, no warning, and the date parts
 * agreeing with each other so nothing downstream looked wrong.
 *
 * The reporting e2e seeder did exactly that, in six places, to spread a fixture
 * across four days "so the report has real shape: multiple rows in the pages grid
 * AND a non-flat timeseries". Measured before the fix: every row landed on today,
 * which is the single point that spread exists to avoid.
 *
 * So this asserts the SEAM, not the setter: that moving the clock reaches the
 * value an event is actually stamped with.
 */
final class RequestClockTest extends TestCase
{
    private $saved;

    protected function setUp(): void
    {
        $rc = \OWA\Core\CoreAPI::requestContainerSingleton();

        // The container is a singleton for the process, so a test that moves its
        // clock and leaves it moved dates every later test's rows.
        $this->saved = $rc->getTimestamp();
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::requestContainerSingleton()->setTimestamp( $this->saved );
    }

    public function testBothReadingsMoveTogether(): void
    {
        $rc   = \OWA\Core\CoreAPI::requestContainerSingleton();
        $when = strtotime( '2026-09-01 12:00:00' );

        $rc->setTimestamp( $when );

        $this->assertSame( $when, $rc->getTimestamp() );
        $this->assertSame( $when * 1000000, $rc->getTimestampMicroseconds(),
            'the microsecond reading is the same instant, not the real now' );
    }

    /**
     * And the event clock follows, which is the part that was broken.
     *
     * edgeTimestampMicroseconds() is the callback behind `ts`. Asserting through
     * it rather than on the container field is deliberate: the container could
     * hold a consistent pair and still be read from somewhere else.
     */
    public function testTheEventClockFollows(): void
    {
        $when = strtotime( '2026-09-01 12:00:00' );

        \OWA\Core\CoreAPI::requestContainerSingleton()->setTimestamp( $when );

        $this->assertSame( $when * 1000000,
            \OWA\Module\Base\Classes\TrackingEventHelpers::edgeTimestampMicroseconds() );
    }

    /**
     * And so do the date parts a report groups by.
     *
     * yyyymmdd is what every period constraint reads. This is the assertion that
     * fails on the old code: it answered today's date for a clock set to a day in
     * the past.
     */
    public function testTheDatePartsFollow(): void
    {
        $when = strtotime( '2026-09-01 12:00:00' );

        \OWA\Core\CoreAPI::requestContainerSingleton()->setTimestamp( $when );

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( 'ts',
            \OWA\Module\Base\Classes\TrackingEventHelpers::edgeTimestampMicroseconds() );

        $this->assertSame( (int) date( 'Ymd', $when ),
            (int) \OWA\Module\Base\Classes\TrackingEventHelpers::deriveYyyymmdd( null, $event ),
            'a backdated event must carry the backdated date, not today' );
    }

    /** Restoring the clock restores it for both readings, so nothing leaks. */
    public function testTheClockCanBePutBack(): void
    {
        $rc     = \OWA\Core\CoreAPI::requestContainerSingleton();
        $before = $rc->getTimestamp();

        $rc->setTimestamp( strtotime( '2020-01-01 00:00:00' ) );
        $rc->setTimestamp( $before );

        $this->assertSame( $before, $rc->getTimestamp() );
        $this->assertSame( $before * 1000000, $rc->getTimestampMicroseconds() );
    }
}
