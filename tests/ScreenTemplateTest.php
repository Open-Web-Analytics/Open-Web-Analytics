<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every screen whose View class only named a template renders that template,
 * under the same page title, now that its controller declares the template
 * (Controller::setView(), setSubview()). EXPECTED was recorded with the View
 * classes in place.
 *
 * Rendered through the controller's doAction() and the view the data names,
 * as index.php does, on a Property and Profile made for the test.
 */
final class ScreenTemplateTest extends TestCase
{
    private const SETTINGS = 'Open Web Analytics - OWA Options';
    private const INSTALL  = 'Installation - Open Web Analytics';

    /** label => [ the template the screen's body is, the page's <title> ] */
    private const EXPECTED = [
        'goal event definitions'     => [ 'Base/templates/goal_event_definitions.php', self::SETTINGS ],
        'goal event definition edit' => [ 'Base/templates/goal_event_definition_edit.php', self::SETTINGS ],
        'system health'              => [ 'Base/templates/system_health.php', self::SETTINGS ],
        'cube status'                => [ 'Base/templates/cube_status.php', self::SETTINGS ],
        'cube status detail'         => [ 'Base/templates/cube_status_detail.php', self::SETTINGS ],
        'organization profile'       => [ 'Base/templates/organization_profile.php', self::SETTINGS ],
        'property profiles'          => [ 'Base/templates/property_profiles.php', self::SETTINGS ],
        'property profile'           => [ 'Base/templates/property_profile.php', self::SETTINGS ],
        'new property'               => [ 'Base/templates/property_profile.php', self::SETTINGS ],
        'property retention'         => [ 'Base/templates/property_retention.php', self::SETTINGS ],
        'profile settings'           => [ 'Base/templates/profile_settings.php', self::SETTINGS ],
        'custom dimensions'          => [ 'Base/templates/custom_dimensions.php', self::SETTINGS ],
        'custom dimension edit'      => [ 'Base/templates/custom_dimension_edit.php', self::SETTINGS ],
        'options retention'          => [ 'Base/templates/options_retention.php', self::SETTINGS ],
        'options general'            => [ 'Base/templates/options_general.php', self::SETTINGS ],
        'options modules'            => [ 'Base/templates/options_modules.php', self::SETTINGS ],
        'my app passwords'           => [ 'Base/templates/my_app_passwords.php', self::SETTINGS ],
        'my profile'                 => [ 'Base/templates/my_profile.php', self::SETTINGS ],
        'users'                      => [ 'Base/templates/users.php', self::SETTINGS ],
        'maxmind options'            => [ 'MaxmindGeoip/templates/options_geoip.php', self::SETTINGS ],
        'sqs options'                => [ 'Sqs/templates/options_sqs.php', self::SETTINGS ],
        'custom reports'             => [ 'Base/templates/custom_reports.php', 'Open Web Analytics - Custom reports' ],
        'custom report edit'         => [ 'Base/templates/custom_report_edit.php', 'Open Web Analytics - New Custom Report' ],
        'visualization edit'         => [ 'Base/templates/visualization_edit.php', 'Open Web Analytics - New funnel' ],
        'realtime'                   => [ 'Base/templates/report_realtime.php', 'Open Web Analytics - Realtime' ],
        'report not ready'           => [ 'Base/templates/report_not_ready.php', 'Open Web Analytics - Pages' ],
        'report widgets'             => [ 'Base/templates/report_widgets.php', 'Open Web Analytics - Fixture report' ],
        'recordings'                 => [ 'Domstream/templates/report_domstreams.php', 'Open Web Analytics - Recordings' ],
        'install start'              => [ 'Base/templates/install_start.php', self::INSTALL ],
        'install defaults entry'     => [ 'Base/templates/install_defaults_entry.php', self::INSTALL ],
        'logout confirm'             => [ 'Base/templates/logout_confirm.php', 'Log out - Open Web Analytics' ],
        'password reset form'        => [ 'Base/templates/users_password_reset_request.php', 'Password Reset - Open Web Analytics' ],
        'password entry'             => [ 'Base/templates/users_change_password.php', 'OWA Password Entry - Open Web Analytics' ],
        'updates'                    => [ 'Base/templates/updates.php', 'Open Web Analytics' ],
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
        $site = $sm->createSite( 'screens-' . uniqid() . '.example.com', 'Screen fixture' );

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
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'Rendering a screen loads the site list.' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    /** @return array<string, array{0: callable}> */
    public static function screens(): array
    {
        $B = '\\OWA\\Module\\Base\\Controller\\';
        $P = static fn () => [ 'propertyId' => self::$propertyId ];
        $S = static fn () => [ 'siteId' => self::$siteId ];
        $none = static fn () => [];

        $run = static fn ( string $class, callable $params ) => static fn () => (array) ( new $class( $params() ) )->doAction();

        // An install screen refuses to run on an installed system, so its action() is run alone.
        $install = static fn ( string $class ) => static function () use ( $class ) {

            $controller = new $class( [] );
            $controller->action();

            return (array) $controller->data;
        };

        return [
            'goal event definitions'      => [ $run( $B . 'GoalEventDefinitions', $P ) ],
            'goal event definition edit'  => [ $run( $B . 'GoalEventDefinitionEdit', $P ) ],
            'system health'               => [ $run( $B . 'SystemHealth', $S ) ],
            'cube status'                 => [ $run( $B . 'CubeStatus', $S ) ],
            'cube status detail'          => [ $run( $B . 'CubeStatusDetail', $P ) ],
            'organization profile'        => [ $run( $B . 'OrganizationProfile', $S ) ],
            'property profiles'           => [ $run( $B . 'PropertyProfiles', $P ) ],
            'property profile'            => [ $run( $B . 'PropertyProfile', $P ) ],
            'new property'                => [ $run( $B . 'PropertyProfile', $none ) ],
            'property retention'          => [ $run( $B . 'PropertyRetention', $P ) ],
            'profile settings'            => [ $run( $B . 'ProfileSettings', $S ) ],
            'custom dimensions'           => [ $run( $B . 'CustomDimensions', $P ) ],
            'custom dimension edit'       => [ $run( $B . 'CustomDimensionEdit', $P ) ],
            'options retention'           => [ $run( $B . 'OptionsRetention', $S ) ],
            'options general'             => [ $run( $B . 'OptionsGeneral', $S ) ],
            'options modules'             => [ $run( $B . 'OptionsModules', $S ) ],
            'my app passwords'            => [ $run( $B . 'MyAppPasswords', $S ) ],
            'my profile'                  => [ $run( $B . 'MyProfile', $S ) ],
            'users'                       => [ $run( $B . 'Users', $S ) ],
            'maxmind options'             => [ $run( '\\OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoip', $S ) ],
            'sqs options'                 => [ $run( '\\OWA\\Module\\Sqs\\Controller\\OptionsSqs', $S ) ],
            'custom reports'              => [ $run( $B . 'CustomReports', $S ) ],
            'custom report edit'          => [ $run( $B . 'CustomReportEdit', $S ) ],
            'visualization edit'          => [ $run( $B . 'VisualizationEdit', $P ) ],
            'realtime'                    => [ $run( $B . 'ReportRealtime', $P ) ],
            // The fixture Property has no cube.
            'report not ready'            => [ $run( $B . 'Report', static fn () => [ 'reportId' => 'pages', 'siteId' => self::$siteId ] ) ],
            'report widgets'              => [ static function () {

                $controller = new \OWA\Core\ConfiguredReport( [ 'siteId' => self::$siteId, 'period' => 'last_thirty_days' ] );
                $controller->setDefinition( [ 'title' => 'Fixture report', 'widgets' => [ [
                    'type' => 'grid', 'id' => 'dim', 'query' => [ 'metrics' => 'sessions', 'dimensions' => 'pagePath' ] ] ] ] );
                ( new \ReflectionProperty( \OWA\Core\ReportController::class, 'reads_reporting_data' ) )->setValue( $controller, false );

                return (array) $controller->doAction();
            } ],
            'recordings'                  => [ $run( '\\OWA\\Module\\Domstream\\Controller\\ReportDomstreams', $S ) ],
            'install start'               => [ $install( $B . 'InstallStart' ) ],
            'install defaults entry'      => [ $install( $B . 'InstallDefaultsEntry' ) ],
            'logout confirm'              => [ $run( $B . 'Logout', $none ) ],
            'password reset form'         => [ $run( $B . 'PasswordResetForm', $none ) ],
            'password entry'              => [ static function () {

                $controller = new \OWA\Module\Base\Controller\UsersChangePassword( [ 'k' => 'a-key' ] );
                $controller->errorAction();

                return (array) $controller->data;
            } ],
            'updates'                     => [ static function () {

                $service = \OWA\Core\CoreAPI::serviceSingleton();
                $before  = $service->modules_needing_updates;
                $service->modules_needing_updates = [ 'base' ];

                try {
                    return (array) ( new \OWA\Module\Base\Controller\Updates( [] ) )->doAction();
                } finally {
                    $service->modules_needing_updates = $before;
                }
            } ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testTheScreenRendersItsTemplateUnderItsTitle( callable $data ): void
    {
        $label = $this->dataName();
        $data  = $data();

        $this->assertNotEmpty( $data['view'] ?? '', "$label names no view" );

        // What CoreAPI::displayView() does, keeping the view to look at.
        $view = \OWA\Core\CoreAPI::viewFactory( $data['view'], $data );
        $view->setData( $data );
        $html = (string) $view->assembleView( $data );

        $body = ! empty( $data['subview'] ) ? $view->subview->body : $view->body;
        $file = str_replace( \OWA\Core\Env::modulesDir(), '', (string) $body->file );

        $this->assertSame( 1, preg_match( '~<title>(.*?)</title>~s', $html, $m ), "$label has no <title>" );

        $rendered = ! empty( $data['subview'] ) ? (string) $view->subview_rendered : (string) $body->fetch();
        $this->assertNotSame( '', trim( $rendered ), "$label: a template that fails renders as nothing" );

        $this->assertSame( self::EXPECTED[ $label ] ?? null,
            [ $file, trim( html_entity_decode( $m[1], ENT_QUOTES ) ) ], $label );
    }
}
