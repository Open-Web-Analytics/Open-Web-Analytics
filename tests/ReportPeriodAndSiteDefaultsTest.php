<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The date picker and the site filter, and the defaults behind them.
 *
 * Every report carries these two controls, and neither is declared by the
 * report -- so when reports become configuration, this is the behaviour a
 * JSON-rendered report has to keep. It is pinned here because the
 * characterization fixture cannot hold it: the answers depend on today's date
 * and on the site list in the database.
 *
 * The entry point that matters is TimePeriod::setFromMap(). It is where the
 * defaults live, and a renderer that reaches for set() instead bypasses all of
 * them -- including the guard that stops an arbitrary URL value reaching the
 * date arithmetic.
 */
final class ReportPeriodAndSiteDefaultsTest extends TestCase
{
    private function period(): object
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );
    }

    private function fromMap( array $map ): object
    {
        $p = $this->period();
        $p->setFromMap( $map );

        return $p;
    }

    /**
     * Every period the class can build, including the ones no menu offers.
     *
     * @return array<string, array{0:string, 1:array}>
     */
    public static function everyPeriodProvider(): array
    {
        $extra = array(
            'date_range' => array( 'startDate' => '20260801', 'endDate' => '20260815' ),
            'time_range' => array( 'startTime' => 1787000000, 'endTime' => 1787200000 ),
            'day'        => array( 'day' => '15', 'month' => '08', 'year' => '2026' ),
        );

        $types = array(
            'today', 'yesterday', 'this_week', 'this_month', 'this_year',
            'last_week', 'last_month', 'last_year', 'last_seven_days',
            'last_thirty_days', 'same_day_last_week', 'same_week_last_year',
            'same_month_last_year', 'last_24_hours', 'last_hour',
            'last_half_hour', 'all_time', 'date_range', 'time_range', 'day',
        );

        $cases = array();

        foreach ( $types as $t ) {
            $cases[ $t ] = array( $t, $extra[ $t ] ?? array() );
        }

        return $cases;
    }

    /**
     * @dataProvider everyPeriodProvider
     */
    public function testEveryPeriodProducesAnOrderedRange( string $type, array $extra ): void
    {
        $p = $this->period();
        $p->set( $type, $extra );

        $start = $p->getStartDate();
        $end   = $p->getEndDate();

        $this->assertIsObject( $start, "$type produced no start date" );
        $this->assertIsObject( $end, "$type produced no end date" );

        $this->assertLessThanOrEqual( $end->getTimestamp(), $start->getTimestamp(),
            "$type runs backwards" );

        // Reports bucket by day, so the day stamps are what a query actually
        // bounds on -- a range whose timestamps order correctly but whose day
        // stamps do not would select nothing.
        $this->assertLessThanOrEqual( (int) $end->get( 'yyyymmdd' ), (int) $start->get( 'yyyymmdd' ),
            "$type has day bounds that run backwards" );

        $this->assertNotEmpty( $p->getLabel(), "$type renders no label for the picker" );
    }

    public function testThePickerOffersOnlyPeriodsThatCanBeBuilt(): void
    {
        $p = $this->period();

        foreach ( array_keys( $p->getPeriodLabels() ) as $label ) {

            $built = $this->period();
            $built->set( $label );

            $this->assertIsObject( $built->getStartDate(),
                "the picker offers \"$label\" but it does not build" );
        }
    }

    /**
     * Six periods are implemented and refused.
     *
     * _setDates() builds last_24_hours, last_hour, last_half_hour, all_time,
     * time_range and day, but isValid() is derived from the picker's label list
     * plus date_range -- so asking for any of them through the normal path gets
     * the DEFAULT reporting period instead, and says so only in a debug line
     * that is off unless debugging is on.
     *
     * Pinned rather than fixed: whether those six should be reachable is a
     * decision, and this records which way it currently falls so a change to
     * either list is visible instead of silent.
     */
    public function testImplementedButUnofferedPeriodsSilentlyBecomeTheDefault(): void
    {
        $refused = array( 'last_24_hours', 'last_hour', 'last_half_hour',
                          'all_time', 'time_range', 'day' );

        $default = $this->period()->getDefaultReportingPeriod();

        foreach ( $refused as $type ) {

            $this->assertFalse( $this->period()->isValid( $type ),
                "$type is now accepted -- if that is intended, this test should say so" );

            $p = $this->fromMap( array( 'period' => $type ) );

            $this->assertSame( $default, $p->get(),
                "$type should currently fall back to the default period" );
        }
    }

    public function testAnArbitraryValueFallsBackRatherThanReachingTheDateArithmetic(): void
    {
        $p = $this->fromMap( array( 'period' => 'nonsense; drop table' ) );

        $this->assertSame( $this->period()->getDefaultReportingPeriod(), $p->get() );
        $this->assertIsObject( $p->getStartDate() );
    }

    public function testNoPeriodAtAllYieldsTheConfiguredDefault(): void
    {
        $p = $this->fromMap( array() );

        $this->assertSame(
            \OWA\Core\CoreAPI::getSetting( 'base', 'default_reporting_period' ),
            $p->get(),
            'a report with no period must use the configured default' );

        $this->assertTrue( $p->isDefaultPeriod(),
            'the view distinguishes a defaulted period from a chosen one' );
    }

    /**
     * The flag is what the picker uses to show whether the user chose the
     * range, so it must NOT be set when they did.
     */
    public function testAChosenPeriodIsNotMarkedAsDefault(): void
    {
        $this->assertFalse( $this->fromMap( array( 'period' => 'today' ) )->isDefaultPeriod() );

        // ...and an invalid choice, which falls back to the default VALUE,
        // still counts as chosen. Recorded because it is surprising: the value
        // is the default but the flag says otherwise.
        $this->assertFalse( $this->fromMap( array( 'period' => 'garbage' ) )->isDefaultPeriod() );
    }

    public function testDatesWithoutAPeriodBecomeACustomRange(): void
    {
        $p = $this->fromMap( array( 'startDate' => '20260801', 'endDate' => '20260815' ) );

        $this->assertSame( 'date_range', $p->get(),
            'the custom range the calendar produces is how two dates arrive' );

        $this->assertSame( 20260801, (int) $p->getStartDate()->get( 'yyyymmdd' ) );
        $this->assertSame( 20260815, (int) $p->getEndDate()->get( 'yyyymmdd' ) );
    }

    public function testAnExplicitPeriodBeatsSuppliedDates(): void
    {
        $p = $this->fromMap( array(
            'period'    => 'today',
            'startDate' => '20200101',
            'endDate'   => '20200102',
        ) );

        $this->assertSame( 'today', $p->get(),
            'a named period and a date range both present -- the named one wins' );
    }

    /**
     * A start date with no end date is neither a named period nor a complete
     * range. Recorded because the branch exists and nothing else covers it.
     */
    public function testAStartDateAloneDoesNotSilentlyBecomeARange(): void
    {
        $p = $this->fromMap( array( 'startDate' => '20260801' ) );

        $this->assertIsObject( $p->getStartDate() );
        $this->assertIsObject( $p->getEndDate() );
        $this->assertLessThanOrEqual(
            (int) $p->getEndDate()->get( 'yyyymmdd' ),
            (int) $p->getStartDate()->get( 'yyyymmdd' ) );
    }

    /**
     * There is no default site, and the code that would have supplied one is
     * commented out at its only call site.
     *
     * Pinned as it stands rather than changed: making reports pick a site on
     * the user's behalf is a product decision, not a refactor. This exists so
     * the conversion does not accidentally introduce one, or accidentally
     * remove the dead method under the impression it is live.
     */
    public function testAReportWithNoSiteIdDoesNotInventOne(): void
    {
        $controller = new \OWA\Module\Base\Controller\ReportPages( array() );

        $this->assertEmpty( $controller->getParam( 'siteId' ),
            'a report with no siteId must not choose a site silently' );

        $source = (string) file_get_contents( OWA_DIR . 'Core/ReportController.php' );

        $this->assertMatchesRegularExpression(
            '#//\s*\$siteId\s*=\s*\$this->getDefaultSiteId\(\);#', $source,
            'the default-site call is commented out; if it is restored, the '
            . 'assertion above should change with it rather than be deleted' );
    }

    public function testSiteIdIsAcceptedFromEitherSpelling(): void
    {
        // Links in the wild carry both. Reports read siteId; the tracker and
        // some older links use site_id, and a report reached from one of those
        // must still know which site it is showing.
        $camel = new \OWA\Module\Base\Controller\ReportPages( array( 'siteId' => 'abc' ) );
        $snake = new \OWA\Module\Base\Controller\ReportPages( array( 'site_id' => 'abc' ) );

        $this->assertSame( 'abc', $camel->getParam( 'siteId' ) );
        $this->assertSame( 'abc', $snake->getParam( 'siteId' ) );
    }
}
