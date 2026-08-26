<?php

use PHPUnit\Framework\TestCase;

/**
 * Cron parsing and the level-triggered due test.
 *
 * Pure: no database, no clock, no settings — so unlike most of this suite it
 * runs everywhere, including the CI job that has no database and skips 232
 * tests. That matters, because this is where the scheduler's correctness lives.
 *
 * The expectations are written out as literal dates rather than recomputed with
 * the same expression the implementation uses. A test that says
 * `assertSame(date('Ymd', strtotime($x)), subject($x))` asserts nothing except
 * that PHP is consistent with itself.
 */
final class CronTest extends TestCase
{
    /** The zone every case is expressed in, chosen because it observes DST. */
    private const TZ = 'America/Los_Angeles';

    private function at(string $iso): int
    {
        return (new DateTimeImmutable($iso, new DateTimeZone(self::TZ)))->getTimestamp();
    }

    private function parse(string $expr): array
    {
        $parsed = \OWA\Core\Cron::parse($expr);
        $this->assertIsArray($parsed, "should have parsed: $expr");

        return $parsed;
    }

    // -----------------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------------

    public function testParsesEveryFieldForm()
    {
        $cases = [
            // expression          field index  expected values
            ['* * * * *',          0, range(0, 59)],
            ['5 * * * *',          0, [5]],
            ['0,30 * * * *',       0, [0, 30]],
            ['1-5 * * * *',        0, [1, 2, 3, 4, 5]],
            ['*/15 * * * *',       0, [0, 15, 30, 45]],
            ['0-10/5 * * * *',     0, [0, 5, 10]],
            ['5/10 * * * *',       0, [5, 15, 25, 35, 45, 55]],
            ['1,3,5-7 * * * *',    0, [1, 3, 5, 6, 7]],
            ['* 0 * * *',          1, [0]],
            ['* 9-17 * * *',       1, range(9, 17)],
            ['* * 1 * *',          2, [1]],
            ['* * * 6 *',          3, [6]],
            ['* * * * 1-5',        4, [1, 2, 3, 4, 5]],
        ];

        foreach ($cases as [$expr, $field, $expected]) {
            $this->assertSame($expected, $this->parse($expr)[$field], $expr);
        }
    }

    /** The @ shorthands are vixie-cron's own, not an invention of ours. */
    public function testParsesTheStandardAliases()
    {
        foreach ([
            '@hourly'   => '0 * * * *',
            '@daily'    => '0 0 * * *',
            '@midnight' => '0 0 * * *',
            '@weekly'   => '0 0 * * 0',
            '@monthly'  => '0 0 1 * *',
            '@yearly'   => '0 0 1 1 *',
            '@annually' => '0 0 1 1 *',
        ] as $alias => $equivalent) {
            $this->assertSame(
                \OWA\Core\Cron::parse($equivalent),
                \OWA\Core\Cron::parse($alias),
                "$alias should be identical to $equivalent"
            );
        }

        // Case and surrounding whitespace are forgiven; the value comes from a
        // config file a human typed.
        $this->assertSame(\OWA\Core\Cron::parse('@daily'), \OWA\Core\Cron::parse('  @DAILY '));
    }

    /**
     * Anything unreadable yields null, never a guess.
     *
     * The caller refuses the job on null. Were this to fall back to a default
     * the installation would run something on a cadence nobody chose, which is
     * worse than not running it at all.
     */
    public function testRefusesWhatItCannotRead()
    {
        foreach ([
            '',                 // nothing
            '   ',              // whitespace
            '* * * *',          // four fields
            '* * * * * *',      // six fields
            '@yearlyish',       // unknown alias
            '@',                // bare
            'daily',            // alias without the @
            '60 * * * *',       // minute out of range
            '* 24 * * *',       // hour out of range
            '* * 0 * *',        // day-of-month is 1-based
            '* * 32 * *',
            '* * * 0 *',        // month is 1-based
            '* * * 13 *',
            '* * * * 7',        // day-of-week is 0-6
            '5-1 * * * *',      // inverted range
            '*/0 * * * *',      // zero step
            '*/-5 * * * *',
            'a * * * *',
            '* * * * a',
            '1,, * * * *',      // empty list member
            '1- * * * *',
            '- * * * *',
        ] as $bad) {
            $this->assertNull(
                \OWA\Core\Cron::parse($bad),
                var_export($bad, true) . ' should not parse'
            );
        }
    }

