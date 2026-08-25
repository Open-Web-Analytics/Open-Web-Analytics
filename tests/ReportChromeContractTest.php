<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * The furniture every report is supposed to come with.
 *
 * A report is not only its metrics and dimensions. Every one of them also gets
 * a site selector and a date-range picker, and those do not come from the
 * report -- they come from ReportController::pre(), which runs inside
 * doAction() once the user is authenticated. It sets `sites`, `currentSiteId`
 * and `period`, and folds siteId, period, startDate and endDate into the params
 * the view renders from.
 *
 * The characterization fixture does NOT cover any of this, deliberately: it
 * calls action() directly, so it records what each report declares for itself
 * and stays free of a database and of today's date. That is the right shape for
 * comparing one report against its own past, and the wrong shape for this --
 * which is identical across every report, changes with the calendar, and reads
 * the site list out of the database.
 *
 * So it is pinned here instead, as a contract rather than a recording: the keys
 * have to be present and of the right kind, and their values are none of this
 * test's business.
 *
 * The reason it matters for the conversion: a JSON-rendered report that forgets
 * to run pre() loses its site selector and its date picker on every screen, and
 * nothing in the characterization suite would say a word.
 */
final class ReportChromeContractTest extends TestCase
{
    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; the site list cannot load.' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    /** @return array<string, array{0:string}> */
    public static function reportProvider(): array
    {
        $cases = array();

        // A spread rather than all of them: this is shared behaviour, so the
        // useful question is whether it holds across the KINDS of report.
        //
        // Controller-backed reports only, because this provider builds the
        // controller directly. The converted reports get the same chrome
        // through the registry route, which testTheChromeSurvivesTheRegistryRoute
        // covers by id -- and campaigns moved there when it became a definition.
        //
        // The remaining bespoke reports are not interchangeable here. This
        // provider supplies a SENTINEL for every parameter a report reads, so
        // one that reads `period`/`startDate`/`endDate` (ReportDashboard) loses the very chrome under test, and one that reads
        // an id it must resolve (ReportGoalFunnel's goalNumber) throws. Four
        // kinds, chosen because they survive a sentinel.
        foreach ( array( 'ReportTransactionDetail' ) as $name ) {
            $cases[ $name ] = array( $name );
        }

        return $cases;
    }

    private function assertHasChrome( array $data, string $context ): void
    {
        $this->assertArrayHasKey( 'sites', $data,
            "$context: no site list, so the site filter renders empty" );
        $this->assertIsArray( $data['sites'], "$context: the site filter needs a list" );

        $this->assertArrayHasKey( 'currentSiteId', $data,
            "$context: nothing tells the site filter which site is selected" );

        $this->assertArrayHasKey( 'period', $data,
            "$context: no time period, so the date picker has nothing to show" );
        $this->assertIsObject( $data['period'],
            "$context: the period must be the time-period object the view asks for" );

        // The picker and the filter read these from params, not from the top
        // level -- and the report's own links carry them onward, which is how
        // the selection survives a drill-down.
        foreach ( array( 'siteId', 'period', 'startDate', 'endDate' ) as $key ) {
            $this->assertArrayHasKey( $key, $data['params'],
                "$context: params.$key is missing, so the selection cannot travel" );
        }

        /*
         * The third control is real-time mode -- the on/off group that refreshes
         * every widget on a timer. It is entirely client-side: OWA.report holds
         * autoRefreshResultSets (off) and autoRefreshResultSetsInterval (15s),
         * nothing on the server sets either, and templates/report.php builds the
         * object with `new OWA.report()` bound to this dom_id.
         *
         * So there is exactly one server-side thing it depends on, and this is
         * it. A converted report that omits dom_id gives the report object
         * nothing to bind to, and the control -- along with the tab machinery
         * and every widget's refresh -- silently never appears.
         */
        $this->assertArrayHasKey( 'dom_id', $data,
            "$context: no dom_id, so OWA.report has nothing to bind to and the "
            . 'real-time control never renders' );
    }

    /**
     * @dataProvider reportProvider
     */
    public function testAReportComesWithItsSiteFilterAndDatePicker( string $name ): void
    {
        $class  = '\OWA\Module\Base\Controller\\' . $name;
        $params = array_fill_keys( Harness::paramsFor( $name ), Harness::SENTINEL );

        $data = (array) ( new $class( $params ) )->doAction();

        $this->assertHasChrome( $data, $name );
    }

