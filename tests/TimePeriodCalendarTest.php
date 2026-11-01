<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TimePeriod;

/**
 * Every named period is whole calendar days, counted from a fixed "now".
 *
 * "Last seven days" is today and the six days before it. It was the end of
 * today less 7 x 86400 seconds, which is eight days most of the time and seven
 * across the night the clocks go back. Periods counted in seconds go wrong
 * that way twice a year, so every day of a year is walked here, at the start,
 * middle and end of the day, in the configured zone.
 */
final class TimePeriodCalendarTest extends TestCase
{
    /** period => [first day, last day] as DateTimeImmutable at midnight. */
    private static function expected(string $period, DateTimeImmutable $today): array
    {
        $w = (int) $today->format('w');
        $y = (int) $today->format('Y');
        $m = (int) $today->format('n');
        $d = (int) $today->format('j');

        $day = fn(int $year, int $month, int $dom) => (new DateTimeImmutable())->setDate($year, $month, $dom)->setTime(0, 0, 0);

        switch ($period) {
            case 'today':               return [$today, $today];
            case 'yesterday':           return [$today->modify('-1 day'), $today->modify('-1 day')];
            case 'last_seven_days':     return [$today->modify('-6 days'), $today];
            case 'last_thirty_days':    return [$today->modify('-29 days'), $today];
            case 'this_week':           return [$today->modify("-$w days"), $today->modify('+' . (6 - $w) . ' days')];
            case 'last_week':           return [$today->modify('-' . ($w + 7) . ' days'), $today->modify('-' . ($w + 1) . ' days')];
            case 'same_day_last_week':  return [$today->modify('-7 days'), $today->modify('-7 days')];
            case 'this_month':          return [$day($y, $m, 1), $day($y, $m + 1, 0)];
            case 'last_month':          return [$day($y, $m - 1, 1), $day($y, $m, 0)];
            case 'this_year':           return [$day($y, 1, 1), $day($y, 12, 31)];
            case 'last_year':           return [$day($y - 1, 1, 1), $day($y - 1, 12, 31)];
            case 'same_month_last_year': return [$day($y - 1, $m, 1), $day($y - 1, $m + 1, 0)];
            case 'same_week_last_year': return [$day($y - 1, $m, $d - $w), $day($y - 1, $m, $d + 6 - $w)];
        }

        throw new \LogicException($period);
    }

    public static function periods(): array
    {
        return array_map(fn($p) => [$p], [
            'today', 'yesterday', 'last_seven_days', 'last_thirty_days',
            'this_week', 'last_week', 'same_day_last_week',
            'this_month', 'last_month', 'this_year', 'last_year',
            'same_month_last_year', 'same_week_last_year',
        ]);
    }

    /**
     * @dataProvider periods
     */
    public function testEveryDayOfAYearGivesWholeCalendarDays(string $period): void
    {
        $wrong = [];

        $today = (new DateTimeImmutable())->setDate(2026, 1, 1)->setTime(0, 0, 0);

        for ($i = 0; $i < 365; $i++, $today = $today->modify('+1 day')) {

            foreach ([[0, 30], [12, 0], [23, 30]] as [$h, $min]) {

                $tp = new TimePeriod();
                $tp->now = $today->setTime($h, $min)->getTimestamp();
                $tp->set($period);

                [$first, $last] = self::expected($period, $today);

                $want = $first->format('Ymd') . ' 000000 - ' . $last->format('Ymd') . ' 235959';
                $got  = date('Ymd His', $tp->getStartDate()->getTimestamp()) . ' - '
                      . date('Ymd His', $tp->getEndDate()->getTimestamp());

                if ($got !== $want) {
                    $wrong[] = sprintf('now %s: want %s, got %s', $today->setTime($h, $min)->format('Y-m-d H:i'), $want, $got);
                }
            }
        }

        $this->assertSame([], array_slice($wrong, 0, 5),
            sprintf('%s is wrong at %d of %d instants in %s', $period, count($wrong), 365 * 3, date_default_timezone_get()));
    }

    /** The test is only worth anything in a zone with daylight saving. */
    public function testTheZoneChangesItsClocks(): void
    {
        $zone = new DateTimeZone(date_default_timezone_get());

        $this->assertNotSame(
            $zone->getOffset(new DateTime('2026-01-15')),
            $zone->getOffset(new DateTime('2026-07-15')),
            'run in a zone with daylight saving, or the clock-change nights test nothing');
    }
}