    /** Two identical calls agree, and nothing reads a clock. */
    public function testParsingIsPure()
    {
        $a = \OWA\Core\Cron::parse('*/7 3 * * 2');
        $b = \OWA\Core\Cron::parse('*/7 3 * * 2');

        $this->assertSame($a, $b);
    }

    // -----------------------------------------------------------------------
    // Matching
    // -----------------------------------------------------------------------

    public function testMatchesTheMinutesItShould()
    {
        $hourly = $this->parse('0 * * * *');

        $this->assertTrue(\OWA\Core\Cron::matches($hourly, $this->at('2026-08-17 14:00:00'), self::TZ));
        $this->assertFalse(\OWA\Core\Cron::matches($hourly, $this->at('2026-08-17 14:01:00'), self::TZ));

        $monthly = $this->parse('@monthly');

        $this->assertTrue(\OWA\Core\Cron::matches($monthly, $this->at('2026-09-01 00:00:00'), self::TZ));
        $this->assertFalse(\OWA\Core\Cron::matches($monthly, $this->at('2026-09-02 00:00:00'), self::TZ));
        $this->assertFalse(\OWA\Core\Cron::matches($monthly, $this->at('2026-09-01 00:01:00'), self::TZ));
    }

    /**
     * Cron's own day-of-month / day-of-week rule, which surprises people:
     * when BOTH are restricted they are OR'd, not AND'd.
     */
    public function testDayOfMonthAndDayOfWeekAreOredWhenBothRestricted()
    {
        // The 1st, and every Monday.
        $both = $this->parse('0 0 1 * 1');

        // 2026-09-01 is a Tuesday -- matches on day-of-month alone.
        $this->assertTrue(\OWA\Core\Cron::matches($both, $this->at('2026-09-01 00:00:00'), self::TZ));

        // 2026-09-07 is a Monday -- matches on day-of-week alone.
        $this->assertTrue(\OWA\Core\Cron::matches($both, $this->at('2026-09-07 00:00:00'), self::TZ));

        // 2026-09-08 is a Tuesday and not the 1st -- neither.
        $this->assertFalse(\OWA\Core\Cron::matches($both, $this->at('2026-09-08 00:00:00'), self::TZ));

        // With only one restricted, it is a plain AND.
        $dom_only = $this->parse('0 0 1 * *');
        $this->assertFalse(\OWA\Core\Cron::matches($dom_only, $this->at('2026-09-07 00:00:00'), self::TZ));
    }

    // -----------------------------------------------------------------------
    // The due test -- the part that proves the design
    // -----------------------------------------------------------------------

    /**
     * Run a simulated stretch of ticks and return the occurrences that fired.
     *
     * This is the scheduler's actual loop: at each tick ask for a due slot, and
     * if there is one, record it as satisfied. Everything below is a statement
     * about the result of this loop rather than about a single call.
     *
     * @return int[] the slots that ran, in order
     */
    private function simulate(array $parsed, string $from, string $to, int $tick, ?int $last_slot = null): array
    {
        $ran  = [];
        $now  = $this->at($from);
        $end  = $this->at($to);

        // Default: treat everything before the window as already satisfied, so
        // the result counts occurrences INSIDE the window. Passing 0 explicitly
        // means "never run", which correctly fires once at the first tick for
        // the most recent occurrence -- even one that precedes the window.
        $last_slot = $last_slot ?? ($now - 60);

        for (; $now <= $end; $now += $tick) {
            $slot = \OWA\Core\Cron::dueSlot($parsed, $last_slot, $now, self::TZ);

            if ($slot !== null) {
                $ran[]     = $slot;
                $last_slot = $slot;
            }
        }

        return $ran;
    }

