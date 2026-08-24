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
            // 'day' reads startDate, not separate parts -- an easy thing to get
            // wrong, and getting it wrong here is what surfaced the missing
            // guard on that read.
            'day'        => array( 'startDate' => '20260815' ),
        );

        $types = array(
            'today', 'yesterday', 'this_week', 'this_month', 'this_year',
            'last_week', 'last_month', 'last_year', 'last_seven_days',
            'last_thirty_days', 'same_day_last_week', 'same_week_last_year',
            'same_month_last_year', 'last_24_hours', 'last_hour',
            'last_half_hour', 'date_range', 'time_range', 'day',
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
     * _setDates() still builds last_24_hours, last_hour, last_half_hour,
     * time_range and day, but getValidPeriods() does not include them, so
     * asking for one gets the DEFAULT reporting period instead.
     *
     * Left unoffered deliberately: the sub-hour periods need bound pruning at
     * finer than day granularity before they are anything but expensive, and
     * nothing needs them today. all_time is not on this list because it no
     * longer exists at all -- see the test below.
     */
    public function testImplementedButUnofferedPeriodsSilentlyBecomeTheDefault(): void
    {
        $refused = array( 'last_24_hours', 'last_hour', 'last_half_hour',
                          'time_range', 'day' );

        $default = $this->period()->getDefaultReportingPeriod();

        foreach ( $refused as $type ) {

            $this->assertFalse( $this->period()->isValid( $type ),
                "$type is now accepted -- if that is intended, this test should say so" );

            $p = $this->fromMap( array( 'period' => $type ) );

            $this->assertSame( $default, $p->get(),
                "$type should currently fall back to the default period" );
        }
    }

    /**
     * all_time is gone, implementation and all.
     *
     * It set the start to 1 January 1969 and the end to now: a scan of every
     * partition of every fact table, by construction, since a range that wide
     * prunes to nothing. It was already unreachable through the normal path,
     * which is why nobody noticed the visitor roster linking to it -- that link
     * had been quietly serving the default period instead of the visitor's full
     * history.
     *
     * Removing the case as well as the acceptance is what stops set(), which
     * has no guard, from still being able to ask for it.
     */
    public function testAllTimeIsGoneEntirely(): void
    {
        $this->assertFalse( $this->period()->isValid( 'all_time' ) );

        $source = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Classes/TimePeriod.php' );

        $this->assertStringNotContainsString( 'case "all_time"', $source,
            'the implementation must go too, or set() can still reach it' );

        // ...and nothing links to it any more.
        foreach ( glob( OWA_DIR . 'modules/Base/templates/*.php' ) as $tpl ) {

            $this->assertStringNotContainsString( "'all_time'", (string) file_get_contents( $tpl ),
                basename( $tpl ) . ' still asks for a period that no longer exists' );
        }
    }

    /**
     * The web and the API validate against the same list.
     *
     * They did not: this class accepted the picker's labels plus date_range,
     * while ReportsRest built its own from the labels alone -- so
     * `period=date_range` was valid over the web and rejected over the API, and
     * a custom range could only be requested by omitting the parameter.
     *
     * The inference still works, and that matters: a caller sending two dates
     * and nothing else should not have to add a parameter that carries no
     * information.
     */
    public function testTheApiAndTheWebAgreeOnWhatAPeriodMayBe(): void
    {
        $valid = $this->period()->getValidPeriods();

        $this->assertContains( 'date_range', $valid,
            'a custom range is a period a caller may name' );

        foreach ( array_keys( $this->period()->getPeriodLabels() ) as $offered ) {
            $this->assertContains( $offered, $valid,
                "the picker offers \"$offered\" but it is not accepted" );
        }

        // The REST controller must read that list rather than build a second one.
        $rest = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/ReportsRest.php' );

        $this->assertStringContainsString( 'getValidPeriods()', $rest,
            'ReportsRest must validate against the shared list' );
        $this->assertStringNotContainsString( 'getPeriodLabels()', $rest,
            'building a second list from the dropdown labels is how these drifted apart' );
    }

    /**
     * Naming the range must stay optional.
     */
    public function testTwoDatesAloneAreStillEnough(): void
    {
        $p = $this->fromMap( array( 'startDate' => '20260801', 'endDate' => '20260815' ) );

        $this->assertSame( 'date_range', $p->get() );
        $this->assertSame( 20260801, (int) $p->getStartDate()->get( 'yyyymmdd' ) );
    }

    public function testAnArbitraryValueFallsBackRatherThanReachingTheDateArithmetic(): void
    {
        $p = $this->fromMap( array( 'period' => 'nonsense; drop table' ) );

        $this->assertSame( $this->period()->getDefaultReportingPeriod(), $p->get() );
        $this->assertIsObject( $p->getStartDate() );
    }

    /**
     * The web refuses an invalid period instead of quietly substituting one.
     *
     * The picker constrains the choice, so reaching this means the URL was
     * edited by hand. Answering is better than guessing -- and it is what the
     * REST API already did, so the two now agree on what a period may be and on
     * what happens when it is not one.
     */
    public function testTheWebRefusesAnInvalidPeriodRatherThanSubstituting(): void
    {
        // Rendering a report runs pre(), which loads the site list.
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; rendering loads the site list.' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        foreach ( array( 'garbage', 'all_time', 'last_hour' ) as $bad ) {

            $data = (array) ( new \OWA\Module\Base\Controller\Report(
                array( 'reportId' => 'pages', 'period' => $bad ) ) )->doAction();

            $this->assertSame( 'base.error', $data['view'] ?? null,
                "period=$bad was accepted and quietly replaced" );

            $this->assertArrayNotHasKey( 'subview', $data,
                "period=$bad still rendered a report" );

            $this->assertStringContainsString( $bad, (string) ( $data['error_msg'] ?? '' ),
                'the message must name the value that was refused' );
        }
    }

    public function testAValidPeriodAndNoPeriodBothStillRender(): void
    {
        // Rendering a report runs pre(), which loads the site list.
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; rendering loads the site list.' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        foreach ( array( array(), array( 'period' => 'today' ),
                         array( 'period' => 'date_range', 'startDate' => '20260801',
                                'endDate' => '20260815' ) ) as $params ) {

            $data = (array) ( new \OWA\Module\Base\Controller\Report(
                $params + array( 'reportId' => 'pages' ) ) )->doAction();

            $this->assertSame( 'base.report', $data['view'] ?? null,
                'a legitimate request must still render: ' . json_encode( $params ) );
        }
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
        // Any ReportController would do; this is the one every configured
        // report now runs on, and ReportPages is no longer a class.
        $controller = new \OWA\Core\ConfiguredReport( array() );

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
        $camel = new \OWA\Core\ConfiguredReport( array( 'siteId' => 'abc' ) );
        $snake = new \OWA\Core\ConfiguredReport( array( 'site_id' => 'abc' ) );

        $this->assertSame( 'abc', $camel->getParam( 'siteId' ) );
        $this->assertSame( 'abc', $snake->getParam( 'siteId' ) );
    }

    /**
     * The web refuses an unusable range instead of rendering an inverted one.
     *
     * Same stance as an invalid period: the picker cannot produce these, so
     * arriving with one means the URL was edited, and substituting something
     * plausible hides that.
     *
     * @dataProvider unusableWebRangeProvider
     */
    public function testTheWebRefusesAnUnusableDateRange( array $params, string $because ): void
    {
        // Rendering a report runs pre(), which loads the site list.
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; rendering loads the site list.' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $data = (array) ( new \OWA\Module\Base\Controller\Report(
                $params + array( 'reportId' => 'pages' ) ) )->doAction();

        $this->assertSame( 'base.error', $data['view'] ?? null,
            'an unusable range was accepted: ' . json_encode( $params ) );

        $this->assertArrayNotHasKey( 'subview', $data,
            'it still rendered a report' );

        $this->assertStringContainsString( $because, (string) ( $data['error_msg'] ?? '' ),
            'the message must say what is actually wrong with the range' );
    }

    public static function unusableWebRangeProvider(): array
    {
        return array(
            'end date alone'      => array( array( 'endDate' => '20260810' ), 'needs a start date' ),
            'start date alone'    => array( array( 'startDate' => '20260801' ), 'needs an end date' ),
            'range with no dates' => array( array( 'period' => 'date_range' ), 'needs a start date and an end date' ),
            'inverted range'      => array( array( 'startDate' => '20260810', 'endDate' => '20260801' ), 'is after the end date' ),
            'malformed bound'     => array( array( 'startDate' => 'yesterday', 'endDate' => '20260810' ), 'is not a date' ),
        );
    }

    /**
     * And the shapes that are legitimate still render -- including a single
     * day, whose bounds are equal.
     *
     * @dataProvider usableWebRangeProvider
     */
    public function testTheWebStillRendersAUsableDateRange( array $params ): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; rendering loads the site list.' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $data = (array) ( new \OWA\Module\Base\Controller\Report(
                $params + array( 'reportId' => 'pages' ) ) )->doAction();

        $this->assertNotSame( 'base.error', $data['view'] ?? null,
            'a legitimate range was refused: ' . json_encode( $params ) );
    }

    public static function usableWebRangeProvider(): array
    {
        return array(
            'ordered range'      => array( array( 'startDate' => '20260801', 'endDate' => '20260810' ) ),
            'named date_range'   => array( array( 'period' => 'date_range', 'startDate' => '20260801', 'endDate' => '20260810' ) ),
            'single day'         => array( array( 'period' => 'date_range', 'startDate' => '20260801', 'endDate' => '20260801' ) ),
            'a relative period'  => array( array( 'period' => 'today' ) ),
            'nothing at all'     => array( array() ),
        );
    }
}
