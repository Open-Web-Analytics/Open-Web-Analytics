<?php

use PHPUnit\Framework\TestCase;

/**
 * The period contract, and the way it fails.
 *
 * TimePeriod::isValid() rejects an unknown period name by falling back to the
 * default reporting period and logging a debug line. Nothing throws, nothing
 * warns, and any startDate that came with it is dropped -- so a caller asking
 * for a period that does not exist gets a plausible window over the wrong
 * dates. ReportVisitorsRoster did exactly that for as long as it has existed,
 * asking for 'day' and titling itself "New Visitors from <date>" while
 * listing the default window.
 *
 * These pin both halves: which names are real, and how a single day is
 * actually expressed.
 */
final class TimePeriodTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function period(string $name, array $map = []): object
    {
        return \OWA\Core\CoreAPI::makeTimePeriod($name, $map);
    }

    private function span(object $p): array
    {
        return [
            date('Y-m-d', $p->getStartDate()->getTimestamp()),
            date('Y-m-d', $p->getEndDate()->getTimestamp()),
        ];
    }

    /**
     * A single day is a date range whose ends are equal. There is no period
     * named 'day', and asking for one silently yields the default window.
     */
    public function testASingleDayIsADateRangeWithEqualEnds()
    {
        $day = date('Ymd', strtotime('-5 days'));

        $this->assertSame(
            [date('Y-m-d', strtotime($day)), date('Y-m-d', strtotime($day))],
            $this->span($this->period('date_range', ['startDate' => $day, 'endDate' => $day])),
            'a date_range with equal ends must cover exactly that day'
        );
    }

    /**
     * The failure mode that hid the bug: an unknown name is not refused, it is
     * replaced -- and the requested date goes with it.
     */
    public function testAnUnknownPeriodNameSilentlyDiscardsTheRequestedDate()
    {
        $day = date('Ymd', strtotime('-5 days'));

        $asked  = $this->span($this->period('day', ['startDate' => $day]));
        $wanted = date('Y-m-d', strtotime($day));

        $this->assertNotSame(
            [$wanted, $wanted],
            $asked,
            "'day' is not a period name; if this ever starts working, the fallback in "
            . 'TimePeriod::setFromMap() has changed and callers should be revisited'
        );
    }

    /** The names that actually exist, so a typo in a caller is catchable. */
    public function testTheValidPeriodNames()
    {
        $tp = \OWA\Core\CoreAPI::supportClassFactory('base', 'timePeriod');

        foreach ([
            'today', 'yesterday', 'this_week', 'this_month', 'this_year',
            'last_week', 'last_month', 'last_year', 'last_seven_days',
            'last_thirty_days', 'same_day_last_week', 'same_week_last_year',
            'same_month_last_year', 'date_range',
        ] as $name) {
            $this->assertTrue($tp->isValid($name), "$name should be a valid period");
        }

        foreach (['day', 'week', 'month', 'year', 'hour', 'last_24_hours', ''] as $name) {
            $this->assertFalse($tp->isValid($name), "$name should not be a valid period");
        }
    }

    /** A range with no dates falls back to today rather than to nothing. */
    public function testAnEmptyDateRangeCoversToday()
    {
        $this->assertSame(
            [date('Y-m-d'), date('Y-m-d')],
            $this->span($this->period('date_range', ['startDate' => '', 'endDate' => ''])),
            'an empty range should still be a usable single day'
        );
    }
}
