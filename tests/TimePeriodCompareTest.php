<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TimePeriod;

/**
 * The comparison period, the retired periods, and what the picker is given.
 */
final class TimePeriodCompareTest extends TestCase
{
    public static function fixtureCases(): array
    {
        $json  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/compare-ranges.json' ), true );
        $cases = [];

        foreach ( $json['cases'] as $c ) {

            $cases[ "{$c['key']} {$c['start']}-{$c['end']}" ] = [ $c['key'], $c['start'], $c['end'], $c['want'] ];
        }

        return $cases;
    }

    /**
     * @dataProvider fixtureCases
     */
    public function testCompareRangeMatchesTheSharedFixture( string $key, string $start, string $end, array $want ): void
    {
        $this->assertSame( $want, TimePeriod::compareRange( $key, $start, $end ) );
    }

    /**
     * Whole calendar months are the exception: their previous period is the
     * months before, as long as those are (testWholeMonthsStepBackWholeMonths).
     */
    public function testACompareRangeIsAsLongAsThePeriodExceptAcrossAFebruary29(): void
    {
        foreach ( array_keys( TimePeriod::COMPARE_PERIODS ) as $key ) {

            foreach ( [ [ '20261013', '20261109' ], [ '20260302', '20260331' ], [ '20261109', '20261109' ] ] as [ $s, $e ] ) {

                [ $cs, $ce ] = TimePeriod::compareRange( $key, $s, $e );

                $len = fn ( $a, $b ) => (int) ( new DateTimeImmutable( $a ) )->diff( new DateTimeImmutable( $b ) )->days;

                $this->assertSame( $len( $s, $e ), $len( $cs, $ce ), "$key $s-$e" );
                $this->assertLessThan( $s, $cs, "$key must be earlier than the period" );
            }
        }
    }

    /**
     * A month, a quarter or a year against the one before it, whatever their
     * lengths: November against October, March against February.
     */
    public function testWholeMonthsStepBackWholeMonths(): void
    {
        $cases = [
            [ '20261101', '20261130', '20261001', '20261031' ],
            [ '20260301', '20260331', '20260201', '20260228' ],
            [ '20261001', '20261231', '20260701', '20260930' ],
            [ '20260101', '20261231', '20250101', '20251231' ],
            [ '20260101', '20260131', '20251201', '20251231' ],
        ];

        foreach ( $cases as [ $s, $e, $cs, $ce ] ) {

            $this->assertSame( [ $cs, $ce ], TimePeriod::compareRange( 'previous_period', $s, $e ), "$s-$e" );
        }

        // Not ending on a month's last day: the days before.
        $this->assertSame( [ '20261017', '20261031' ], TimePeriod::compareRange( 'previous_period', '20261101', '20261115' ) );
    }

    /**
     * "This ..." periods are compared over their whole week, month, quarter
     * or year, however much of it has gone: on a Sunday, the whole week
     * before; on the 15th, the whole month before.
     */
    public function testAThisPeriodIsComparedToTheWholePeriodBefore(): void
    {
        $cases = [
            [ 'this_week',       '2026-11-15', '20261108', '20261114' ],   // a Sunday
            [ 'this_week',       '2026-11-18', '20261108', '20261114' ],   // a Wednesday
            [ 'this_month',      '2026-11-01', '20261001', '20261031' ],
            [ 'this_month',      '2026-11-15', '20261001', '20261031' ],
            [ 'quarter_to_date', '2026-11-15', '20260701', '20260930' ],
            [ 'this_year',       '2026-11-15', '20250101', '20251231' ],
            [ 'today',           '2026-11-15', '20261114', '20261114' ],
            [ 'last_seven_days', '2026-11-15', '20261102', '20261108' ],
        ];

        foreach ( $cases as [ $period, $today, $cs, $ce ] ) {

            $tp      = new TimePeriod();
            $tp->now = ( new DateTimeImmutable( $today . ' 08:15' ) )->getTimestamp();
            $tp->set( $period );

            $this->assertSame(
                [ 'compare' => 'previous_period', 'startDate' => $cs, 'endDate' => $ce ],
                $tp->getCompareFromMap( [ 'compare' => 'previous_period' ] ),
                "$period on $today" );
        }

        // The picker previews the same range from each preset's axisEndDate.
        $tp      = new TimePeriod();
        $tp->now = ( new DateTimeImmutable( '2026-11-15 08:15' ) )->getTimestamp();

        $week = array_values( array_filter( $tp->getPresets(), fn ( $p ) => $p['key'] === 'this_week' ) )[0];

        $this->assertSame( [ '20261115', '20261115', '20261121' ], [ $week['startDate'], $week['endDate'], $week['axisEndDate'] ] );
    }

