<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\PartitionRotateCli;

/**
 * A database that records what the front tier asked of it.
 *
 * Only the four calls the merge and carve make. Every partition it is given is
 * reported empty, because carving a month that holds rows is a separate branch
 * with its own test.
 */
class RecordingDb
{
    public array $spans  = [];
    public array $merged = [];
    public array $carved = [];

    public function getPartitionSpans($table)
    {
        return $this->spans;
    }

    public function getPartitionContents($table, $partition, $column = 'yyyymmdd')
    {
        return ['rows' => 0];
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
 * The cube front tier's carve-and-merge arithmetic, which cmd=partition-rotate
 * runs alongside the lead.
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

    public function testItCarvesThisMonthAndNextAndNotTheWholeLead(): void
    {
        // The bug: extendPartitions() fills twelve months of lead, and carving
        // all of it is ~390 daily partitions -- the whole budget spent on empty
        // future months, which is what the two-stage scheme exists to avoid.
        $candidates = $this->call('carveCandidates', [$this->monthlyLead(12)]);

        $this->assertCount(2, $candidates,
            'the daily lead covers the rebuild window plus a margin, not the whole lead');

        $this->assertSame(date('Ym01'), $candidates[0]['start']);
        $this->assertSame(date('Ym01', strtotime('first day of +1 month')), $candidates[1]['start']);
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

    public function testDailyPartitionsGroupByTheirOwnMonth(): void
    {
        $spans = [
            $this->span('20260901', '20260902'),
            $this->span('20260902', '20260903'),
            $this->span('20261001', '20261002'),
            $this->span('20261101', '20261201'),   // monthly, not part of any group
        ];

        $months = $this->call('dailyByMonth', [$spans]);

        // Numeric-string keys become ints in PHP, so compare as such.
        $this->assertSame([202609, 202610], array_keys($months));
        $this->assertCount(2, $months[202609]['names']);
        $this->assertSame('20260901', $months[202609]['start']);
        $this->assertSame('20261001', $months[202609]['less_than'],
            'a merge replaces the days with one partition spanning the whole month');
    }

    /**
     * A run 40 days after the first: September merges back, November is carved.
     *
     * The cube breathes -- one month merged behind, one carved ahead -- so the
     * count sawtooths rather than growing. It is two months of daily for most
     * of a month and three for the week between a carve and its merge.
     */
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
        $months = $this->call('dailyByMonth', [$spans]);
        $this->assertCount(30, $months[202609]['names']);
        $this->assertCount(31, $months[202610]['names']);

        $cutoff = date('Ymd', strtotime('20261031 -7 days'));
        $this->assertLessThan($cutoff, '20260930', 'September has expired');
        $this->assertGreaterThanOrEqual($cutoff, '20261031', 'October has not');

        // And November -- next month, still empty -- is what gets carved.
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
            $this->span('20270101', '20270201'),   // next
            $this->span('20270201', '20270301'),   // beyond the margin
        ];

        $starts = array_column($this->call('carveCandidates', [$spans]), 'start');

        $this->assertNotContains('20261001', $starts, 'the window no longer reaches October');
        $this->assertContains('20261101', $starts, 'November is rebuilt until the 7th');
        $this->assertContains('20261201', $starts, 'December is current');
        $this->assertContains('20270101', $starts, 'January is the carve-ahead margin');
        $this->assertNotContains('20270201', $starts, 'February is beyond it');
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

        $carvable = array_column($this->call('carveCandidates', [$monthly]), 'start');

        $window = $this->call('windowDays', []);
        $cutoff = date('Ymd', strtotime(RotateAtDate::$now . " -$window days"));

        foreach ($this->call('dailyByMonth', [$daily]) as $month => $group) {
            $month_end = date('Ymd', strtotime($month . '01 +1 month -1 day'));
            $mergeable = $month_end < $cutoff;

            $this->assertSame(
                $mergeable,
                !in_array($month . '01', $carvable, true),
                "month $month must be exactly one of mergeable or carvable, never both or neither"
            );
        }
    }

    /**
     * For the week after a carve, three months are daily at once.
     *
     * The window reaches back seven days while the carve reaches forward up to
     * thirty-one, so they overlap: the month the window still covers, the
     * current one, and the margin. That peak is what has to fit the partition
     * budget -- roughly 103 against a limit of 145 here -- not the steady state
     * of about 73.
     */
    public function testTheCarveAndTheWindowOverlapForAWeek(): void
    {
        RotateAtDate::$now = '20261101';   // carve day, before that month merges

        $spans = array_merge(
            $this->dailySpans('202610'),                        // window still reaches it
            $this->dailySpans('202611'),                        // current
            [$this->span('20261201', '20270101')],              // the margin, about to be carved
            [$this->span('20270101', '20270201')]
        );

        $mergeable = array_keys($this->call('mergeableMonths', [$spans]));
        $carvable  = array_column($this->call('carveCandidates', [$spans]), 'start');

        $this->assertSame([], $mergeable,
            'on the 1st the window still reaches last month, so nothing merges yet');
        $this->assertSame(['20261201'], $carvable,
            'and December is carved, making three months daily at once');
    }

    /**
     * The merge turns a decision into one ALTER per month, not one per day.
     *
     * The arithmetic is tested above; this covers the loop that acts on it,
     * which reads the live partition list and issues the DDL. A dangling
     * variable in its notice survived every arithmetic test.
     */
    public function testTheMergeIssuesOneAlterPerMonth(): void
    {
        RotateAtDate::$now      = '20261205';
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

    /** The carve turns one monthly partition into that month's days. */
    public function testTheCarveIssuesOneReorganizePerMonth(): void
    {
        RotateAtDate::$now       = '20261101';
        RotateAtDate::$db->spans = array_merge(
            $this->dailySpans('202611'),
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')]
        );

        $budget  = ['limit' => 145, 'reason' => 'test'];
        $touched = $this->call('carveCubeMonths', ['owa_event', $budget, false]);

        $this->assertTrue($touched);
        $this->assertSame(
            [['from' => 'p20261201', 'into' => 31]],
            RotateAtDate::$db->carved,
            'December is carved into 31 days; January is beyond the margin'
        );
    }

    public function testTheWindowDefaultsRatherThanBeingZero(): void
    {
        // 3.1 has the number open pending a measurement of client-side
        // lateness; what must not happen is a zero window, which would merge a
        // month the moment it ended and make settling its last day cost a month.
        $this->assertGreaterThan(0, $this->call('windowDays', []));
    }
}
