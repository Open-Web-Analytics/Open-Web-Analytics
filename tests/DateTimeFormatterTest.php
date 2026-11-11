<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A `datetime` value is epoch seconds and reads as a date and time in the
 * configured timezone: the session's start, on Most Recent Visits.
 */
final class DateTimeFormatterTest extends TestCase
{
    private $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set( 'America/Los_Angeles' );
    }

    protected function tearDown(): void
    {
        date_default_timezone_set( $this->zone );
    }

    private function rsm()
    {
        return new \OWA\Module\Base\Classes\ResultSetManager;
    }

    public function testThisYearsDateLeavesOutTheYear(): void
    {
        $ts = mktime( 11, 4, 9, 3, 10, (int) date( 'Y' ) );

        $this->assertSame( 'Mar 10, 11:04 AM', $this->rsm()->formatValue( 'datetime', (string) $ts ) );
    }

    public function testAnotherYearsDateSaysWhichYear(): void
    {
        $ts = mktime( 23, 30, 0, 12, 31, (int) date( 'Y' ) - 1 );

        $this->assertSame( 'Dec 31, ' . ( (int) date( 'Y' ) - 1 ) . ', 11:30 PM',
            $this->rsm()->formatValue( 'datetime', $ts ) );
    }

    /** The configured zone, not UTC: 19:04 UTC is 11:04 in Los Angeles in March. */
    public function testItReadsInTheConfiguredZone(): void
    {
        $ts = gmmktime( 19, 4, 0, 3, 10, (int) date( 'Y' ) );

        $this->assertSame( 'Mar 10, 12:04 PM', $this->rsm()->formatValue( 'datetime', $ts ),
            'Los Angeles is on daylight time (UTC-7) from the second Sunday of March' );
    }

    public function testAMissingStartIsNotSet(): void
    {
        $this->assertSame( '(not set)', $this->rsm()->formatDimensionValue( 'datetime', null ) );
        $this->assertSame( 'abc', $this->rsm()->formatValue( 'datetime', 'abc' ) );
    }

    /** Sorted on, the cube's column orders the visits: newest first descending. */
    public function testTheDimensionIsTheSessionsStart(): void
    {
        $dims = \OWA\Core\CoreAPI::getAllDimensions();

        $this->assertArrayHasKey( 'sessionStartTime', $dims );
        $this->assertSame( 'session_start_ts', $dims['sessionStartTime']['column'] );
        $this->assertSame( 'datetime', $dims['sessionStartTime']['data_type'] );
        $this->assertSame( 'Visit Start', $dims['sessionStartTime']['label'] );
    }
}