    /**
     * The same must hold through the dispatcher, because that is the route the
     * conversion moves everything onto. A report that keeps its chrome when
     * reached directly and loses it when reached by id would break every screen
     * the moment nav switches to report ids.
     *
     * @dataProvider registryRouteProvider
     */
    public function testTheChromeSurvivesTheRegistryRoute( string $id ): void
    {
        /*
         * Every parameter the definition declares, supplied.
         *
         * A definition that enumerates a constraint is refused when the value
         * behind it is missing -- rendering site-wide data under a detail
         * report's heading is worse than an error. So a chrome test has to
         * arrive with the report's parameters, or it is testing the error view.
         */
        $params = array( 'reportId' => $id );

        foreach ( self::declaredParams( $id ) as $name ) {

            $params[ $name ] = 'chrome_contract_sentinel';
        }

        $data = (array) ( new \OWA\Module\Base\Controller\Report( $params ) )->doAction();

        $this->assertHasChrome( $data, "base.report&reportId=$id" );
    }

    /** @return array<int,string> the parameter names a definition declares */
    private static function declaredParams( string $id ): array
    {
        $file = OWA_DIR . 'modules/Base/reports/' . $id . '.json';

        if ( ! is_readable( $file ) ) {

            return array();
        }

        $def = json_decode( (string) file_get_contents( $file ), true );

        return array_keys( (array) ( $def['params'] ?? array() ) );
    }

    /** @return array<string, array{0:string}> */
    public static function registryRouteProvider(): array
    {
        return array(
            'pages'           => array( 'pages' ),
            'entry-pages'     => array( 'entry-pages' ),
            'browsers'        => array( 'browsers' ),
            'referring-sites' => array( 'referring-sites' ),

            // A parameterised detail report, and one carrying a panel widget:
            // referral-detail took ReportReferralDetail's place in the spread
            // above when it became a definition.
            'referral-detail' => array( 'referral-detail' ),
            'campaigns'       => array( 'campaigns' ),
            'dom-clicks'      => array( 'dom-clicks' ),
        );
    }

