<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * The custom report roster, page and nav entry, requires view_reports on the
 * Property it is of.
 *
 * Users are built in memory: a role, and the Profiles granted to it as
 * assignedSites. The Property ids are not numeric, so an administrator's
 * Profile lookup (Cubes::siteIds()) answers none without a query.
 */
final class CustomReportsRosterAccessTest extends TestCase
{
    use SettingsSingletonSnapshot;

    const PROPERTY_A = 'owatest-property-a';
    const PROPERTY_B = 'owatest-property-b';
    const SITE_A     = 'owatest-site-a';

    /** Holds view_site_list and nothing that reads reports. */
    const LISTER_ROLE = 'owatest_lister';

    /** @var mixed the current user before the test */
    private $previous_user;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
        $this->settings()->addCapabilityToRole( self::LISTER_ROLE, 'view_site_list' );
        $this->previous_user = \OWA\Core\CoreAPI::serviceSingleton()->getCurrentUser();
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->current_user = $this->previous_user;
        $this->restoreSettings();
    }

    /** A signed-in user of the role, granted a Profile of Property A. */
    private function user( string $role ): \OWA\Module\Base\Classes\ServiceUser
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->set( 'site_id', self::SITE_A );
        $site->set( 'property_id', self::PROPERTY_A );

        $user = new \OWA\Module\Base\Classes\ServiceUser();
        $user->setRole( $role );
        $user->setUserData( 'user_id', 'owatest-' . $role );
        $user->setAuthStatus( true );
        $user->assignedSites = $role === 'admin' ? [] : [ self::SITE_A => $site ];

        \OWA\Core\CoreAPI::serviceSingleton()->current_user = $user;

        return $user;
    }

    private function mayOpen( \OWA\Module\Base\Classes\ServiceUser $user, string $propertyId ): bool
    {
        $controller = new \OWA\Module\Base\Controller\CustomReports( [ 'propertyId' => $propertyId ] );

        return (bool) ( new ReflectionMethod( $controller, 'isCurrentUserCapable' ) )->invoke( $controller, $user );
    }

    /** The rail's sections for a Property, from the registered navigation and reportNavigation(). */
    private function railIds( \OWA\Module\Base\Classes\ServiceUser $user, string $propertyId, bool $registeredOnly ): array
    {
        $nav = $registeredOnly
            ? (array) \OWA\Core\CoreAPI::getGroupNavigation( 'Reports' )
            : (array) \OWA\Core\ReportController::reportNavigation( $propertyId );

        $rail = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' )
            ->navRail( $nav, $propertyId, [] );

        return array_column( $rail, 'id' );
    }

    public function testTheRosterIsRequiredToBeViewReports(): void
    {
        $this->assertSame( 'view_reports',
            ( new \OWA\Module\Base\Controller\CustomReports( [] ) )->getRequiredCapability() );

        $registered = (array) \OWA\Core\CoreAPI::getGroupNavigation( 'Reports' );
        $this->assertSame( 'view_reports', $registered['Custom Reports']['priviledge'] ?? null );
    }

    public function testAViewerOpensTheRosterOfTheirPropertyOnly(): void
    {
        $viewer = $this->user( 'viewer' );

        $this->assertTrue( $this->mayOpen( $viewer, self::PROPERTY_A ) );
        $this->assertFalse( $this->mayOpen( $viewer, self::PROPERTY_B ) );

        $this->assertContains( 'Custom Reports', $this->railIds( $viewer, self::PROPERTY_A, true ) );
        $this->assertNotContains( 'Custom Reports', $this->railIds( $viewer, self::PROPERTY_B, true ) );
        $this->assertNotContains( 'Custom Reports', $this->railIds( $viewer, self::PROPERTY_B, false ) );
    }

    public function testARoleWithoutViewReportsGetsNeitherTheLinkNorThePage(): void
    {
        $lister = $this->user( self::LISTER_ROLE );

        $this->assertTrue( $lister->isCapable( 'view_site_list' ) );
        $this->assertFalse( $this->mayOpen( $lister, self::PROPERTY_A ), 'the roster opened without view_reports' );

        $this->assertNotContains( 'Custom Reports', $this->railIds( $lister, self::PROPERTY_A, true ) );
        $this->assertNotContains( 'Custom Reports', $this->railIds( $lister, self::PROPERTY_A, false ),
            'the rail links to a roster the reader cannot open' );
    }

    public function testAnAdministratorOpensAnyPropertysRoster(): void
    {
        $admin = $this->user( 'admin' );

        $this->assertTrue( $this->mayOpen( $admin, self::PROPERTY_A ) );
        $this->assertTrue( $this->mayOpen( $admin, self::PROPERTY_B ) );

        $this->assertContains( 'Custom Reports', $this->railIds( $admin, self::PROPERTY_B, true ) );
    }
}
