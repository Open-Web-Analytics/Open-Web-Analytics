<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\PartitionRotateCli;

/**
 * A database that records what the lead maintenance asked of it.
 *
 * Only the four calls the merge and carve make. A partition is empty unless the
 * test puts a row count in $rows.
 */
class RecordingDb
{
    public array $spans  = [];
    public array $merged = [];
    public array $carved = [];

    /** partition name => rows. Anything unlisted is empty. */
    public array $rows = [];

    /** What the table is otherwise partitioned on; the merge cycle follows it. */
    public string $granularity = 'monthly';

    public function getPartitionSpans($table)
    {
        return $this->spans;
    }

    public function inferPartitionGranularity($table)
    {
        return $this->granularity;
    }

    public function getPartitionContents($table, $partition, $column = 'yyyymmdd')
    {
        return ['rows' => $this->rows[$partition] ?? 0];
    }

    public function mergePartitions($table, $names, $start, $less_than)
    {
        $this->merged[] = ['count' => count($names), 'start' => $start, 'less_than' => $less_than];

        return true;
    }

    public function reorganizePartitions($table, $from, $ranges)
    {
        $this->carved[] = ['from' => $from[0], 'into' => count($ranges)];

        return true;
    }
}

/** A rotate whose clock the test sets. */
class RotateAtDate extends PartitionRotateCli
{
    public static $now = '20260921';

    /** @var RecordingDb|null */
    public static $db = null;

    protected function today()
    {
        return self::$now;
    }

    protected function db()
    {
        return self::$db;
    }

    /** The cube is named by prefix, and the test builds no config. */
    protected function isCube($table)
    {
        return true;
    }
}

/**
 * The carve-and-merge arithmetic that keeps the front of the cube's lead daily.
 * cmd=partition-rotate runs it as part of maintaining that one lead -- there is
 * no second lead and no second budget.
 *
 * Two of these pin bugs that were in the first working version and that the
 * dry-run did not make obvious: it carved the entire twelve-month lead, and it
 * checked each carve against the budget without accumulating, so a run that
 * breached the limit three carves in looked fine at every individual step.
 *
 * Built without the constructor: Core\Controller\Cli exits unless the request
 * mode is cli, and this is arithmetic rather than dispatch.
 */
