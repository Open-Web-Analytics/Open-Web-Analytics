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
     * report screens not yet moved. A block of data
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
        'Base/templates/report.php'                 => 2,
        'Base/templates/report_realtime.php'        => 1,
        'Base/templates/report_widgets.php'         => 7,
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
        $this->assertContains( 'visits', array_column( $config['metrics'], 'name' ) );
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

    public function testTheBellIsHandedAUrlPerMethod(): void
    {
        $this->requireDb();

        $html = $this->screen( '\\OWA\\Module\\Base\\Controller\\CustomReports', [ 'siteId' => self::$siteId ] );

        foreach ( [ 'read', 'mark-read', 'dismiss' ] as $which ) {

            $this->assertSame( 1, preg_match( '~data-owa-notifications-' . $which . '="([^"]+)"~', $html, $m ), "no $which URL" );
            $this->assertStringContainsString( 'notifications', html_entity_decode( $m[1] ) );
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
}
