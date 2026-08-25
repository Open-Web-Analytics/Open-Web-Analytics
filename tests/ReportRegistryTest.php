<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';

use OWA\Tests\ReportCharacterizationHarness as Harness;

/**
 * One action for every report, and a registry that says what each id means.
 *
 * Step 1 of moving reports into configuration. Nothing renders differently yet:
 * every registered report names a controller, so `do=base.report&reportId=pages`
 * reaches exactly what `base.reportPages` reached. That is the point -- the URL
 * scheme, the nav links and the inter-report links can all move onto report ids
 * before a single report becomes JSON, and each conversion afterwards changes
 * one registry entry instead of every link pointing at it.
 *
 * The registry is also what makes the two permanent exceptions affordable. The
 * heatmap and the session player never become configuration; they keep their
 * controllers, and a registry entry is what keeps them linkable by id anyway.
 */
final class ReportRegistryTest extends TestCase
{
    /** Report-defining keys: what a report IS, as opposed to request scaffolding. */
    private const DEFINING = array(
        'subview', 'view_method', 'metrics', 'dimensions', 'sort', 'resultsPerPage',
        'constraints', 'trendChartMetric', 'trendTitle', 'gridTitle',
        'excludeColumns', 'dimensionLink', 'dimension', 'title', 'titleSuffix',
    );

    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    /**
     * Delegating to a real report runs ReportController::pre(), which loads the
     * site list -- so those cases need a database and the rest do not.
     *
     * Guarded per test rather than per class on purpose. The registry's
     * contents, its id format, its laziness and its refusal of duplicates are
     * all checkable without one, and skipping the whole class would give that
     * coverage up everywhere a database is absent, which includes CI's unit job.
     */
    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; delegation loads the site list.' );
        }
    }

    private function registry(): array
    {
        return \OWA\Core\CoreAPI::getReportRegistry();
    }

    public function testEveryReportControllerHasARegistryEntry(): void
    {
        $registry = $this->registry();

        $this->assertNotEmpty( $registry );

        $controllers = array();

        foreach ( $registry as $id => $def ) {
            if ( ! empty( $def['controller'] ) ) {
                $controllers[] = $def['controller'];
            }
        }

        // Every report reachable as an action must be reachable as an id, or
        // moving nav onto ids would silently drop reports out of the interface.
        foreach ( glob( OWA_DIR . 'modules/Base/Controller/Report*.php' ) as $file ) {

            $name = basename( $file, '.php' );

            if ( in_array( $name, array( 'ReportsRest', 'Report' ), true ) ) {
                continue;
            }

            $action = 'base.' . lcfirst( $name );

            $this->assertContains( $action, $controllers,
                "$action has no report id, so it would be unreachable once nav moves to ids" );
        }
    }

    public function testIdsReadLikeNamesNotClasses(): void
    {
        foreach ( array_keys( $this->registry() ) as $id ) {

            $this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $id,
                "\"$id\" is the public identity of a report -- it belongs in URLs and in "
                . 'links, so it has to read like a name' );
        }

        // A spot check that the derivation did what it looks like it did.
        $this->assertArrayHasKey( 'entry-pages', $this->registry() );
        $this->assertArrayHasKey( 'referring-sites', $this->registry() );
    }

    /**
     * For a report still implemented by a controller, the new route is the old
     * route.
     *
     * The converted reports are deliberately not in this provider: they have no
     * direct route left to compare against, which is the change rather than a
     * gap. What they render is held to the recorded baseline instead, by
     * ReportConfigEquivalenceTest and the characterization fixture.
     *
     * @dataProvider sampleReportProvider
     */
    public function testTheRegistryRouteMatchesTheDirectRoute( string $id, string $class ): void
    {
        $this->requireDb();

        $direct = (array) ( new $class( array() ) )->doAction();
        $viaId  = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => $id ) ) )->doAction();

        foreach ( self::DEFINING as $key ) {

            $this->assertSame(
                $direct[ $key ] ?? null,
                $viaId[ $key ] ?? null,
                "$id declares a different $key through the registry than it does directly" );
        }

        // ...and it is not passing because both came back empty.
        $this->assertNotEmpty( $viaId['subview'] ?? '' );
    }

    public static function sampleReportProvider(): array
    {
        // The bespoke reports, which are the only ones still implemented by a
        // controller -- and, by decision, always will be. They prefetch result
        // sets, so they are also the ones the characterization harness cannot
        // snapshot; this is the coverage they get instead.
        return array(
            'bespoke stays'  => array( 'domstreams', '\OWA\Module\Base\Controller\ReportDomstreams' ),
            'prefetching'    => array( 'goal-funnel', '\OWA\Module\Base\Controller\ReportGoalFunnel' ),
            'entity detail'  => array( 'transaction-detail', '\OWA\Module\Base\Controller\ReportTransactionDetail' ),
        );
    }

    /**
     * A converted report renders through the registry, and its old action is
     * gone.
     *
     * Both halves matter. The first says the JSON path actually works end to
     * end rather than only under the harness; the second says the controller
     * really was retired, because an action left registered would keep serving
     * a report from code that no longer matches the configuration -- two
     * answers to one question, with nothing to say which is authoritative.
     *
     * @dataProvider convertedReportProvider
     */
    public function testAConvertedReportRendersFromItsDefinition( string $id, string $wasAction ): void
    {
        $this->requireDb();

        $data = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => $id ) ) )->doAction();

        $this->assertNotEmpty( $data['subview'] ?? '',
            "report '$id' did not render from its definition" );

        $this->assertNotSame( 'base.error', $data['view'] ?? null,
            "report '$id' was refused" );

        $actions = \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'actions', $wasAction );

        $this->assertEmpty( $actions,
            "$wasAction is still registered, so a converted report has two implementations" );
    }

    public static function convertedReportProvider(): array
    {
        return array(
            'pages'           => array( 'pages',           'base.reportPages' ),
            'entry-pages'     => array( 'entry-pages',     'base.reportEntryPages' ),
            'referring-sites' => array( 'referring-sites', 'base.reportReferringSites' ),
            'traffic'         => array( 'traffic',         'base.reportTraffic' ),
        );
    }

    /**
     * A parameter has to survive the extra hop, or every detail report breaks
     * the moment nav moves onto ids.
     */
    public function testParametersReachTheDelegatedReport(): void
    {
        $this->requireDb();

        $viaId = (array) ( new \OWA\Module\Base\Controller\Report( array(
            'reportId' => 'host-detail',
            'hostName' => Harness::SENTINEL,
        ) ) )->doAction();

        $this->assertStringContainsString( Harness::SENTINEL, (string) ( $viaId['constraints'] ?? '' ),
            'the delegated report built its constraint without the parameter' );
    }

    public function testAnUnknownIdIsRefusedRatherThanDispatched(): void
    {
        $before = http_response_code();

        ( new \OWA\Module\Base\Controller\Report( array( 'reportId' => 'no-such-report' ) ) )
            ->doAction();

        $this->assertSame( 404, http_response_code(),
            'an unknown report id must 404 rather than fall through to something' );

        http_response_code( $before ?: 200 );
    }

    public function testAMissingIdIsRefused(): void
    {
        $before = http_response_code();

        ( new \OWA\Module\Base\Controller\Report( array() ) )->doAction();

        $this->assertSame( 400, http_response_code() );

        http_response_code( $before ?: 200 );
    }

    /**
     * Registration is lazy, and must stay lazy.
     *
     * Module::__construct() runs on every request including every tracker
     * beacon. If registerReports() were ever called from there, a file read and
     * a json_decode per report would land on the logging path -- the opposite
     * of the direction that path has been taken.
     */
    public function testRegistrationIsNotDoneByTheConstructor(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'Core/Module.php' );

        $constructor = substr( $src, strpos( $src, 'function __construct' ) );
        $constructor = substr( $constructor, 0, strpos( $constructor, "\n    }" ) );

        $this->assertStringNotContainsString( 'registerReports', $constructor,
            'report registration in the constructor puts it on the tracker path' );

        // ...and the lazy path is what actually populates it.
        $this->assertNotEmpty( \OWA\Core\CoreAPI::getReportRegistry() );
    }

    /**
     * The dispatcher declares no capability of its own, and is listed as
     * intentionally public in ControllerCapabilityContractTest. That exemption
     * is only defensible if the TARGET's check really runs, so demonstrate it
     * rather than assert it in a comment.
     */
    public function testAnUnauthenticatedRequestIsRefusedByTheTargetsCapability(): void
    {
        $this->requireDb();

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'everyone' );
        $user->setAuthStatus( false );

        $refused = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => 'pages' ) ) )->doAction();

        $this->assertArrayNotHasKey( 'subview', $refused,
            'an unauthenticated request reached the report through base.report' );

        // ...and the same id succeeds once the capability is held, so the
        // refusal above is authorization and not a broken lookup.
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $allowed = (array) ( new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => 'pages' ) ) )->doAction();

        $this->assertSame( 'base.reportWidgets', $allowed['subview'] ?? null );
    }

    /**
     * The dispatcher must not add a gate of its own.
     *
     * Asserted structurally rather than through a role, and the reason is worth
     * recording: view_reports is in capabilitiesThatRequireSiteAccess, so any
     * non-admin identity is refused for lack of a site assignment long before a
     * second capability could matter. Every role available without database
     * fixtures therefore produces the same answer whether the dispatcher gates
     * or not, which makes a role-based test look like it passes while proving
     * nothing.
     *
     * So state the invariant itself. That a report's OWN capability is still
     * enforced is covered separately, by the unauthenticated case below and by
     * delegation happening at doAction().
     */
    public function testTheDispatcherDeclaresNoCapabilityOfItsOwn(): void
    {
        $dispatcher = new \OWA\Module\Base\Controller\Report(
            array( 'reportId' => 'pages' ) );

        $this->assertSame( '', (string) $dispatcher->getRequiredCapability(),
            'base.report declaring a capability means a report can be gated by two '
            . 'different answers, with the stricter winning by accident. Resolution is '
            . 'not the thing being authorized; the report is.' );
    }

    public function testTheRegistryRefusesADuplicateId(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $before  = $service->getMapValue( 'reports', 'pages' );

        // Module::__construct() takes no arguments and reads $this->name, which
        // real modules set before calling it -- so passing 'base' positionally
        // was silently dropped and the name stayed null all the way into
        // moduleDirName(). Set the property, as a real module does.
        $module = new class extends \OWA\Core\Module {
            public function __construct() { $this->name = 'base'; parent::__construct(); }
            public function takeIt() { return $this->registerReport( 'pages', 'x.json' ); }
        };

        $this->assertFalse( $module->takeIt(),
            'a second registration of an id must be refused, not silently win' );

        $this->assertSame( $before, $service->getMapValue( 'reports', 'pages' ),
            'the original definition must survive the attempt' );
    }
}
