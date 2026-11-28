<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/ReportCharacterizationHarness.php';
require_once __DIR__ . '/DomstreamFixtures.php';

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
    private const DEFINING = [
        'subview', 'view_method', 'metrics', 'dimensions', 'sort', 'resultsPerPage',
        'constraints', 'trendChartMetric', 'gridTitle',
        'excludeColumns', 'dimensionLink', 'dimension', 'title', 'titleSuffix',
    ];

    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    /**
     * And the configuration half: every definition file is registered, and every
     * registration that names a file names one that exists.
     *
     * A file nobody registers is loaded by nobody -- left behind by a rename, it
     * sits there diverging from the report it looks like it belongs to. A
     * registration naming a missing file is a nav link to an error.
     */
    public function testEveryDefinitionFileIsRegisteredAndEveryRegistrationHasOne(): void
    {
        $registered = [];

        foreach ( $this->registry() as $id => $def ) {

            if ( empty( $def['json'] ) ) {
                continue;
            }

            $this->assertFileExists( $def['json'], "report '$id' is registered against a missing file" );

            $registered[] = realpath( $def['json'] );
        }

        $this->assertNotEmpty( $registered, 'no report is registered from a file; this would pass vacuously' );

        foreach ( glob( \OWA\Core\Env::root() . '/modules/Base/reports/*.json' ) as $file ) {

            $this->assertContains( realpath( $file ), $registered,
                basename( $file ) . ' is in the reports directory but nothing registers it' );
        }
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

        $controllers = [];

        foreach ( $registry as $id => $def ) {
            if ( ! empty( $def['controller'] ) ) {
                $controllers[] = $def['controller'];
            }
        }

        // Every report reachable as an action must be reachable as an id, or
        // moving nav onto ids would silently drop reports out of the interface.
        // Asked of every ACTIVE module: an inactive one registers neither.
        $files = [];

        foreach ( \OWA\Core\CoreAPI::serviceSingleton()->modules as $module ) {
            foreach ( (array) glob( $module->path . 'Controller/Report*.php' ) as $file ) {
                $files[ $file ] = $module->name;
            }
        }

        $this->assertArrayHasKey( \OWA\Core\Env::modulesDir() . 'Base/Controller/ReportsRest.php', $files,
            'no controllers were found -- this asserts nothing without them' );

        foreach ( $files as $file => $module_name ) {

            $name = basename( $file, '.php' );

            /*
             * ReportingHome matches Report*.php but is not a report -- it
             * resolves which Profile to open and redirects to base.report. It
             * has no id because it is not something you can navigate TO by id;
             * it is what decides the id.
             */
            if ( in_array( $name, [ 'ReportsRest', 'Report', 'ReportingHome' ], true ) ) {
                continue;
            }

            $action = $module_name . '.' . lcfirst( $name );

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
     * For a report still implemented by a controller, the registry route is the
     * direct route.
     *
     * Reports that are configuration are deliberately not in this provider: they
     * have no direct route to compare against. Their definitions are checked
     * by ReportDefinitionFilesTest, and running each of them by
     * ReportCharacterizationTest.
     *
     * @dataProvider sampleReportProvider
     */
    public function testTheRegistryRouteMatchesTheDirectRoute( string $id, string $class ): void
    {
        $this->requireDb();

        $restore = DomstreamFixtures::registerReport();

        try {
            $direct = (array) ( new $class( [] ) )->doAction();
            $viaId  = (array) ( new \OWA\Module\Base\Controller\Report(
                [ 'reportId' => $id ] ) )->doAction();
        } finally {
            $restore();
        }

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
        /*
         * The bespoke report -- one, and a module's: Base has none.
         *
         * The funnel is a VISUALIZATION, a row on owa_custom_report drawn by its own
         * controller and reached as custom-<id>, so it has no registry route to
         * compare.
         *
         * domstreams prefetches result sets, so the characterization harness
         * cannot snapshot it; this is the coverage it gets instead.
         */
        return [
            'bespoke stays'  => [ 'domstreams', '\OWA\Module\Domstream\Controller\ReportDomstreams' ],
        ];
    }

    /**
     * A report that is configuration renders through the registry, and has no
     * action of its own.
     *
     * Both halves matter. The first says the JSON path actually works end to
     * end rather than only under the harness; the second says no controller still
     * serves it, because an action left registered would serve a report from code
     * that does not match the configuration -- two answers to one question, with
     * nothing to say which is authoritative.
     *
     * @dataProvider convertedReportProvider
     */
    public function testAConvertedReportRendersFromItsDefinition( string $id, string $wasAction ): void
    {
        $this->requireDb();

        $data = (array) ( new \OWA\Module\Base\Controller\Report(
            [ 'reportId' => $id ] ) )->doAction();

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
        return [
            'pages'           => [ 'pages',           'base.reportPages' ],
            'entry-pages'     => [ 'entry-pages',     'base.reportEntryPages' ],
            'referring-sites' => [ 'referring-sites', 'base.reportReferringSites' ],
            'traffic'         => [ 'traffic',         'base.reportTraffic' ],
        ];
    }

    /**
     * A parameter has to survive the extra hop, or every detail report breaks
     * the moment nav moves onto ids.
     */
    public function testParametersReachTheDelegatedReport(): void
    {
        $this->requireDb();

        $viaId = (array) ( new \OWA\Module\Base\Controller\Report( [
            'reportId' => 'dom-clicks',
            'pagePath' => Harness::SENTINEL,
        ] ) )->doAction();

        $this->assertStringContainsString( Harness::SENTINEL, (string) ( $viaId['constraints'] ?? '' ),
            'the delegated report built its constraint without the parameter' );
    }

    public function testAnUnknownIdIsRefusedRatherThanDispatched(): void
    {
        $before = http_response_code();

        ( new \OWA\Module\Base\Controller\Report( [ 'reportId' => 'no-such-report' ] ) )
            ->doAction();

        $this->assertSame( 404, http_response_code(),
            'an unknown report id must 404 rather than fall through to something' );

        http_response_code( $before ?: 200 );
    }

    public function testAMissingIdIsRefused(): void
    {
        $before = http_response_code();

        ( new \OWA\Module\Base\Controller\Report( [] ) )->doAction();

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
        $module = new class extends \OWA\Core\Module {

            const NAME = 'owatest_lazy_reports';

            /** @var int */
            public static $asked = 0;

            public function registerReports() {

                self::$asked++;

                return false;
            }
        };

        $this->assertSame( 0, $module::$asked,
            'report registration in the constructor puts it on the tracker path' );
        $this->assertFalse( $module->reports_registered );

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
            [ 'reportId' => 'pages' ] ) )->doAction();

        $this->assertArrayNotHasKey( 'subview', $refused,
            'an unauthenticated request reached the report through base.report' );

        // ...and the same id succeeds once the capability is held, so the
        // refusal above is authorization and not a broken lookup.
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );

        $allowed = (array) ( new \OWA\Module\Base\Controller\Report(
            [ 'reportId' => 'pages' ] ) )->doAction();

        // Reaching the report is what this asserts, not whether it has data to
        // draw: with no cube for the resolved Profile's Property the report
        // is reached and shows why instead (ReportController::post()).
        $this->assertContains( $allowed['subview'] ?? null,
            [ 'base.reportWidgets', 'base.reportNotReady' ] );
    }

    /**
     * The dispatcher must not add a gate of its own.
     *
     * Asserted structurally rather than through a role: a report needs
     * view_reports and access to its Property, so any non-admin identity is
     * refused for lack of access long before a second capability could
     * matter. Every role available without database
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
            [ 'reportId' => 'pages' ] );

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
            const NAME = 'base';
            public function takeIt() { return $this->registerReport( 'pages', 'x.json' ); }
        };

        $this->assertFalse( $module->takeIt(),
            'a second registration of an id must be refused, not silently win' );

        $this->assertSame( $before, $service->getMapValue( 'reports', 'pages' ),
            'the original definition must survive the attempt' );
    }

    /**
     * A report link in the nav and the report it opens require the same
     * capability. A link gated more tightly than its report hides the report
     * from the menu while its URL still opens it.
     */
    public function testANavLinkRequiresWhatItsReportRequires(): void
    {
        $checked = 0;

        foreach ( \OWA\Core\CoreAPI::serviceSingleton()->modules as $module ) {

            // Registered once: registerNavigation() adds to what is there.
            if ( empty( $module->nav_links ) ) {

                $module->registerNavigation();
            }

            foreach ( (array) ( $module->getNavigationLinks()['Reports'] ?? [] ) as $group ) {

                foreach ( array_merge( [ $group ], array_values( (array) ( $group['subgroup'] ?? [] ) ) ) as $link ) {

                    $id         = is_array( $link['ref'] ?? null ) ? (string) ( $link['ref']['reportId'] ?? '' ) : '';
                    $definition = $id !== '' ? \OWA\Core\CoreAPI::getReportDefinition( $id ) : null;

                    if ( empty( $definition['json'] ) ) {

                        continue;
                    }

                    $controller = new \OWA\Core\ConfiguredReport( [] );
                    $controller->setDefinition( (array) json_decode(
                        (string) file_get_contents( $definition['json'] ), true ) );

                    $this->assertSame( (string) $link['priviledge'], $controller->getRequiredCapability(),
                        "the nav link to '$id' and the report require different capabilities" );

                    $checked++;
                }
            }
        }

        $this->assertGreaterThan( 0, $checked, 'no nav link to a configured report was found' );
    }
}