    public function testMatchDayOfWeekKeepsTheWeekday(): void
    {
        foreach ( [ 'previous_period_dow', 'previous_year_dow' ] as $key ) {

            foreach ( [ [ '20261011', '20261109' ], [ '20260101', '20260103' ], [ '20261109', '20261109' ] ] as [ $s, $e ] ) {

                [ $cs ] = TimePeriod::compareRange( $key, $s, $e );

                $this->assertSame( ( new DateTimeImmutable( $s ) )->format( 'w' ), ( new DateTimeImmutable( $cs ) )->format( 'w' ), "$key $s" );
            }
        }
    }

    public function testAnUnknownComparisonOrBadDateGivesNoRange(): void
    {
        $this->assertNull( TimePeriod::compareRange( 'same_month_last_year', '20261001', '20261031' ) );
        $this->assertNull( TimePeriod::compareRange( 'previous_period', '2026-10-01', '20261031' ) );
    }

    public static function compareParams(): array
    {
        return [
            'none'                   => [ [], '' ],
            'relative'               => [ [ 'compare' => 'previous_year' ], '' ],
            'custom'                 => [ [ 'compare' => 'date_range', 'compareStartDate' => '20250101', 'compareEndDate' => '20250131' ], '' ],
            'dates without compare'  => [ [ 'compareStartDate' => '20250101', 'compareEndDate' => '20250131' ], '' ],
            'unknown comparison'     => [ [ 'compare' => 'same_month_last_year' ], 'is not a comparison' ],
            'custom without dates'   => [ [ 'compare' => 'date_range' ], 'Compared to: A date range needs a start date and an end date.' ],
            'one compare date'       => [ [ 'compareStartDate' => '20250101' ], 'Compared to: A date range needs an end date' ],
            'backwards'              => [ [ 'compare' => 'date_range', 'compareStartDate' => '20250131', 'compareEndDate' => '20250101' ], 'is after the end date' ],
            'not a date'             => [ [ 'compare' => 'date_range', 'compareStartDate' => '2025-01-01', 'compareEndDate' => '20250131' ], 'is not a date' ],
        ];
    }

    /**
     * @dataProvider compareParams
     */
    public function testCompareParametersAreValidated( array $map, string $error ): void
    {
        $got = TimePeriod::getCompareError( $map );

        if ( $error === '' ) {

            $this->assertSame( '', $got );

        } else {

            $this->assertStringContainsString( $error, $got );
        }
    }

    public function testTheCompareIsResolvedAgainstThePeriod(): void
    {
        $p = new TimePeriod();
        $p->set( 'date_range', [ 'startDate' => '20261013', 'endDate' => '20261109' ] );

        $this->assertNull( $p->getCompareFromMap( [] ) );
        $this->assertNull( $p->getCompareFromMap( [ 'compare' => 'nonsense' ] ) );

        $this->assertSame(
            [ 'compare' => 'previous_period_dow', 'startDate' => '20260915', 'endDate' => '20261012' ],
            $p->getCompareFromMap( [ 'compare' => 'previous_period_dow' ] ) );

        $this->assertSame(
            [ 'compare' => 'date_range', 'startDate' => '20250101', 'endDate' => '20250131' ],
            $p->getCompareFromMap( [ 'compareStartDate' => '20250101', 'compareEndDate' => '20250131' ] ) );
    }