    private function readable(array $slots): array
    {
        return array_map(
            fn($t) => (new DateTimeImmutable('@' . $t))
                ->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i'),
            $slots
        );
    }

    /**
     * A month of one-minute ticks fires each occurrence exactly once.
     *
     * The single most important test here. It is also the one that would catch
     * anyone "simplifying" dueSlot() back into a match-this-minute check, which
     * would pass every single-call test and fail this.
     */
    public function testEachOccurrenceFiresExactlyOnce()
    {
        $daily = $this->parse('0 4 * * *');

        $ran = $this->simulate($daily, '2026-09-01 00:00:00', '2026-09-30 23:59:00', 60);

        $this->assertCount(30, $ran, 'one run per day in September');
        $this->assertSame($ran, array_unique($ran), 'no occurrence fired twice');
        $this->assertSame('2026-09-01 04:00', $this->readable($ran)[0]);
        $this->assertSame('2026-09-30 04:00', $this->readable($ran)[29]);
    }

    /**
     * The result does not depend on how often the dispatcher is invoked.
     *
     * This is what makes the crontab line a resolution floor rather than part
     * of the schedule, and what would break immediately under an
     * edge-triggered design.
     */
    public function testResultIsIndependentOfTickInterval()
    {
        $daily = $this->parse('0 4 * * *');

        $one   = $this->simulate($daily, '2026-09-01 00:00:00', '2026-09-10 23:59:00', 60);
        $five  = $this->simulate($daily, '2026-09-01 00:00:00', '2026-09-10 23:59:00', 300);
        $fifty = $this->simulate($daily, '2026-09-01 00:00:00', '2026-09-10 23:59:00', 900);

        $this->assertCount(10, $one);
        $this->assertSame($one, $five, '5-minute ticks give the same occurrences');
        $this->assertSame($one, $fifty, '15-minute ticks give the same occurrences');
    }

    /**
     * A job missed for days runs ONCE when the dispatcher returns, not once per
     * missed occurrence. This is the difference from Laravel, and the reason
     * a scheduled job must be convergent.
     */
    public function testAMissedJobCatchesUpExactlyOnce()
    {
        $daily = $this->parse('0 4 * * *');

        // Last satisfied ten days ago; the dispatcher comes back on the 15th.
        $last = $this->at('2026-09-05 04:00:00');
        $ran  = $this->simulate($daily, '2026-09-15 09:00:00', '2026-09-15 23:59:00', 60, $last);

        $this->assertCount(1, $ran, 'ten missed days must produce one run, not ten');
        $this->assertSame('2026-09-15 04:00', $this->readable($ran)[0]);
    }

    /** A monthly job neglected for half a year likewise runs once. */
    public function testALongNeglectedMonthlyJobRunsOnce()
    {
        $monthly = $this->parse('@monthly');

        $last = $this->at('2026-03-01 00:00:00');
        $ran  = $this->simulate($monthly, '2026-09-15 00:00:00', '2026-09-16 00:00:00', 60, $last);

        $this->assertCount(1, $ran);
        $this->assertSame('2026-09-01 00:00', $this->readable($ran)[0], 'the most recent occurrence, not the oldest missed one');
    }

    /** Never run before: due at the first tick, for every shape of schedule. */
    public function testANeverRunJobIsDueImmediately()
    {
        foreach (['@hourly', '@daily', '@weekly', '@monthly', '*/5 * * * *'] as $expr) {
            $ran = $this->simulate($this->parse($expr), '2026-09-15 13:07:00', '2026-09-15 13:08:00', 60, 0);

            $this->assertCount(1, $ran, "$expr should fire exactly once, not once per missed occurrence");

            $this->assertNotEmpty($ran, "$expr should be due when it has never run");
        }
    }

