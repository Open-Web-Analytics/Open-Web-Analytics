<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\PartitionRotateCli;

/** A rotate whose clock the test sets. */
class RotateAtDate extends PartitionRotateCli
{
    public static $now = '20260921';

    protected function today()
    {
        return self::$now;
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
     * A run 40 days after the first: September merges back, November is carved,
     * and the daily tier stays two months wide.
     *
     * The cube breathes -- one month merged behind, one carved ahead -- so the
     * partition count is steady rather than growing.
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
     * A month that goes current without ever being carved stays monthly.
     *
     * The failure mode of a missed run, and the reason the job defaults to
     * daily: carving is only free while a month is empty, so a gap longer than
     * a month leaves that month coarse and its rebuilds month-sized.
     */
    public function testAMonthThatWentCurrentUncarvedIsNotCarvedLater(): void
    {
        RotateAtDate::$now = '20261205';

        // November went current while nothing ran, so it still spans a month.
        $spans = [
            $this->span('20261101', '20261201'),
            $this->span('20261201', '20270101'),
            $this->span('20270101', '20270201'),
        ];

        $candidates = $this->call('carveCandidates', [$spans]);
        $starts     = array_column($candidates, 'start');

        $this->assertNotContains('20261101', $starts, 'November is in the past now');
        $this->assertContains('20261201', $starts, 'December is current');
        $this->assertContains('20270101', $starts, 'January is next');
    }

    public function testTheWindowDefaultsRatherThanBeingZero(): void
    {
        // 3.1 has the number open pending a measurement of client-side
        // lateness; what must not happen is a zero window, which would merge a
        // month the moment it ended and make settling its last day cost a month.
        $this->assertGreaterThan(0, $this->call('windowDays', []));
    }
}
