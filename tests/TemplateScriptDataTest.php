<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A screen's behaviour is in the reporting bundle (modules/Base/src/reporting),
 * and its template hands it data: a <script type="application/json"> blob or
 * data-* attributes, never PHP inside JavaScript.
 *
 * The data blobs are read back from the rendered screens, so what the
 * JavaScript is handed is the server's own lists.
 */
final class TemplateScriptDataTest extends TestCase
{
    /**
     * The templates that still carry inline JavaScript, and how many blocks.
     *
     * The tracker tags and the overlay launcher are R6's; the rest are the
     * report screens not yet moved. report.php's one block creates
     * OWA.items[dom_id] while the page parses, which a report's subview may
     * read from its own inline script. A block of data
     * (type="application/json") or a script loaded by src is not code here.
     */
    private const INLINE_CODE_LEFT = [
        'Base/templates/custom_reports.php'         => 1,
        'Base/templates/head.php'                   => 1,
        'Base/templates/js_bundle_tag.php'          => 1,
        'Base/templates/js_log_tag.php'             => 2,
        'Base/templates/msgs.php'                   => 1,
        'Base/templates/nav_shell.php'              => 1,
        'Base/templates/property_access.php'        => 1,
        'Base/templates/report.php'                 => 1,
        'Base/templates/report_realtime.php'        => 1,
        'Base/templates/sites_invocation.php'       => 1,
        'Base/templates/visualization_funnel.php'   => 1,
        'Domstream/templates/report_domstreams.php' => 1,
    ];

    private static $propertyId = '';
    private static $siteId     = '';
    private static $siteRowId  = '';

    public static function setUpBeforeClass(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        $sm   = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );
        $site = $sm->createSite( 'script-data-' . uniqid() . '.example.com', 'Script data fixture' );