final class CubeRotateTest extends TestCase
{
    private function cli(): PartitionRotateCli
    {
        $class = new ReflectionClass(RotateAtDate::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $params = $class->getProperty('params');
        $params->setAccessible(true);
        $params->setValue($cli, []);

        return $cli;
    }

    private function call(string $method, array $args)
    {
        $m = new ReflectionMethod(RotateAtDate::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->cli(), $args);
    }

    protected function setUp(): void
    {
        RotateAtDate::$now = '20260921';
        RotateAtDate::$db  = new RecordingDb();
    }

    /** Every day of a month, as daily spans. */
    private function dailySpans(string $month): array
    {
        $spans = [];
        $day   = $month . '01';
        $end   = date('Ymd', strtotime($month . '01 +1 month'));

        while ($day < $end) {
            $next    = date('Ymd', strtotime($day . ' +1 day'));
            $spans[] = $this->span($day, $next);
            $day     = $next;
        }

        return $spans;
    }

    private function span(string $start, string $less_than): array
    {
        return ['name' => 'p' . $start, 'start' => $start, 'less_than' => $less_than];
    }

    /** Monthly spans from a given month forward, for a fixed clock. */
    private function monthlyLeadFrom(string $month, int $count): array
    {
        $spans = [];
        $start = $month . '01';

        for ($i = 0; $i < $count; $i++) {
            $end     = date('Ymd', strtotime($start . ' +1 month'));
            $spans[] = $this->span($start, $end);
            $start   = $end;
        }

        return $spans;
    }

    /** A month's worth of monthly spans, from this month forward. */
    private function monthlyLead(int $months): array
    {
        $spans = [];

        for ($i = 0; $i < $months; $i++) {
            $start = date('Ym01', strtotime("first day of +$i month"));
            $end   = date('Ym01', strtotime('first day of +' . ($i + 1) . ' month'));
            $spans[] = $this->span($start, $end);
        }

        return $spans;
    }

    public function testASpanKnowsWhetherItIsADayOrAMonth(): void
    {
        // Read from the bounds, not the name: a monthly partition and the first
        // daily one of the same month are both called p2026MM01.
        $this->assertSame(1, $this->call('spanDays', [$this->span('20260901', '20260902')]));
        $this->assertSame(30, $this->call('spanDays', [$this->span('20260901', '20261001')]));
        $this->assertSame(0, $this->call('spanDays', [$this->span('20260901', '20260901')]));
    }

    public function testItCarvesTwoMonthsOfTheLeadAndNotTheWholeLead(): void
    {
        // The bug: extendPartitions() fills twelve months of lead, and carving
        // all of it is ~390 daily partitions -- the whole budget spent on empty
        // future months. The lead stays twelve months; two of them are daily.
        RotateAtDate::$now = '20261110';

        $plan = $this->call('carvePlan', [$this->monthlyLeadFrom('202611', 12)]);

        $this->assertSame(['20261101', '20261201'], array_column($plan, 'start'),
            'two months, and the other ten left at the table\'s own granularity');
        $this->assertSame([30, 31], array_column($plan, 'days'));
    }

    /**
     * The carve waits for the merge rather than running ahead of it.
     *
     * This is what keeps the count flat. The merge cannot fire until the window
     * has cleared the previous month's last day, about the 8th; if the carve
     * fired on the 1st the daily part would be three months wide until then, and the
     * budget would have to cover that peak.
     */
    public function testItDoesNotCarveUntilTheMergeHasMadeRoom(): void
    {
        RotateAtDate::$now = '20261101';

        // The 1st: last month's days are still inside the window, so nothing
        // has merged, and the daily part is already two months deep.
        $spans = array_merge(
            $this->dailySpans('202610'),
            $this->dailySpans('202611'),
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')]
        );

        $this->assertSame([], $this->call('mergeablePeriods', [$spans]));
        $this->assertSame([], $this->call('carvePlan', [$spans]),
            'nothing merged, so nothing is carved -- it stays two months, not three');

        // The 8th: October has left the window and merged, so December is carved
        // in the same run.
        RotateAtDate::$now = '20261108';

        $merged = array_merge(
            [$this->span('20261001', '20261101')],
            $this->dailySpans('202611'),
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')]
        );

        $this->assertSame(['20261201'], array_column($this->call('carvePlan', [$merged]), 'start'));
    }

    /**
     * Two months of coverage, not sixty partitions.
     *
     * A flat count gets February wrong: after a 31-day month merges, 31 + 28 is
     * 59, and a gate of 60 would let a third month through.
     */
    public function testTheDailyPartIsMeasuredInMonthsNotPartitions(): void
    {
        RotateAtDate::$now = '20270108';

        // January daily (31), February and March still monthly.
        $spans = array_merge(
            $this->dailySpans('202701'),
            [$this->span('20270201', '20270301')],
            [$this->span('20270301', '20270401')],
            [$this->span('20270401', '20270501')]
        );

        $plan = $this->call('carvePlan', [$spans]);

        $this->assertSame(['20270201'], array_column($plan, 'start'),
            'February completes the two months at 59 partitions; March is not carved');
        $this->assertSame(59, $this->call('dailyCount', [$spans]) + $plan[0]['days']);
    }

    public function testALeadAlreadyTwoMonthsDailyIsLeftAlone(): void
    {
        RotateAtDate::$now = '20261110';

        $spans = array_merge(
            $this->dailySpans('202611'),
            $this->dailySpans('202612'),
            [$this->span('20270101', '20270201')],
            [$this->span('20270201', '20270301')]
        );

        $this->assertSame([], $this->call('carvePlan', [$spans]),
            'two months already, so nothing is rewritten');
    }

    public function testCoverageIsReadFromTheBoundsNotTheCount(): void
    {
        $spans = array_merge(
            $this->dailySpans('202611'),
            [$this->span('20261201', '20270101')]
        );

        $this->assertSame(
            ['start' => '20261101', 'end' => '20261201'],
            $this->call('dailyCoverage', [$spans])
        );

        $this->assertNull($this->call('dailyCoverage', [[$this->span('20261101', '20261201')]]),
            'nothing daily at all');
    }

    /** A twelve-month lead at a given granularity, as spans. */
    private function leadAt(string $granularity, string $from, string $to): array
    {
        $spans  = [];
        $ranges = \OWA\Core\Db::makePartitionRangesForSpan($from, $to, $granularity);

        foreach ($ranges as $name => $less_than) {
            $spans[] = $this->span(substr($name, 1), $less_than);
        }

        return $spans;
    }

    /**
     * Run the real merge and carve day by day for a year and report the
     * deepest the daily part of the lead ever got, in days.
     */
    private function deepestDailyPartOverAYear(string $granularity): int
    {
        $spans = $this->leadAt($granularity, '20260901', '20270901');
        $worst = 0;

        for ($date = '20260921'; $date <= '20270801'; $date = date('Ymd', strtotime($date . ' +1 day'))) {
            RotateAtDate::$now = $date;

            foreach ($this->call('mergeablePeriods', [$spans, $granularity]) as $group) {
                $spans = array_values(array_filter(
                    $spans, fn($s) => !in_array($s['name'], $group['names'], true)));
                $spans[] = $this->span($group['start'], $group['less_than']);
            }

            foreach ($this->call('carvePlan', [$spans]) as $carve) {
                $spans = array_values(array_filter($spans, fn($s) => $s['name'] !== $carve['name']));

                foreach ($carve['ranges'] as $name => $less_than) {
                    $spans[] = $this->span(substr($name, 1), $less_than);
                }
            }

            usort($spans, fn($a, $b) => strcmp($a['start'], $b['start']));

            $coverage = $this->call('dailyCoverage', [$spans]);

            if ($coverage) {
                $worst = max($worst, (int) round(
                    (strtotime($coverage['end']) - strtotime($coverage['start'])) / 86400));
            }
        }

        return $worst;
    }

    /**
     * THE INVARIANT: never more than two months of the cube's lead is daily,
     * whatever granularity the rest of the lead is at.
     *
     * Driven through a year day by day rather than asserted at one date,
     * because the way this breaks is a carve firing before the merge that pays
     * for it -- which only shows up at a period boundary.
     *
     * @dataProvider leadGranularities
     */
    public function testTheDailyPartOfTheLeadNeverExceedsTwoMonths(string $granularity): void
    {
        $deepest = $this->deepestDailyPartOverAYear($granularity);

        $this->assertLessThanOrEqual(62, $deepest,
            "a $granularity lead let its daily part reach $deepest days");

        $this->assertGreaterThan(55, $deepest,
            'and it does reach two months, rather than never carving at all');
    }

    public static function leadGranularities(): array
    {
        return [
            'monthly'       => ['monthly'],
            'half-month'    => ['half-month'],
            'quarter-month' => ['quarter-month'],
        ];
    }

    /**
     * Daily is the exception: the whole table is already daily, so there is no
     * daily part to bound at two months and nothing to carve or merge.
     */
    public function testAnAllDailyTableHasNothingToBound(): void
    {
        $spans = $this->leadAt('daily', '20260901', '20270901');

        RotateAtDate::$now = '20261110';

        $this->assertSame([], $this->call('carvePlan', [$spans]),
            'everything is daily already, so there is nothing to carve');
        $this->assertSame([], $this->call('mergeablePeriods', [$spans, 'daily']),
            'a daily period is one partition, so there is nothing to merge it into');

        $coverage = $this->call('dailyCoverage', [$spans]);

        $this->assertGreaterThan(62, (strtotime($coverage['end']) - strtotime($coverage['start'])) / 86400,
            'the whole lead is daily, which is the granularity the operator asked for');
    }

    public function testItNeverCarvesTheFurthestFutureMonth(): void
    {
        /*
         * Not about the budget -- about partition-rotate staying correct.
         * Granularity is never stored: inferPartitionGranularity() reads the
         * LAST span's month. The cube infers `monthly` only because the
         * furthest-future partitions are still monthly lead. Carve those and
         * the inference flips to `daily`, and partition-rotate would then
         * extend twelve months of lead at daily -- ~365 partitions, silently.
         */
        $lead = $this->monthlyLead(12);
        $last = $lead[count($lead) - 1];

        foreach ($this->call('carveCandidates', [$lead]) as $candidate) {
            $this->assertNotSame($last['start'], $candidate['start'],
                'the last span must stay monthly or granularity inference flips to daily');
        }
    }

    public function testItDoesNotCarveWhatIsAlreadyDaily(): void
    {
        $spans = [
            $this->span(date('Ym01'), date('Ym02')),
            $this->span(date('Ym02'), date('Ym03')),
        ];

        $this->assertSame([], $this->call('carveCandidates', [$spans]));
    }

    public function testItDoesNotCarveAMonthThatHasAlreadyPassed(): void
    {
        $start = date('Ym01', strtotime('first day of -2 month'));
        $end   = date('Ym01', strtotime('first day of -1 month'));

        $this->assertSame([], $this->call('carveCandidates', [[$this->span($start, $end)]]),
            'carving a month with rows rewrites all of them, which is the cost being avoided');
    }

    public function testDailyPartitionsGroupByTheirOwnPeriod(): void
    {
        $spans = [
            $this->span('20260901', '20260902'),
            $this->span('20260902', '20260903'),
            $this->span('20261001', '20261002'),
            $this->span('20261101', '20261201'),   // monthly, not part of any group
        ];

        $periods = $this->call('dailyByPeriod', [$spans]);

        // Numeric-string keys become ints in PHP, so compare as such.
        $this->assertSame([20260901, 20261001], array_keys($periods));
        $this->assertCount(2, $periods[20260901]['names']);
        $this->assertCount(1, $periods[20261001]['names']);
    }

    /**
     * The cycle follows the table's granularity, not the calendar month.
     *
     * With a quarter-month lead the merge happens a window after each
     * QUARTER ends, and merges that quarter's days back into one partition.
     */
    public function testTheCycleFollowsTheTablesOwnGranularity(): void
    {
        $spans = $this->dailySpans('202610');

        $periods = $this->call('dailyByPeriod', [$spans, 'quarter-month']);

        $this->assertSame([20261001, 20261008, 20261015, 20261022], array_keys($periods),
            'the quarter-month cuts are the 1st, 8th, 15th and 22nd');
        $this->assertCount(7, $periods[20261001]['names']);
        $this->assertCount(10, $periods[20261022]['names'], 'the last quarter carries the remainder');

        // A window after the third quarter ends, that quarter merges and the
        // fourth -- still inside the window -- does not.
        RotateAtDate::$now = '20261029';

        $starts = array_keys($this->call('mergeablePeriods', [$spans, 'quarter-month']));

        $this->assertContains(20261015, $starts, 'the third quarter ended on the 21st');
        $this->assertNotContains(20261022, $starts, 'the fourth is still inside the window');
    }

    /** A period only partly carved is not merged: the span would overlap. */
    public function testAPartlyDailyPeriodIsNotMerged(): void
    {
        RotateAtDate::$now = '20261205';

        $spans = array_values(array_filter(
            $this->dailySpans('202610'),
            fn($s) => $s['start'] < '20261020'
        ));

        $this->assertSame([], array_keys($this->call('mergeablePeriods', [$spans])),
            'merging 19 of October days into a span covering the month would overlap the rest');
    }

    public function testARunFortyDaysLaterMergesBehindAndCarvesAhead(): void
    {
        RotateAtDate::$now = '20261031';

        $spans = array_merge(
            $this->dailySpans('202609'),                       // carved on day one
            $this->dailySpans('202610'),                       // carved on day one
            [$this->span('20261101', '20261201')],             // still monthly
            [$this->span('20261201', '20270101')]
        );

        // September's days have left the 7-day window; October's have not.
        $months = $this->call('dailyByPeriod', [$spans]);
        $this->assertCount(30, $months['20260901']['names']);
        $this->assertCount(31, $months['20261001']['names']);

        $cutoff = date('Ymd', strtotime('20261031 -7 days'));
        $this->assertLessThan($cutoff, '20260930', 'September has expired');
        $this->assertGreaterThanOrEqual($cutoff, '20261031', 'October has not');

        // And November -- next month, still empty -- is what gets carved.
        // December is excluded as the last span, not by any horizon.
        $candidates = $this->call('carveCandidates', [$spans]);

        $this->assertCount(1, $candidates);
        $this->assertSame('20261101', $candidates[0]['start']);
    }

    /**
     * A month that has ended is still a candidate while the window reaches it.
     *
     * On 5 December a seven-day window still rebuilds November, and those
     * rebuilds are month-sized until it is carved -- so it must reach the check
     * that weighs the real cost (it holds rows) rather than being excluded for
     * being in the past. That is the difference between force=1 having
     * something to override and having nothing.
     */
    public function testAMonthStillInsideTheWindowIsStillACandidate(): void
    {
        RotateAtDate::$now = '20261205';

        $spans = [
            $this->span('20261001', '20261101'),   // ended, window has passed it
            $this->span('20261101', '20261201'),   // ended, window still reaches it
            $this->span('20261201', '20270101'),   // current
            $this->span('20270101', '20270201'),   // lead
            $this->span('20270201', '20270301'),   // the last span, read for granularity
        ];

        $starts = array_column($this->call('carveCandidates', [$spans]), 'start');

        $this->assertNotContains('20261001', $starts, 'the window no longer reaches October');
        $this->assertContains('20261101', $starts, 'November is rebuilt until the 7th');
        $this->assertContains('20261201', $starts, 'December is current');
        $this->assertContains('20270101', $starts, 'January is ordinary lead');
        $this->assertNotContains('20270201', $starts,
            'February is the last span, which granularity is inferred from');
    }

    /**
     * No month is ever both merged and carved.
     *
     * The two triggers are complementary around the same instant -- merge when
     * the window no longer reaches a month, carve while it still does -- so the
     * sets are disjoint by construction. Overlap them and the table is rewritten
     * on every scheduled run for no change in shape, which is the failure
     * makeTieredPartitionRanges() already carries a warning about.
     */
    public function testAMonthIsNeverBothMergedAndCarved(): void
    {
        RotateAtDate::$now = '20261205';

        // Every month around the boundary, as both shapes at once, so whichever
        // way a month is classified it is offered to both decisions.
        $monthly = [];
        $daily   = [];

        foreach (['202609', '202610', '202611', '202612', '202701'] as $month) {
            $monthly[] = $this->span($month . '01', date('Ymd', strtotime($month . '01 +1 month')));
            $daily     = array_merge($daily, $this->dailySpans($month));
        }

        // One month past the ones under test, because the LAST span is never a
        // candidate -- granularity is inferred from it. Without this the
        // exclusion would land on 202701 and read as a disjointness failure.
        $monthly[] = $this->span('20270201', '20270301');

        $carvable = array_column($this->call('carveCandidates', [$monthly]), 'start');

        $window = $this->call('windowDays', []);
        $cutoff = date('Ymd', strtotime(RotateAtDate::$now . " -$window days"));

        foreach ($this->call('dailyByPeriod', [$daily]) as $start => $group) {
            $month     = substr($start, 0, 6);
            $month_end = date('Ymd', strtotime($group['less_than'] . ' -1 day'));
            $mergeable = $month_end < $cutoff;

            $this->assertSame(
                $mergeable,
                !in_array($month . '01', $carvable, true),
                "month $month must be exactly one of mergeable or carvable, never both or neither"
            );
        }
    }

    /**
     * The partition taking writes is carved even though it holds rows.
     *
     * If today is inside a monthly partition the daily part has fallen behind, and
     * every cube rebuild until that month ends rewrites a month. Carving costs
     * that rewrite once; not carving costs it on every run.
     */
    /**
     * The merge turns a decision into one ALTER per month, not one per day.
     *
     * The arithmetic is tested above; this covers the loop that acts on it,
     * which reads the live partition list and issues the DDL. A dangling
     * variable in its notice survived every arithmetic test.
     */
    public function testTheMergeIssuesOneAlterPerMonth(): void
    {
        RotateAtDate::$now       = '20261205';
        RotateAtDate::$db->spans = array_merge(
            $this->dailySpans('202610'),
            $this->dailySpans('202611'),
            [$this->span('20261201', '20270101')]
        );

        $touched = $this->call('mergeExpiredCubeDays', ['owa_event', false]);

        $this->assertTrue($touched);
        $this->assertSame(
            [['count' => 31, 'start' => '20261001', 'less_than' => '20261101']],
            RotateAtDate::$db->merged,
            'October merges; November is still inside the window on the 5th'
        );
    }

    public function testADryRunMergeIssuesNothing(): void
    {
        RotateAtDate::$now       = '20261205';
        RotateAtDate::$db->spans = $this->dailySpans('202610');

        $this->assertTrue($this->call('mergeExpiredCubeDays', ['owa_event', true]));
        $this->assertSame([], RotateAtDate::$db->merged);
    }

    /** The carve issues one REORGANIZE per span, for as many days as the plan took. */
    public function testTheCarveIssuesOneReorganizePerSpan(): void
    {
        RotateAtDate::$now       = '20261110';
        RotateAtDate::$db->spans = array_merge(
            $this->dailySpans('202611'),                    // 30 daily already
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')],
            [$this->span('20270201', '20270301')]
        );

        $touched = $this->call('carveCubeMonths', ['owa_event', ['limit' => 145, 'reason' => 'test'], false]);

        $this->assertTrue($touched);
        $this->assertSame(
            [['from' => 'p20261201', 'into' => 31]],
            RotateAtDate::$db->carved,
            'one REORGANIZE, for one whole span -- November is daily already, so December '
          . 'completes the two months and January is left monthly'
        );
    }

    public function testADryRunCarveIssuesNothing(): void
    {
        RotateAtDate::$now       = '20261110';
        RotateAtDate::$db->spans = [
            $this->span('20261101', '20261201'),
            $this->span('20261201', '20270101'),
            $this->span('20270101', '20270201'),
        ];

        $this->assertTrue($this->call('carveCubeMonths', ['owa_event', ['limit' => 145, 'reason' => 'test'], true]));
        $this->assertSame([], RotateAtDate::$db->carved);
    }

    public function testItCarvesTheMonthlyPartitionThatIsTakingWrites(): void
    {
        RotateAtDate::$now       = '20261110';
        RotateAtDate::$db->spans = [
            $this->span('20261101', '20261201'),
            $this->span('20261201', '20270101'),
            $this->span('20270101', '20270201'),
        ];
        RotateAtDate::$db->rows = ['p20261101' => 48_000];

        $this->call('carveCubeMonths', ['owa_event', ['limit' => 145, 'reason' => 'test'], false]);

        $this->assertSame('p20261101', RotateAtDate::$db->carved[0]['from'],
            'the one taking writes is carved first, despite its rows');
        $this->assertSame(30, RotateAtDate::$db->carved[0]['into']);
    }

    /**
     * A budget too small for a daily front leaves the lead coarse, not broken.
     *
     * One lead, one budget, and the daily part is the half that loses:
     * extendTableLead() runs first and its dozen coarse partitions always fit,
     * so it is the sixty daily ones the budget refuses. The table still has a
     * lead; every rebuild is just period-sized.
     */
    public function testATightBudgetLeavesTheLeadCoarseRatherThanCarvingPastIt(): void
    {
        RotateAtDate::$now       = '20261110';
        RotateAtDate::$db->spans = [
            $this->span('20261101', '20261201'),
            $this->span('20261201', '20270101'),
            $this->span('20270101', '20270201'),
        ];

        $touched = $this->call('carveCubeMonths',
            ['owa_event', ['limit' => 20, 'reason' => 'a small server'], false]);

        $this->assertFalse($touched);
        $this->assertSame([], RotateAtDate::$db->carved,
            'refused whole rather than part-carved up to the limit');
    }

    /**
     * THE LEAD IS EXHAUSTED: rows are landing in a coarse partition.
     *
     * The case a missed run or an upgrade leaves behind. Nothing is daily, the
     * partition holding today has rows, and every cube-rebuild until that
     * period ends is rewriting a whole month. One run has to get the whole
     * daily part back, not just make a start on it.
     */
    public function testItRecoversTheWholeDailyPartWhenTheLeadIsExhausted(): void
    {
        RotateAtDate::$now       = '20261110';
        RotateAtDate::$db->spans = array_merge(
            [$this->span('20261101', '20261201')],          // current, HOLDS ROWS
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')],
            [$this->span('20270201', '20270301')],
            [$this->span('20270301', '20270401')]
        );
        RotateAtDate::$db->rows = ['p20261101' => 120_000];

        $this->assertSame(0, $this->call('dailyCount', [RotateAtDate::$db->spans]),
            'the fixture starts with nothing daily');

        $this->call('carveCubeMonths', ['owa_event', ['limit' => 400, 'reason' => 'test'], false]);

        $carved = RotateAtDate::$db->carved;

        $this->assertSame(['p20261101', 'p20261201'], array_column($carved, 'from'),
            'both months in one run -- November despite its rows, then December');

        $this->assertSame(61, array_sum(array_column($carved, 'into')),
            'which is the full two months, not a first instalment');
    }

    /**
     * And the recovery is idempotent: a second run has nothing left to do.
     *
     * Otherwise the daily part would be re-carved on every run, rewriting the
     * rows it just rewrote.
     */
    public function testTheRecoveryDoesNotRepeatOnTheNextRun(): void
    {
        RotateAtDate::$now = '20261110';

        $recovered = array_merge(
            $this->dailySpans('202611'),
            $this->dailySpans('202612'),
            [$this->span('20270101', '20270201')],
            [$this->span('20270201', '20270301')]
        );

        RotateAtDate::$db->spans = $recovered;
        RotateAtDate::$db->rows  = ['p20261101' => 12_000];

        $this->call('carveCubeMonths', ['owa_event', ['limit' => 400, 'reason' => 'test'], false]);

        $this->assertSame([], RotateAtDate::$db->carved,
            'two months daily already, so nothing is rewritten');
    }

    public function testItLeavesAPastMonthWithRowsAlone(): void
    {
        RotateAtDate::$now       = '20261110';
        RotateAtDate::$db->spans = array_merge(
            [$this->span('20261001', '20261101')],          // past, holds rows
            $this->dailySpans('202611'),                    // current, already daily
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')]
        );
        RotateAtDate::$db->rows = ['p20261001' => 48_000];

        $this->call('carveCubeMonths', ['owa_event', ['limit' => 145, 'reason' => 'test'], false]);

        $this->assertNotContains('p20261001', array_column(RotateAtDate::$db->carved, 'from'),
            'not taking writes and merges shortly, so rewriting it buys nothing');
    }

    public function testTheWindowDefaultsRatherThanBeingZero(): void
    {
        // 3.1 has the number open pending a measurement of client-side
        // lateness; what must not happen is a zero window, which would merge a
        // month the moment it ended and make settling its last day cost a month.
        $this->assertGreaterThan(0, $this->call('windowDays', []));
    }
}