    /**
     * Direct and delegated must agree about the furniture, not merely both have
     * some. Comparing the shapes catches a dispatcher that supplies its OWN
     * period or site list instead of letting the report's pre() do it.
     */
    public function testTheTwoRoutesAgreeAboutTheChrome(): void
    {
        // A bespoke report, which is the only kind that still HAS both routes
        // to compare -- every configured report has only the one.
        $direct = (array) ( new \OWA\Module\Base\Controller\ReportTransactionDetail( array() ) )->doAction();
        $viaId  = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => 'transaction-detail' ) ) )->doAction();

        $directKeys = array_keys( $direct['params'] );
        $viaIdKeys  = array_keys( $viaId['params'] );

        // Everything the direct route hands the view must survive the extra hop.
        $this->assertSame( array(), array_diff( $directKeys, $viaIdKeys ),
            'the delegated route dropped params the direct route provides' );

        // ...and the only thing it adds is the id that got it there. Asserted
        // exactly rather than ignored, so a dispatcher that started injecting
        // its own state into the view would be visible here.
        $this->assertSame( array( 'reportId' ), array_values( array_diff( $viaIdKeys, $directKeys ) ),
            'the delegated route added params beyond the reportId that addressed it' );

        $this->assertSame( count( $direct['sites'] ), count( $viaId['sites'] ),
            'the two routes disagree about how many sites the filter should list' );

        $this->assertSame( get_class( $direct['period'] ), get_class( $viaId['period'] ) );
    }

    /**
     * Reports reached by id must not share a container id.
     *
     * dom_id used to be the action name hyphenated, and every report had its
     * own action -- base-reportPages, base-reportEntryPages. Routing everything
     * through base.report would have collapsed that to "base-report" for every
     * report on the installation.
     *
     * Nothing keys persistence off it today, so nothing was broken. It is
     * derived from the report identity anyway, because dom_id is what
     * OWA.report binds to and what anything remembering per-report UI state --
     * an active tab, real-time mode left switched on -- would naturally key on.
     */
    public function testReportsReachedByIdGetDistinctContainerIds(): void
    {
        $ids = array();

        foreach ( array( 'pages', 'entry-pages', 'browsers', 'referring-sites' ) as $id ) {

            $data = (array) ( new \OWA\Module\Base\Controller\Report(
                array( 'reportId' => $id, 'do' => 'base.report' ) ) )->doAction();

            $ids[ $id ] = $data['dom_id'];
        }

        $this->assertSame( count( $ids ), count( array_unique( $ids ) ),
            'two reports share a container id: ' . json_encode( $ids ) );

        foreach ( $ids as $reportId => $domId ) {
            $this->assertStringContainsString( str_replace( '_', '-', $reportId ), $domId,
                "the container id for $reportId does not identify it" );
        }
    }

    /**
     * ...and a report still reached by its own action keeps the id it had, so
     * the reportId derivation added an id for the new path rather than changing
     * the old one.
     *
     * Was ReportPages, then ReportHostDetail, then ReportCampaigns, then
     * ReportGoals; all four are configuration now and have no action left.
     * document still prefetches -- it loads a document entity for its header --
     * so it keeps its own.
     */
    public function testTheDirectRouteKeepsItsOriginalContainerId(): void
    {
        $data = (array) ( new \OWA\Module\Base\Controller\ReportTransactionDetail(
            array( 'do' => 'base.reportTransactionDetail' ) ) )->doAction();

        $this->assertSame( 'base-reportTransactionDetail', $data['dom_id'] );
    }

    /**
     * The sites page is not a report, and depends on the report chrome anyway.
     *
     * base.sites extends ReportController, so it inherits pre() -- and its
     * template builds one API URL per site with makeApiLink( ..., true ), which
     * adds the current state. The per-site metric strips therefore honour the
     * date picker exactly as a report's widgets do.
     *
     * It is covered here because that dependency is invisible from its
     * controller, whose action() only lists sites. Someone reading it would
     * reasonably conclude the page has nothing to do with periods, and remove
     * the chrome from underneath it.
     */
    public function testTheSitesPageKeepsTheReportChrome(): void
    {
        $data = (array) ( new \OWA\Module\Base\Controller\Sites( array() ) )->doAction();

        $this->assertHasChrome( $data, 'base.sites' );

        $this->assertArrayHasKey( 'tracked_sites', $data,
            'the page must still list the sites it exists to list' );
    }

    /**
     * ...and it inherits the period refusal along with the chrome.
     *
     * Recorded because it is a consequence rather than a decision: the
     * validation was added to ReportController for the reports, and every
     * subclass got it. Here that is the behaviour we want -- a garbage period
     * would otherwise have shown seven days of metrics beside every site while
     * the picker claimed something else -- but a subclass that did NOT want it
     * would need to say so, and nothing would have told us.
     */
    public function testTheSitesPageAlsoRefusesAnInvalidPeriod(): void
    {
        $data = (array) ( new \OWA\Module\Base\Controller\Sites(
            array( 'period' => 'garbage' ) ) )->doAction();

        $this->assertSame( 'base.error', $data['view'] ?? null );
        $this->assertArrayNotHasKey( 'tracked_sites', $data );
    }

    /**
     * Vacuity guard.
     *
     * Every assertion above is "this key exists". If pre() ever stopped running
     * entirely the data bag would be small and they would all fail -- but if it
     * ran and produced nothing useful they would all pass. Show the bag is the
     * rich one.
     */
    public function testTheChromeIsSubstantialNotJustPresent(): void
    {
        // Reached by id: pages is configuration now and has no other route.
        $data = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => 'pages' ) ) )->doAction();

        /*
         * Named keys rather than a count.
         *
         * This was `count($data) > 20`, and moving pages' settings into its
         * widgets list dropped the total to 19 -- a failure that says nothing
         * about the chrome, which is what the test is for. Counting the bag
         * measures how a report happens to be written; naming what pre() adds
         * measures what the test actually cares about.
         */
        $declared = Harness::snapshot( 'ReportPages' )['config'];

        $addedByPre = array_diff( array_keys( $data ), array_keys( $declared ) );

        foreach ( array( 'sites', 'currentSiteId', 'params', 'is_default_period', 'dom_id' ) as $key ) {

            $this->assertContains( $key, $addedByPre,
                "doAction() did not add '$key' over action(), so pre() cannot have run" );
        }
    }
}