    /** Within one occurrence, repeated ticks change nothing. */
    public function testIsIdempotentWithinAnOccurrence()
    {
        $monthly = $this->parse('@monthly');

        $ran = $this->simulate($monthly, '2026-09-01 00:00:00', '2026-09-01 23:59:00', 60);

        $this->assertCount(1, $ran, '1440 ticks inside one monthly occurrence run it once');
    }

    // -----------------------------------------------------------------------
    // Daylight saving -- the two failures Laravel's docs warn about
    // -----------------------------------------------------------------------

    /**
     * Autumn: 01:00-02:00 happens twice on 2026-11-01 in America/Los_Angeles.
     * A job scheduled inside it must not run twice.
     */
    public function testTheRepeatedHourDoesNotRunTwice()
    {
        $ran = $this->simulate($this->parse('30 1 * * *'), '2026-11-01 00:00:00', '2026-11-01 23:59:00', 60);

        $this->assertCount(1, $ran, '01:30 exists twice on this date but is one occurrence');
    }

    /**
     * Spring: 02:00-03:00 does not exist on 2026-03-08. A job scheduled inside
     * the missing hour must still run that day rather than being skipped.
     */
    public function testTheSkippedHourStillRunsOnce()
    {
        $ran = $this->simulate($this->parse('30 2 * * *'), '2026-03-08 00:00:00', '2026-03-08 23:59:00', 60);

        $this->assertCount(1, $ran, '02:30 does not exist on this date, but the day must not be skipped');
    }

    /** Across either transition, a daily job still runs once per calendar day. */
    public function testDailyJobsRunOncePerDayAcrossBothTransitions()
    {
        $daily = $this->parse('0 4 * * *');

        $spring = $this->simulate($daily, '2026-03-06 00:00:00', '2026-03-10 23:59:00', 60);
        $this->assertCount(5, $spring, 'five calendar days across spring forward');

        $autumn = $this->simulate($daily, '2026-10-30 00:00:00', '2026-11-03 23:59:00', 60);
        $this->assertCount(5, $autumn, 'five calendar days across fall back');
    }

    // -----------------------------------------------------------------------
    // Calendar edges
    // -----------------------------------------------------------------------

    /** Month lengths, including February in a leap and a non-leap year. */
    public function testHandlesMonthLengths()
    {
        $daily = $this->parse('0 12 * * *');

        foreach ([
            ['2026-02-01', '2026-02-28', 28],   // 2026 is not a leap year
            ['2028-02-01', '2028-02-29', 29],   // 2028 is
            ['2026-04-01', '2026-04-30', 30],
            ['2026-07-01', '2026-07-31', 31],
        ] as [$from, $to, $days]) {
            $ran = $this->simulate($daily, $from . ' 00:00:00', $to . ' 23:59:00', 3600);

            $this->assertCount($days, $ran, "$from to $to");
        }
    }

    /**
     * A day-of-month that some months do not have simply does not fire in those
     * months — cron's behaviour, and the reason the plan warns against 29-31.
     */
    public function testADayOfMonthBeyondTheMonthLengthIsSkipped()
    {
        $ran = $this->simulate($this->parse('0 0 31 * *'), '2026-01-01 00:00:00', '2026-06-30 23:59:00', 3600);

        // Jan, Mar, May have a 31st in that window; Feb, Apr, Jun do not.
        $this->assertSame(
            ['2026-01-31 00:00', '2026-03-31 00:00', '2026-05-31 00:00'],
            $this->readable($ran)
        );
    }

    /** Weekly across a year boundary. */
    public function testWeeklyAcrossAYearBoundary()
    {
        $ran = $this->simulate($this->parse('@weekly'), '2026-12-20 00:00:00', '2027-01-17 23:59:00', 3600);

        $this->assertSame(
            ['2026-12-20 00:00', '2026-12-27 00:00', '2027-01-03 00:00', '2027-01-10 00:00', '2027-01-17 00:00'],
            $this->readable($ran)
        );
    }

