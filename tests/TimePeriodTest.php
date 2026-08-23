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

    /**
     * Run $fn with a warning collector on top of the handler stack, and return
     * every diagnostic PHP raised while it ran.
     *
     * OWA's bootstrap installs its own error handler, which logs a warning and
     * returns -- so warnings are invisible when this suite runs against a real
     * install, and are failures under CI, which is exactly the asymmetry that
     * let the defect below reach master. Collecting them explicitly makes the
     * assertion mean the same thing in both places.
     */
    private function diagnosticsDuring(callable $fn): array
    {
        $seen = [];

        set_error_handler(
            function ( $no, $str, $file, $line ) use ( &$seen ) {
                $seen[] = sprintf( '%s (%s:%d)', $str, basename( (string) $file ), $line );
                return true;
            }
        );

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $seen;
    }

    /**
     * Positive control for the collector above.
     *
     * Without this, every assertion of "raised no warnings" would pass just as
     * happily if the collector were wired up wrong and saw nothing at all.
     */
    public function testTheWarningCollectorActuallySeesWarnings()
    {
        $seen = $this->diagnosticsDuring(
            function () {
                $empty = [];
                /** @phpstan-ignore-next-line deliberate: proving the collector fires */
                $x = @$empty['definitely_not_there'];
                $y = $empty['definitely_not_there'];
            }
        );

        $this->assertNotEmpty( $seen, 'the collector must be able to observe a warning, or every use of it is vacuous' );
        $this->assertStringContainsString( 'definitely_not_there', $seen[0] );
    }

    public static function partialMapProvider(): array
    {
        // Each omits at least one key the period machinery reads. None of these
        // is exotic: 'date_range' is a valid period, so ?period=date_range with
        // a half-filled or missing range arrives from an ordinary edited URL.
        return [
            'no map at all'          => [ [] ],
            'date_range, no dates'   => [ [ 'period' => 'date_range' ] ],
            'date_range, start only' => [ [ 'period' => 'date_range', 'startDate' => '20260801' ] ],
            'date_range, end only'   => [ [ 'period' => 'date_range', 'endDate'   => '20260810' ] ],
            'start date, no period'  => [ [ 'startDate' => '20260801' ] ],
            'end date, no period'    => [ [ 'endDate'   => '20260810' ] ],
            'named period, no dates' => [ [ 'period' => 'last_seven_days' ] ],
            'unknown period alone'   => [ [ 'period' => 'not_a_real_period' ] ],
        ];
    }

    /**
     * A map that omits keys must not raise diagnostics.
     *
     * setFromMap() opens by building a defaults array and then discarding it --
     * array_intersect_key() used it as a key filter only -- so any key the
     * caller left out stayed out, and the switch below read it anyway.
     *
     * @dataProvider partialMapProvider
     */
    public function testAPartialMapRaisesNoDiagnostics( array $map )
    {
        $period = null;

        $seen = $this->diagnosticsDuring(
            function () use ( $map, &$period ) {
                $period = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );
                $period->setFromMap( $map );
            }
        );

        $this->assertSame( [], $seen, "a partial map raised: " . implode( '; ', $seen ) );
        $this->assertNotSame( '', (string) $period->get(), 'a partial map should still resolve to some period' );
    }

    /**
     * set() never passes through setFromMap, so it needs its own normalization.
     *
     * 'day' and 'time_range' are not offered by the picker and are not valid
     * period names, so setFromMap can never produce them -- set() is the only
     * way in, and it is the path ReportVisitorsRoster used.
     *
     * @dataProvider bypassProvider
     */
    public function testSetWithNoMapRaisesNoDiagnostics( string $name )
    {
        $period = null;

        $seen = $this->diagnosticsDuring(
            function () use ( $name, &$period ) {
                $period = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );
                $period->set( $name, [] );
            }
        );

        $this->assertSame( [], $seen, "set('$name') raised: " . implode( '; ', $seen ) );
        $this->assertInstanceOf( \OWA\Module\Base\Classes\Date::class, $period->getStartDate() );
    }

    public static function bypassProvider(): array
    {
        return [ 'day' => [ 'day' ], 'time_range' => [ 'time_range' ], 'date_range' => [ 'date_range' ], 'today' => [ 'today' ] ];
    }

    /**
     * Normalizing must not have changed what a well-formed map resolves to.
     * This is the regression half: the guard is only worth having if the
     * ordinary cases still land on exactly the same dates.
     */
    public function testAWellFormedMapIsUnaffectedByNormalization()
    {
        $this->assertSame(
            [ '2026-08-01', '2026-08-10' ],
            $this->span( $this->period( 'date_range', [ 'startDate' => '20260801', 'endDate' => '20260810' ] ) ),
            'an explicit range must survive normalization intact'
        );

        $this->assertSame(
            [ date( 'Y-m-d' ), date( 'Y-m-d' ) ],
            $this->span( $this->period( 'today' ) ),
            'a named period must survive normalization intact'
        );
    }

    /**
     * The filtering half of the original line still has to work: keys this
     * class does not own are dropped rather than carried into the date logic.
     */
    public function testForeignKeysAreDiscarded()
    {
        $seen = $this->diagnosticsDuring(
            function () {
                $p = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );
                $p->setFromMap( [ 'period' => 'today', 'siteId' => 'x', 'do' => 'y', 'startDate' => '20260801' ] );
                $this->assertSame( 'today', $p->get() );
                // 'today' wins over the stray startDate, as it did before.
                $this->assertSame( date( 'Y-m-d' ), date( 'Y-m-d', $p->getStartDate()->getTimestamp() ) );
            }
        );

        $this->assertSame( [], $seen );
    }

    /*
     * ---------------------------------------------------------------------
     * Date ranges have to be complete and ordered.
     *
     * A range is both bounds or neither. One bound alone was not refused: the
     * missing one resolved to today, so ?endDate=20260810 asked for "up to the
     * 10th" and got a window running from today BACKWARDS to the 10th. The
     * report then rendered that inverted window without complaint.
     *
     * getRangeError() is static and touches no settings, so it holds without a
     * database -- which is what lets both the web and REST paths ask before
     * building anything.
     * ---------------------------------------------------------------------
     */

    public static function unusableRangeProvider(): array
    {
        return [
            // The case this exists for.
            'end alone'              => [ [ 'endDate' => '20260810' ], 'needs a start date' ],
            'end alone, named'       => [ [ 'period' => 'date_range', 'endDate' => '20260810' ], 'needs a start date' ],

            // Its mirror: one bound is not a range in either direction.
            'start alone'            => [ [ 'startDate' => '20260801' ], 'needs an end date' ],
            'start alone, named'     => [ [ 'period' => 'date_range', 'startDate' => '20260801' ], 'needs an end date' ],

            'no bounds at all'       => [ [ 'period' => 'date_range' ], 'needs a start date and an end date' ],

            'start after end'        => [ [ 'startDate' => '20260810', 'endDate' => '20260801' ], 'is after the end date' ],
            'start after end, named' => [ [ 'period' => 'date_range', 'startDate' => '20261231', 'endDate' => '20260101' ], 'is after the end date' ],

            // Ordering compares yyyymmdd as strings, which only agrees with
            // chronological order for eight digits -- so anything else has to
            // be refused before the comparison, not by it.
            'start not a date'       => [ [ 'startDate' => 'aug1', 'endDate' => '20260810' ], 'is not a date' ],
            'end not a date'         => [ [ 'startDate' => '20260801', 'endDate' => '2026-08-10' ], 'is not a date' ],
            'short date'             => [ [ 'startDate' => '202608', 'endDate' => '20260810' ], 'is not a date' ],
        ];
    }

    /** @dataProvider unusableRangeProvider */
    public function testAnUnusableRangeIsRefused( array $map, string $because )
    {
        $error = \OWA\Module\Base\Classes\TimePeriod::getRangeError( $map );

        $this->assertNotSame( '', $error, 'this map should not have been accepted' );
        $this->assertStringContainsString( $because, $error,
            'the reason given must be the actual defect, since it is shown to the user' );
    }

    public static function usableRangeProvider(): array
    {
        return [
            'ordered range'          => [ [ 'startDate' => '20260801', 'endDate' => '20260810' ] ],
            'ordered range, named'   => [ [ 'period' => 'date_range', 'startDate' => '20260801', 'endDate' => '20260810' ] ],

            // Equal bounds are a single day, which is how ReportVisitorsRoster
            // asks for one. The rule is start <= end, not start < end.
            'single day'             => [ [ 'period' => 'date_range', 'startDate' => '20260801', 'endDate' => '20260801' ] ],
            'single day, no period'  => [ [ 'startDate' => '20260801', 'endDate' => '20260801' ] ],

            'a named period'         => [ [ 'period' => 'today' ] ],
            'nothing at all'         => [ [] ],

            // A named relative period wins over any dates sent with it, so a
            // partial range alongside one is ignored rather than refused --
            // refusing would reject a parameter that has no effect.
            'named period + partial' => [ [ 'period' => 'today', 'startDate' => '20260801' ] ],
            'named period + strays'  => [ [ 'period' => 'last_week', 'endDate' => '20260810' ] ],
        ];
    }

    /** @dataProvider usableRangeProvider */
    public function testAUsableRangeIsAccepted( array $map )
    {
        $this->assertSame( '', \OWA\Module\Base\Classes\TimePeriod::getRangeError( $map ),
            'this map describes a usable request and must not be refused' );
    }

    /**
     * The defect stated directly: an end date alone produced a window whose
     * start was AFTER its end, and nothing objected.
     */
    public function testTheOldEndOnlyBehaviourWasAnInvertedWindow()
    {
        $p = $this->period( 'date_range', [ 'endDate' => '20260810' ] );

        $this->assertGreaterThan(
            (int) $p->getEndDate()->get( 'yyyymmdd' ),
            (int) $p->getStartDate()->get( 'yyyymmdd' ),
            'if this is no longer inverted the model changed, and the guard above '
            . 'is now the only thing describing the rule' );

        $this->assertNotSame( '',
            \OWA\Module\Base\Classes\TimePeriod::getRangeError( [ 'period' => 'date_range', 'endDate' => '20260810' ] ),
            'and that is exactly the shape the rule has to catch' );
    }
}