        self::$propertyId = (string) $site->get( 'property_id' );
        self::$siteId     = (string) $site->get( 'site_id' );
        self::$siteRowId  = (string) $site->get( 'id' );
    }

    public static function tearDownAfterClass(): void
    {
        if ( self::$siteRowId !== '' ) {
            \OWA\Core\CoreAPI::entityFactory( 'base.site' )->delete( self::$siteRowId );
        }

        if ( self::$propertyId !== '' ) {
            \OWA\Core\CoreAPI::entityFactory( 'base.property' )->delete( self::$propertyId );
        }
    }

    protected function setUp(): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'Rendering a screen loads the site list.' );
        }
    }

    /**
     * Inline <script> blocks that are code, per template, in source order.
     *
     * @return array<string, int>  template (relative to modules/) => blocks
     */
    private static function inlineCode(): array
    {
        $out = [];

        foreach ( glob( \OWA\Core\Env::modulesDir() . '*/templates/*.php' ) as $file ) {

            preg_match_all( '~<script\b([^>]*)>~i', (string) file_get_contents( $file ), $tags );

            $code = array_filter( $tags[1], static function ( $attrs ) {

                return ! preg_match( '~\bsrc\s*=|\btype\s*=\s*"application/json"~i', $attrs );
            } );

            if ( $code ) {
                $out[ str_replace( \OWA\Core\Env::modulesDir(), '', $file ) ] = count( $code );
            }
        }

        ksort( $out );

        return $out;
    }

    public function testOnlyTheListedTemplatesStillCarryInlineCode(): void
    {
        $expected = array_filter( self::INLINE_CODE_LEFT );
        ksort( $expected );

        $this->assertSame( $expected, self::inlineCode() );
    }

    /** The JSON a rendered screen hands its module, by the element's data-ui. */
    private function config( string $html, string $hook ): array
    {
        $pattern = '~<script type="application/json" data-ui="' . preg_quote( $hook, '~' ) . '">(.*?)</script>~s';

        $this->assertSame( 1, preg_match( $pattern, $html, $m ), "no $hook data" );

        $data = json_decode( $m[1], true );

        $this->assertIsArray( $data, "$hook is not JSON" );

        return $data;
    }

    /** A screen's body, rendered through its controller and view as index.php does. */
    private function screen( string $controller, array $params ): string
    {
        $data = (array) ( new $controller( $params ) )->doAction();
        $view = \OWA\Core\CoreAPI::viewFactory( $data['view'], $data );
        $view->setData( $data );

        return (string) $view->assembleView( $data );
    }

    public function testTheReportEditorIsHandedTheServersOwnLists(): void
    {
        $this->requireDb();

        $html   = $this->screen( '\\OWA\\Module\\Base\\Controller\\CustomReportEdit', [ 'siteId' => self::$siteId ] );
        $config = $this->config( $html, 'custom-report-editor:config' );

        $this->assertSame( \OWA\Module\Base\Classes\CustomReports::timeDimensions(), $config['timeDimensions'] );
        $this->assertSame( \OWA\Module\Base\Classes\CustomReports::CHART_TYPES, $config['chartTypes'] );
        $this->assertSame( \OWA\Module\Base\Classes\CustomReports::UNGROUPED_TYPES, $config['ungroupedTypes'] );
        $this->assertSame( \OWA\Module\Base\Classes\CustomReports::FULL_WIDTH_TYPES, $config['fullWidthTypes'] );
        $this->assertSame( \OWA\Module\Base\Classes\CustomReports::MORE_LABEL, $config['moreLabel'] );
        $this->assertSame( \OWA\Core\ReportGrid::COLUMNS, $config['columns'] );
        $this->assertSame( array_keys( \OWA\Module\Base\Classes\CustomReports::WIDTHS ), array_column( $config['widths'], 0 ) );
        $this->assertNotEmpty( $config['metrics'] );

        foreach ( $config['metrics'] as $choice ) {
            $this->assertTrue( \OWA\Module\Base\Classes\CustomReports::isMetric( $choice['name'] ), "{$choice['name']} is not a metric" );
        }
        $this->assertGreaterThan( 0, $config['maxWidgets'] );
        $this->assertSame( [], $config['definition'], 'a new report starts empty' );
    }

    public function testTheFunnelBuilderIsHandedItsPreviewLink(): void
    {
        $this->requireDb();

        $html   = $this->screen( '\\OWA\\Module\\Base\\Controller\\VisualizationEdit', [ 'propertyId' => self::$propertyId ] );
        $config = $this->config( $html, 'funnel-editor:config' );

        $this->assertStringContainsString( 'base.visualizationPreview', $config['previewUrl'] );
        $this->assertStringContainsString( 'last_thirty_days', $config['previewUrl'] );
        $this->assertGreaterThan( 0, $config['maxSteps'] );
        $this->assertIsArray( $config['dimensions'] );
    }

    /** Each URL carries the nonce for the method its call uses: a nonce is minted for one method (RestNonceMethodTest). */
    public function testTheBellIsHandedAUrlPerMethod(): void
    {
        $this->requireDb();

        $html = $this->screen( '\\OWA\\Module\\Base\\Controller\\CustomReports', [ 'siteId' => self::$siteId ] );

        foreach ( [ 'read' => 'GET', 'mark-read' => 'POST', 'dismiss' => 'DELETE' ] as $which => $method ) {

            $this->assertSame( 1, preg_match( '~data-owa-notifications-' . $which . '="([^"]+)"~', $html, $m ), "no $which URL" );
            parse_str( (string) parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
            $this->assertSame( 'notifications', $query['do'] ?? null, "$which URL names another route" );
            $this->assertSame( \OWA\Core\CoreAPI::createRestApiNonce( 'v1', 'base', 'notifications', $method ), $query['nonce'] ?? null,
                "the $which URL does not carry the nonce minted for $method" );
        }
    }

    /** Every page with the header loads the bundle its menus are in, an error page included. */
    public function testAnErrorPageLoadsTheHeadersCode(): void
    {
        $this->requireDb();

        $data = [ 'view' => 'base.error', 'error_msg' => 'Refused.' ];
        $view = \OWA\Core\CoreAPI::viewFactory( 'base.error', $data );
        $view->setData( $data );
        $html = (string) $view->assembleView( $data );

        $this->assertStringContainsString( 'id="owa_helpMenuToggle"', $html );
        $this->assertStringContainsString( 'base/dist/owa.reporting-combined-min.js', $html );
    }

    /** Every element of a hook's data on a rendered page, in document order. */
    private function configs( string $html, string $hook ): array
    {
        preg_match_all( '~<script type="application/json" data-ui="' . preg_quote( $hook, '~' ) . '">(.*?)</script>~s', $html, $m );

        return array_map( static function ( $json ) {

            return json_decode( $json, true );
        }, $m[1] );
    }

    /**
     * A shipped report's page, drawn as though its Property had reporting data
     * (a fixture Property has no cube, and the page would say so instead).
     *
     * @param array<string, array<string, string>>|null $metricSets the site's sets, when given
     */
    private function report( string $reportId, ?array $metricSets = null ): string
    {
        $controller = new \OWA\Core\ConfiguredReport(
            [ 'reportId' => $reportId, 'propertyId' => self::$propertyId, 'period' => 'last_thirty_days' ] );

        $controller->setDefinition( (array) json_decode( (string) file_get_contents(
            \OWA\Core\Env::modulesDir() . 'Base/reports/' . $reportId . '.json' ), true ) );

        ( new \ReflectionProperty( \OWA\Core\ReportController::class, 'reads_reporting_data' ) )->setValue( $controller, false );

        $data = (array) $controller->doAction();

        if ( $metricSets !== null ) {
            $data['metricSets'] = $metricSets;
        }

        return (string) \OWA\Core\CoreAPI::displayView( $data );
    }

    /**
     * Each widget is handed over as the global its explorer is published as,
     * the element it draws into, its API link and what it is told to draw;
     * the set names the trend and grid that are linked.
     */
    public function testAReportHandsOverEachWidget(): void
    {
        $this->requireDb();

        $html    = $this->report( 'pages' );
        $widgets = array_column( $this->configs( $html, 'report-widget:config' ), null, 'name' );

        $this->assertSame( [ 'trend', 'dim' ], array_keys( $widgets ) );

        foreach ( $widgets as $name => $widget ) {

            $this->assertStringContainsString( 'id="' . $widget['container'] . '"', $html, "$name draws into an element the page does not have" );
            $this->assertTrue( $widget['load'], "$name is not loaded" );

            parse_str( (string) parse_url( $widget['url'], PHP_URL_QUERY ), $query );
            $this->assertSame( 'reports', $query['do'] ?? null, "$name queries another route" );
            $this->assertNotEmpty( $query['nonce'] ?? '', "$name's API link carries no nonce" );
        }

        $this->assertSame( 'trend-chart', $widgets['trend']['container'] );
        $this->assertSame( [ 'makeAreaChart', [ [ 'x' => 'date', 'y' => 'pageViews' ] ], 'trend-chart' ], $widgets['trend']['queue'][0] );
        $this->assertSame( [ 'makeMetricBoxes', 'trend-metrics' ], $widgets['trend']['queue'][1] );
        $this->assertSame( 'sessions', $widgets['trend']['options']['sparkline']['metric'] );
        $this->assertSame( [ [ 'refreshGrid' ] ], $widgets['dim']['queue'] );
        $this->assertNotEmpty( $widgets['dim']['options']['grid']['rowActions'] );

        $sets = $this->configs( $html, 'report-widgets:set' );

        $this->assertCount( 1, $sets );
        $this->assertSame( [ 'trend', 'dim' ], $sets[0]['link'] );
        $this->assertArrayNotHasKey( 'tab', $sets[0], 'a report with metrics of its own is not drawn per set' );
        $this->assertSame( [], $this->configs( $html, 'report-widgets:tabs' ) );
    }

    /**
     * A report drawn per metric set hands over each set's tab and the result
     * sets it loads; its widgets wait for the tab, and the tabs are built for
     * the report report.php creates.
     */
    public function testAReportDrawnPerMetricSetHandsOverItsTabs(): void
    {
        $this->requireDb();

        $html = $this->report( 'browsers', [
            'site_usage' => [ 'label' => 'Site Usage', 'metrics' => 'sessions', 'chartMetric' => 'sessions' ],
            'ecommerce'  => [ 'label' => 'Sales & returns', 'metrics' => 'transactions', 'chartMetric' => 'transactions' ],
        ] );

        $this->assertSame( 1, preg_match( "~OWA\.items\['([^']+)'\] = new OWA\.report\(\);~", $html, $m ) );
        $domId = $m[1];

        $widgets = array_column( $this->configs( $html, 'report-widget:config' ), null, 'name' );
        $sets    = $this->configs( $html, 'report-widgets:set' );

        $this->assertSame( [ 'owaSet_site_usage', 'owaSet_ecommerce' ], array_map( static function ( $set ) {

            return $set['tab']['name'];
        }, $sets ) );

        // The label as written: the tab draws it as text.
        $this->assertSame( 'Sales & returns', $sets[1]['tab']['label'] );

        foreach ( $sets as $set ) {

            $this->assertSame( $domId, $set['report'] );
            $this->assertStringContainsString( 'id="' . $set['tab']['panel'] . '"', $html );
            $this->assertNotSame( '', $set['tab']['label'] );
            $this->assertNotEmpty( $set['tab']['rses'] );

            foreach ( $set['tab']['rses'] as [ $key, $name ] ) {

                $this->assertIsString( $key );
                $this->assertArrayHasKey( $name, $widgets, "the tab loads $name, which was not handed over" );
                $this->assertFalse( $widgets[ $name ]['load'], "$name loads before its tab is chosen" );
            }
        }

        $this->assertSame( [ [ 'report' => $domId ] ], $this->configs( $html, 'report-widgets:tabs' ) );
    }

    /** The picker, the as-of line and the site filter are started for the report report.php creates. */
    public function testAReportHandsOverItsControls(): void
    {
        $this->requireDb();

        $html = $this->report( 'pages' );

        $this->assertSame( 1, preg_match( "~OWA\.items\['([^']+)'\] = new OWA\.report\(\);~", $html, $m ) );

        $controls = $this->configs( $html, 'report:controls' );

        $this->assertCount( 1, $controls );
        $this->assertSame( $m[1], $controls[0]['report'] );
        $this->assertTrue( $controls[0]['siteFilter'] );
        $this->assertSame( 'last_thirty_days', $controls[0]['periodPicker']['period'] ?? null );
    }
}