    // -----------------------------------------------------------------------
    // Reporting helpers
    // -----------------------------------------------------------------------

    public function testNextAfterFindsTheFollowingOccurrence()
    {
        $monthly = $this->parse('@monthly');

        $this->assertSame(
            $this->at('2026-10-01 00:00:00'),
            \OWA\Core\Cron::nextAfter($monthly, $this->at('2026-09-15 12:00:00'), self::TZ)
        );

        // Exactly on an occurrence, "after" means strictly after.
        $this->assertSame(
            $this->at('2026-10-01 00:00:00'),
            \OWA\Core\Cron::nextAfter($monthly, $this->at('2026-09-01 00:00:00'), self::TZ)
        );
    }

    public function testDescribesCommonSchedulesInWords()
    {
        foreach ([
            '@monthly'      => 'monthly, on the 1st at 00:00',
            '@daily'        => 'daily at 00:00',
            '@hourly'       => 'hourly, on the hour',
            '*/5 * * * *'   => 'every 5 minutes',
            '10 * * * *'    => 'hourly, at 10 past',
            '0 4 * * *'     => 'daily at 04:00',
            '30 2 1 * *'    => 'monthly, on day 1 at 02:30',
        ] as $expr => $words) {
            $this->assertSame($words, \OWA\Core\Cron::describe($expr), $expr);
        }

        // Anything unusual is shown as itself rather than mis-described.
        $this->assertSame('0 4 * * 1-5', \OWA\Core\Cron::describe('0 4 * * 1-5'));
    }

    /** An unusable timezone yields no match rather than an exception. */
    public function testAnUnusableTimezoneDoesNotThrow()
    {
        $parsed = $this->parse('@daily');

        $this->assertFalse(\OWA\Core\Cron::matches($parsed, 1789000000, 'Not/AZone'));
        $this->assertNull(\OWA\Core\Cron::previousMatch($parsed, 1789000000, 'Not/AZone'));
        $this->assertNull(\OWA\Core\Cron::nextAfter($parsed, 1789000000, 'Not/AZone'));
    }

    /**
     * `@daily` is midnight exactly, so a job that calls a third-party API on it
     * makes every install request at the same instant -- and most servers keep
     * UTC, so timezones do not even spread it.
     */
    public function testDailySpreadGivesDifferentInstallsDifferentTimes(): void
    {
        $a = \OWA\Core\Cron::dailySpreadFor( 'https://a.example/owa/' );
        $b = \OWA\Core\Cron::dailySpreadFor( 'https://b.example/owa/' );

        $this->assertNotSame( $a, $b );
        $this->assertNotSame( '0 0 * * *', $a, 'the whole point is not to be midnight' );
    }

    /**
     * ...and the SAME install keeps its time. The scheduler decides due-ness by
     * comparing the last satisfied occurrence against the expression, so an
     * expression that moved between runs would leave a job firing repeatedly or
     * never being due again.
     */
    public function testDailySpreadIsStableForOneInstall(): void
    {
        $this->assertSame(
            \OWA\Core\Cron::dailySpreadFor( 'https://a.example/owa/' ),
            \OWA\Core\Cron::dailySpreadFor( 'https://a.example/owa/' ) );
    }

    /** It must parse as an ordinary expression, not a special case. */
    public function testDailySpreadIsAValidExpression(): void
    {
        $parsed = \OWA\Core\Cron::parse( \OWA\Core\Cron::dailySpreadFor( 'seed' ) );

        $this->assertIsArray( $parsed );
    }

    /** The two fields must not be correlated, or the times collapse. */
    public function testTheSpreadActuallySpreads(): void
    {
        $seen = array();

        for ( $i = 0; $i < 500; $i++ ) {
            $seen[ \OWA\Core\Cron::dailySpreadFor( "install-$i" ) ] = true;
        }

        $this->assertGreaterThan( 300, count( $seen ),
            '500 installs landing on few times would still be a spike' );
    }
}
