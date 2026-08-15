<?php

use PHPUnit\Framework\TestCase;

/**
 * Partition boundaries are named for how many parts a month is divided into.
 *
 * Never for a duration: months are 28 to 31 days, so any name carrying a day
 * count would be wrong in some month. 'weekly' was wrong wherever a week
 * crossed a month end, '7day' wherever a month did not divide by seven, and
 * 'tenday' for the 21st onwards, which is 8 days in February and 11 in January.
 *
 * Cutting only on days of the month is also what keeps every boundary on a
 * month start, which is what lets a granularity change rewrite one month at a
 * time instead of the whole table. These assert that property directly, since
 * it is the reason the scheme is shaped this way.
 */
final class PartitionRangesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** Only divisions of a month are granularities. */
    public function testAcceptedGranularities()
    {
        foreach (['daily', 'quarter-month', 'half-month', 'monthly'] as $g) {
            $this->assertTrue(\OWA\Core\Db::isPartitionGranularity($g), "$g should be accepted");
        }

        // Rejected because they claim a length a month cannot honour.
        foreach (['weekly', '7day', 'tenday', 'hourly', ''] as $g) {
            $this->assertFalse(\OWA\Core\Db::isPartitionGranularity($g), "$g should be rejected");
        }
    }

    /** A month is divided into exactly the promised number of parts. */
    public function testPartsPerMonth()
    {
        // January 2026: 31 days. February 2026: 28 days.
        $cases = [
            ['monthly',       20260101, 20260131, 1],
            ['half-month',    20260101, 20260131, 2],
            ['quarter-month', 20260101, 20260131, 4],
            ['daily',         20260101, 20260131, 31],
            ['monthly',       20260201, 20260228, 1],
            ['half-month',    20260201, 20260228, 2],
            ['quarter-month', 20260201, 20260228, 4],
            ['daily',         20260201, 20260228, 28],
        ];

        foreach ($cases as [$g, $from, $to, $expected]) {
            $this->assertCount(
                $expected,
                \OWA\Core\Db::makePartitionRanges($from, $to, $g),
                sprintf('%s should divide %d into %d part(s)', $g, $from, $expected)
            );
        }
    }

    /** Leap day is inside February, not a partition of its own. */
    public function testLeapYearFebruary()
    {
        $r = \OWA\Core\Db::makePartitionRanges(20280201, 20280229, 'quarter-month');

        $this->assertCount(4, $r);
        $this->assertSame('20280301', end($r), 'the last part must close on 1 March');
    }

    /**
     * The property the whole scheme exists for: no partition spans a month
     * boundary, so old and new layouts always share the month starts.
     */
    public function testNoPartitionCrossesAMonthBoundary()
    {
        foreach (['daily', 'quarter-month', 'half-month', 'monthly'] as $g) {

            foreach (\OWA\Core\Db::makePartitionRanges(20251215, 20260315, $g) as $name => $less_than) {

                $start = substr($name, 1);

                // A partition may end on the 1st of the next month, but must
                // not reach past it.
                $month_end = date('Ymd', strtotime(substr($start, 0, 6) . '01 +1 month'));

                $this->assertLessThanOrEqual(
                    $month_end,
                    $less_than,
                    sprintf('%s partition %s reaches past its month, to %s', $g, $name, $less_than)
                );
            }
        }
    }

    /** Reorganisation ranges must tile the span exactly -- REORGANIZE requires it. */
    public function testSpanRangesTileExactly()
    {
        $r = \OWA\Core\Db::makePartitionRangesForSpan(20260201, 20260301, 'quarter-month');

        $this->assertNotEmpty($r);
        $this->assertSame('p20260201', array_key_first($r), 'must start on the span start');
        $this->assertSame('20260301', end($r), 'must close exactly on the span end');

        // No gaps: each partition begins where the previous one ended.
        $prev = null;
        foreach ($r as $name => $less_than) {
            if ($prev !== null) {
                $this->assertSame($prev, substr($name, 1), 'gap between partitions');
            }
            $prev = $less_than;
        }
    }

    /** Bad input yields nothing rather than a malformed statement. */
    public function testRejectsUnusableInput()
    {
        $this->assertSame([], \OWA\Core\Db::makePartitionRanges(20260101, 20260201, 'weekly'));
        $this->assertSame([], \OWA\Core\Db::makePartitionRanges(20260301, 20260101, 'monthly'), 'reversed range');
        $this->assertSame([], \OWA\Core\Db::makePartitionRangesForSpan(20260101, 20260101, 'monthly'), 'empty span');
    }
}
