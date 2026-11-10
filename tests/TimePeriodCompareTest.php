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
        $cases = array();

        foreach ( $json['cases'] as $c ) {

            $cases[ "{$c['key']} {$c['start']}-{$c['end']}" ] = array( $c['key'], $c['start'], $c['end'], $c['want'] );
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

    public function testACompareRangeIsAsLongAsThePeriodExceptAcrossAFebruary29(): void
    {
        foreach ( array_keys( TimePeriod::COMPARE_PERIODS ) as $key ) {

            foreach ( array( array( '20261013', '20261109' ), array( '20260301', '20260331' ), array( '20261109', '20261109' ) ) as [ $s, $e ] ) {

                [ $cs, $ce ] = TimePeriod::compareRange( $key, $s, $e );

                $len = fn ( $a, $b ) => (int) ( new DateTimeImmutable( $a ) )->diff( new DateTimeImmutable( $b ) )->days;

                $this->assertSame( $len( $s, $e ), $len( $cs, $ce ), "$key $s-$e" );
                $this->assertLessThan( $s, $cs, "$key must be earlier than the period" );
            }
        }
    }

    public function testMatchDayOfWeekKeepsTheWeekday(): void
    {
        foreach ( array( 'previous_period_dow', 'previous_year_dow' ) as $key ) {

            foreach ( array( array( '20261011', '20261109' ), array( '20260101', '20260103' ), array( '20261109', '20261109' ) ) as [ $s, $e ] ) {

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
        return array(
            'none'                   => array( array(), '' ),
            'relative'               => array( array( 'compare' => 'previous_year' ), '' ),
            'custom'                 => array( array( 'compare' => 'date_range', 'compareStartDate' => '20250101', 'compareEndDate' => '20250131' ), '' ),
            'dates without compare'  => array( array( 'compareStartDate' => '20250101', 'compareEndDate' => '20250131' ), '' ),
            'unknown comparison'     => array( array( 'compare' => 'same_month_last_year' ), 'is not a comparison' ),
            'custom without dates'   => array( array( 'compare' => 'date_range' ), 'Compared to: A date range needs a start date and an end date.' ),
            'one compare date'       => array( array( 'compareStartDate' => '20250101' ), 'Compared to: A date range needs an end date' ),
            'backwards'              => array( array( 'compare' => 'date_range', 'compareStartDate' => '20250131', 'compareEndDate' => '20250101' ), 'is after the end date' ),
            'not a date'             => array( array( 'compare' => 'date_range', 'compareStartDate' => '2025-01-01', 'compareEndDate' => '20250131' ), 'is not a date' ),
        );
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
        $p->set( 'date_range', array( 'startDate' => '20261013', 'endDate' => '20261109' ) );

        $this->assertNull( $p->getCompareFromMap( array() ) );
        $this->assertNull( $p->getCompareFromMap( array( 'compare' => 'nonsense' ) ) );

        $this->assertSame(
            array( 'compare' => 'previous_period_dow', 'startDate' => '20260915', 'endDate' => '20261012' ),
            $p->getCompareFromMap( array( 'compare' => 'previous_period_dow' ) ) );

        $this->assertSame(
            array( 'compare' => 'date_range', 'startDate' => '20250101', 'endDate' => '20250131' ),
            $p->getCompareFromMap( array( 'compareStartDate' => '20250101', 'compareEndDate' => '20250131' ) ) );
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

    public function testARetiredDefaultFallsBackToLastSevenDays(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting( 'base', 'default_reporting_period' );

        try {

            foreach ( array( 'same_month_last_year', 'nonsense', 'date_range' ) as $bad ) {

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

        $want = array(
            'today'            => array( '20261109', '20261109' ),
            'yesterday'        => array( '20261108', '20261108' ),
            'this_week'        => array( '20261108', '20261109' ),
            'last_seven_days'  => array( '20261103', '20261109' ),
            'last_week'        => array( '20261101', '20261107' ),
            'last_28_days'     => array( '20261013', '20261109' ),
            'last_thirty_days' => array( '20261011', '20261109' ),
            'this_month'       => array( '20261101', '20261109' ),
            'last_month'       => array( '20261001', '20261031' ),
            'last_90_days'     => array( '20260812', '20261109' ),
            'quarter_to_date'  => array( '20261001', '20261109' ),
            'this_year'        => array( '20260101', '20261109' ),
            'last_year'        => array( '20250101', '20251231' ),
        );

        foreach ( $want as $key => [ $s, $e ] ) {

            $this->assertSame( array( $s, $e ), array( $byKey[ $key ]['startDate'], $byKey[ $key ]['endDate'] ), $key );
        }
    }

    public function testThePickerConfigIsBuiltFromTheServersLists(): void
    {
        $p = new TimePeriod();
        $p->set( 'last_28_days' );

        $c = \OWA\Core\ReportController::periodPickerConfig( $p, array( 'compare' => 'previous_year' ) );

        $this->assertSame( array_keys( $p->getPeriodLabels() ), array_column( $c['presets'], 'key' ) );
        $this->assertSame( array_keys( TimePeriod::COMPARE_PERIODS ), array_column( $c['compares'], 'key' ) );
        $this->assertSame( 'last_28_days', $c['period'] );
        $this->assertSame( 'previous_year', $c['compare'] );
        $this->assertSame( TimePeriod::compareRange( 'previous_year', $c['startDate'], $c['endDate'] ),
            array( $c['compareStartDate'], $c['compareEndDate'] ) );
        $this->assertSame( $c['presets'][0]['endDate'], $c['today'] );
    }

    public function testComparisonIsCarriedOnReportLinks(): void
    {
        foreach ( array( 'compare', 'compareStartDate', 'compareEndDate' ) as $key ) {

            $this->assertContains( $key, \OWA\Core\View::LINK_STATE_KEYS );
        }
    }
}
