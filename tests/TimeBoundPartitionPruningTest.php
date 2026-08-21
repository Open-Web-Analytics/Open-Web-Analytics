<?php

use PHPUnit\Framework\TestCase;

/**
 * A time-bounded query must also carry a CLOSED date bound.
 *
 * The fact tables are RANGE-partitioned on yyyymmdd. A predicate naming only
 * `timestamp` cannot prune, and is unselective enough that the optimiser
 * abandons the timestamp index as well. Measured on a live table:
 *
 *   timestamp > X                           all partitions, no index, 405,217 rows
 *   yyyymmdd >= D AND timestamp > X         all from D,     no index, 351,937 rows
 *   yyyymmdd BETWEEN D-1 AND D AND ts > X   1 partition,    yyyymmdd,   4,080 rows
 *
 * The middle row is the reason this says CLOSED: an open lower bound still reads
 * every partition from D onward. Verified again after the fix with EXPLAIN on a
 * 29-partition table -- 29 partitions scanned before, 1 after.
 *
 * WHY IN THE QUERY BUILDER
 * A report author has no reason to know how the facts are physically laid out,
 * and there are ~60 declarative report controllers that would each have to
 * remember. Deriving it centrally also puts it at the seam a non-SQL reporting
 * backend would later override with its own pruning predicate -- ClickHouse
 * partition keys, Parquet row-group statistics and DuckDB zone maps all want the
 * same thing. Pushed into the reports instead, it would die with the star schema.
 *
 * THE REVERSE DIRECTION IS A CORRECTNESS BUG, NOT A PERFORMANCE ONE
 * last_half_hour and last_hour are allowlisted periods, and both produced
 * exactly the same constraint as `today` -- date BETWEEN D AND D, with no
 * timestamp bound at all. The period object knew it meant 22:15-22:45; the query
 * asked for all 1,440 minutes of the day. Measured on a live table: "last hour"
 * returned 131 rows where the true answer was 5. Wrong answers, silently, on a
 * period any user can pick.
 *
 * These assert the CONSTRAINTS rather than the SQL, because that is the contract
 * the builder owes: whatever the storage engine, a time bound implies a date
 * bound and a partial-day period implies a time bound.
 */
final class TimeBoundPartitionPruningTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function rsmForTimeRange(int $start, int $end): \OWA\Module\Base\Classes\ResultSetManager
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setTimePeriod('', '', '', $start, $end);

        return $rsm;
    }

    public function testATimeBoundAlsoProducesADateBound(): void
    {
        $end   = time();
        $start = $end - 1800;

        $constraints = $this->rsmForTimeRange($start, $end)->getConstraints();

        $this->assertArrayHasKey('timestamp', $constraints, 'the time bound itself is missing');
        $this->assertArrayHasKey('date', $constraints,
            'a time bound produced no date bound, so the query cannot prune partitions');
    }

    /** Closed at BOTH ends -- an open bound still reads every later partition. */
    public function testTheDateBoundIsClosed(): void
    {
        $end   = time();
        $start = $end - 1800;

        $date = $this->rsmForTimeRange($start, $end)->getConstraints()['date'];

        $this->assertSame('BETWEEN', $date['operator'],
            'the date bound must be a closed range, not an open comparison');
        $this->assertNotEmpty($date['value']['start']);
        $this->assertNotEmpty($date['value']['end']);
    }

    public function testTheDateBoundMatchesTheTimestamps(): void
    {
        $start = mktime(10, 0, 0, 3, 14, 2026);
        $end   = mktime(11, 0, 0, 3, 14, 2026);

        $date = $this->rsmForTimeRange($start, $end)->getConstraints()['date'];

        $this->assertSame(date('Ymd', $start), $date['value']['start']);
        $this->assertSame(date('Ymd', $end), $date['value']['end']);
    }

    /**
     * A window crossing midnight must span both days. Getting this wrong loses
     * rows rather than merely scanning too many, so it is the case that has to
     * be right.
     */
    public function testAWindowCrossingMidnightSpansBothDays(): void
    {
        $start = mktime(23, 45, 0, 3, 14, 2026);
        $end   = mktime(0, 15, 0, 3, 15, 2026);

        $date = $this->rsmForTimeRange($start, $end)->getConstraints()['date'];

        $this->assertSame(date('Ymd', $start), $date['value']['start']);
        $this->assertSame(date('Ymd', $end), $date['value']['end']);
        $this->assertNotSame($date['value']['start'], $date['value']['end'],
            'a window crossing midnight collapsed to a single day and would lose rows');
    }

    /** A plain date range already bounds yyyymmdd and must be left alone. */
    public function testADateRangePeriodIsUnchanged(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setTimePeriod('', '20260301', '20260331', '', '');

        $constraints = $rsm->getConstraints();

        $this->assertArrayHasKey('date', $constraints);
        $this->assertSame('BETWEEN', $constraints['date']['operator']);
        $this->assertSame('20260301', $constraints['date']['value']['start']);
        $this->assertSame('20260331', $constraints['date']['value']['end']);
        $this->assertArrayNotHasKey('timestamp', $constraints,
            'a date range should not invent a timestamp bound');
    }

    /**
     * The correctness half: a partial-day period must narrow by time, or it
     * silently reports the whole day.
     *
     * @dataProvider subDayPeriodProvider
     */
    public function testAPartialDayPeriodAlsoBoundsTime(string $period): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setTimePeriod($period, '', '', '', '');

        $constraints = $rsm->getConstraints();

        $this->assertArrayHasKey('timestamp', $constraints,
            "$period bounded only the date, so it reports the whole day");

        $span = $constraints['timestamp']['value']['end'] - $constraints['timestamp']['value']['start'];

        $this->assertLessThan(86400, $span, "$period did not narrow to less than a day");
    }

    public static function subDayPeriodProvider(): array
    {
        return ['last_half_hour' => ['last_half_hour'], 'last_hour' => ['last_hour']];
    }

    /**
     * Whole-day-or-longer periods must be left exactly as they were. Adding a
     * time bound here would narrow them -- last_seven_days starts at 23:59:59,
     * so a naive alignment test turns seven whole days into a rolling 7x24h
     * window and shifts numbers users already read.
     *
     * @dataProvider wholeDayPeriodProvider
     */
    public function testWholeDayPeriodsGainNoTimeBound(string $period): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setTimePeriod($period, '', '', '', '');

        $this->assertArrayNotHasKey('timestamp', $rsm->getConstraints(),
            "$period gained a time bound and would return fewer rows than before");
    }

    public static function wholeDayPeriodProvider(): array
    {
        return array_map(
            static fn($p) => [$p],
            array_combine(
                ['today', 'yesterday', 'this_week', 'last_week', 'last_seven_days',
                 'last_thirty_days', 'this_month', 'last_month', 'this_year', 'last_year'],
                ['today', 'yesterday', 'this_week', 'last_week', 'last_seven_days',
                 'last_thirty_days', 'this_month', 'last_month', 'this_year', 'last_year']
            )
        );
    }
}