    public function testRetiredPeriodsAreNotOfferedOrAccepted(): void
    {
        $p = new TimePeriod();

        foreach ( TimePeriod::RETIRED_PERIODS as $retired ) {

            $this->assertTrue( TimePeriod::isRetired( $retired ) );
            $this->assertFalse( $p->isValid( $retired ), "$retired is still accepted" );
            $this->assertArrayNotHasKey( $retired, $p->getPeriodLabels() );
        }

        $this->assertFalse( TimePeriod::isRetired( 'last_seven_days' ) );
    }

    /**
     * @covers-setting base.default_reporting_period
     */
    public function testARetiredDefaultFallsBackToLastSevenDays(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting( 'base', 'default_reporting_period' );

        try {

            foreach ( [ 'same_month_last_year', 'nonsense', 'date_range' ] as $bad ) {

                \OWA\Core\CoreAPI::setSetting( 'base', 'default_reporting_period', $bad );

                $this->assertSame( TimePeriod::FALLBACK_PERIOD, ( new TimePeriod() )->getDefaultReportingPeriod(), $bad );
            }

            \OWA\Core\CoreAPI::setSetting( 'base', 'default_reporting_period', 'last_28_days' );
            $this->assertSame( 'last_28_days', ( new TimePeriod() )->getDefaultReportingPeriod() );

        } finally {

            \OWA\Core\CoreAPI::setSetting( 'base', 'default_reporting_period', $was );
        }
    }

    /**
     * The picker is given every preset with the dates it covers now, so it can
     * show them before anything is applied.
     */
    public function testThePresetsCarryTheirDatesInPickerOrder(): void
    {
        $p = new TimePeriod();
        $p->now = ( new DateTimeImmutable() )->setDate( 2026, 11, 9 )->setTime( 12, 0 )->getTimestamp();

        $presets = $p->getPresets();

        $this->assertSame( array_keys( $p->getPeriodLabels() ), array_column( $presets, 'key' ) );

        $byKey = array_column( $presets, null, 'key' );

        $want = [
            'today'            => [ '20261109', '20261109' ],
            'yesterday'        => [ '20261108', '20261108' ],
            'this_week'        => [ '20261108', '20261109' ],
            'last_seven_days'  => [ '20261103', '20261109' ],
            'last_week'        => [ '20261101', '20261107' ],
            'last_28_days'     => [ '20261013', '20261109' ],
            'last_thirty_days' => [ '20261011', '20261109' ],
            'this_month'       => [ '20261101', '20261109' ],
            'last_month'       => [ '20261001', '20261031' ],
            'last_90_days'     => [ '20260812', '20261109' ],
            'quarter_to_date'  => [ '20261001', '20261109' ],
            'this_year'        => [ '20260101', '20261109' ],
            'last_year'        => [ '20250101', '20251231' ],
        ];

        foreach ( $want as $key => [ $s, $e ] ) {

            $this->assertSame( [ $s, $e ], [ $byKey[ $key ]['startDate'], $byKey[ $key ]['endDate'] ], $key );
        }
    }

    public function testThePickerConfigIsBuiltFromTheServersLists(): void
    {
        $p = new TimePeriod();
        $p->set( 'last_28_days' );

        $c = \OWA\Core\ReportController::periodPickerConfig( $p, [ 'compare' => 'previous_year' ] );

        $this->assertSame( array_keys( $p->getPeriodLabels() ), array_column( $c['presets'], 'key' ) );
        $this->assertSame( array_keys( TimePeriod::COMPARE_PERIODS ), array_column( $c['compares'], 'key' ) );
        $this->assertSame( 'last_28_days', $c['period'] );
        $this->assertSame( 'previous_year', $c['compare'] );
        $this->assertSame( TimePeriod::compareRange( 'previous_year', $c['startDate'], $c['endDate'] ),
            [ $c['compareStartDate'], $c['compareEndDate'] ] );
        $this->assertSame( $c['presets'][0]['endDate'], $c['today'] );
    }

    public function testComparisonIsCarriedOnReportLinks(): void
    {
        foreach ( [ 'compare', 'compareStartDate', 'compareEndDate' ] as $key ) {

            $this->assertContains( $key, \OWA\Core\View::LINK_STATE_KEYS );
        }
    }
}
