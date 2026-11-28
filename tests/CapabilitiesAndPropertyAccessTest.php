<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * Two questions, asked separately.
 *
 * A capability is the role's: ServiceUser::isCapable($cap) takes no Property.
 * Property access is the user's access list: canAccessProperty($propertyId).
 * A request about a Property needs both (PropertyScopedAccess); a request
 * about none needs only the capability.
 *
 * Users are built in memory: a role, and the Profiles granted to it as
 * assignedSites. The Property ids are not numeric, so an administrator's
 * Profile lookup (Cubes::siteIds()) answers none without a query.
 */
final class CapabilitiesAndPropertyAccessTest extends TestCase
{
    use SettingsSingletonSnapshot;

    const PROPERTY_A = 'owatest-access-property-a';
    const PROPERTY_B = 'owatest-access-property-b';
    const SITE_A     = 'owatest-access-site-a';
    const SITE_B     = 'owatest-access-site-b';

    /** Manages the Properties it is given, and reads no reports. */
    const SITE_EDITOR = 'owatest_site_editor';

    /** Edits user accounts, and nothing about a Property. */
    const USER_EDITOR = 'owatest_user_editor';

    /** @var mixed the current user before the test */
    private $previous_user;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
        $this->settings()->addCapabilityToRole( self::SITE_EDITOR, [ 'view_site_list', 'edit_sites' ] );
        $this->settings()->addCapabilityToRole( self::USER_EDITOR, [ 'view_site_list', 'edit_users' ] );
        $this->previous_user = \OWA\Core\CoreAPI::serviceSingleton()->getCurrentUser();
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->current_user = $this->previous_user;
        $this->restoreSettings();
    }

    /**
     * A signed-in user of the role, granted these Profiles, made the current user.
     *
     * @param array<string, string> $grants site_id => property_id
     */
    private function user( string $role, array $grants = [] ): \OWA\Module\Base\Classes\ServiceUser
    {
        $sites = [];

        foreach ( $grants as $siteId => $propertyId ) {
            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->set( 'site_id', $siteId );
            $site->set( 'property_id', $propertyId );
            $sites[ $siteId ] = $site;
        }

        $user = new \OWA\Module\Base\Classes\ServiceUser();
        $user->setRole( $role );
        $user->setUserData( 'user_id', 'owatest-' . $role );
        $user->setAuthStatus( true );
        $user->assignedSites = $sites;

        \OWA\Core\CoreAPI::serviceSingleton()->current_user = $user;

        return $user;
    }

    private function editorOfA(): \OWA\Module\Base\Classes\ServiceUser
    {
        return $this->user( self::SITE_EDITOR, [ self::SITE_A => self::PROPERTY_A ] );
    }

    private function allows( string $class, array $params, \OWA\Module\Base\Classes\ServiceUser $user ): bool
    {
        $controller = new $class( $params );

        return (bool) ( new ReflectionMethod( $controller, 'isCurrentUserCapable' ) )->invoke( $controller, $user );
    }

    // ----------------------------------------------------------- ServiceUser

    public function testACapabilityIsTheRolesAlone(): void
    {
        $viewer = $this->user( 'viewer' );

        $this->assertTrue( $viewer->isCapable( 'view_reports' ), 'held with no Property granted' );
        $this->assertFalse( $viewer->isCapable( 'edit_sites' ) );

        $editor = $this->editorOfA();

        $this->assertTrue( $editor->isCapable( 'edit_sites' ), 'asked of no Property, and no exception' );
        $this->assertFalse( $editor->isCapable( 'view_reports' ) );
        $this->assertTrue( $editor->isCapable( '' ), 'no capability' );
    }

    public function testTheAccessListIsOfProperties(): void
    {
        $editor = $this->editorOfA();

        $this->assertTrue( $editor->canAccessProperty( self::PROPERTY_A ) );
        $this->assertFalse( $editor->canAccessProperty( self::PROPERTY_B ) );
        $this->assertFalse( $editor->canAccessProperty( '' ) );
        $this->assertFalse( $editor->canAccessProperty( null ) );

        $this->assertTrue( $editor->isSiteAccessible( self::SITE_A ) );
        $this->assertFalse( $editor->isSiteAccessible( self::SITE_B ) );
        $this->assertFalse( $editor->isSiteAccessible( null ), 'no siteId is refused, not thrown' );
        $this->assertFalse( $editor->isSiteAccessible( '' ) );
    }

    public function testACapabilityOnAPropertyNeedsBoth(): void
    {
        $editor = $this->editorOfA();

        $this->assertTrue( $editor->isCapableOnProperty( 'edit_sites', self::PROPERTY_A ) );
        $this->assertFalse( $editor->isCapableOnProperty( 'edit_sites', self::PROPERTY_B ), 'no access' );
        $this->assertFalse( $editor->isCapableOnProperty( 'view_reports', self::PROPERTY_A ), 'no capability' );
    }

    public function testAnAdministratorHoldsEveryCapabilityOnEveryProperty(): void
    {
        $admin = $this->user( 'admin' );

        foreach ( [ 'view_reports', 'edit_sites', 'create_properties', 'edit_users' ] as $cap ) {
            $this->assertTrue( $admin->isCapable( $cap ), $cap );
            $this->assertTrue( $admin->isCapableOnProperty( $cap, self::PROPERTY_B ), $cap );
        }

        $this->assertTrue( $admin->canAccessProperty( self::PROPERTY_A ) );
        $this->assertTrue( $admin->canAccessProperty( self::PROPERTY_B ) );
        $this->assertFalse( $admin->canAccessProperty( '' ), 'no Property' );
        $this->assertTrue( $admin->isSiteAccessible( self::SITE_B ) );
    }

    /**
     * @covers-setting base.capabilities
     */
    public function testACapabilityEveryoneHoldsIsHeldOnEveryProperty(): void
    {
        $this->settings()->addCapabilityToRole( 'everyone', 'view_reports' );

        $editor = $this->editorOfA();

        $this->assertTrue( $editor->isCapableOnProperty( 'view_reports', self::PROPERTY_B ) );
        $this->assertFalse( $editor->isCapableOnProperty( 'edit_sites', self::PROPERTY_B ) );
    }

    // ------------------------------------------------------------- requests

    public function testEditSitesWithoutAccessIsRefusedOnThatProperty(): void
    {
        $editor = $this->editorOfA();

        foreach ( [
            \OWA\Module\Base\Controller\PropertyAccess::class,
            \OWA\Module\Base\Controller\PropertyAccessUpdate::class,
            \OWA\Module\Base\Controller\PropertyProfiles::class,
            \OWA\Module\Base\Controller\PropertyProfile::class,
            \OWA\Module\Base\Controller\PropertyEdit::class,
            \OWA\Module\Base\Controller\SitesProfile::class,
        ] as $class ) {
            $this->assertTrue( $this->allows( $class, [ 'propertyId' => self::PROPERTY_A ], $editor ), $class . ' on A' );
            $this->assertFalse( $this->allows( $class, [ 'propertyId' => self::PROPERTY_B ], $editor ), $class . ' on B' );
        }
    }

    public function testAccessWithoutViewReportsIsRefusedOnThePropertysReports(): void
    {
        $editor = $this->user( self::SITE_EDITOR, [ self::SITE_B => self::PROPERTY_B ] );

        foreach ( [
            \OWA\Module\Base\Controller\CustomReports::class,
            \OWA\Module\Base\Controller\CustomReportMarkFavorite::class,
            \OWA\Module\Base\Controller\RealtimeRest::class,
        ] as $class ) {
            $this->assertFalse( $this->allows( $class, [ 'propertyId' => self::PROPERTY_B ], $editor ), $class );
        }

        $viewer = $this->user( 'viewer', [ self::SITE_B => self::PROPERTY_B ] );

        $this->assertTrue( $this->allows( \OWA\Module\Base\Controller\CustomReports::class,
            [ 'propertyId' => self::PROPERTY_B ], $viewer ), 'with view_reports' );
    }

    public function testAnAdministratorIsAllowedOnEveryProperty(): void
    {
        $admin = $this->user( 'admin' );

        foreach ( [
            \OWA\Module\Base\Controller\PropertyAccess::class,
            \OWA\Module\Base\Controller\CustomReports::class,
            \OWA\Module\Base\Controller\GoalEventDefinitions::class,
            \OWA\Module\Base\Controller\PropertyDelete::class,
        ] as $class ) {
            $this->assertTrue( $this->allows( $class, [ 'propertyId' => self::PROPERTY_B ], $admin ), $class );
        }
    }

    public function testARequestAboutNoPropertyNeedsOnlyTheCapability(): void
    {
        $users = $this->user( self::USER_EDITOR );

        $this->assertTrue( $this->allows( \OWA\Module\Base\Controller\Users::class, [], $users ), 'no grant needed' );
        $this->assertFalse( $this->allows( \OWA\Module\Base\Controller\Users::class, [], $this->user( 'viewer' ) ) );

        // A Property screen that names none shows none.
        $this->assertTrue( $this->allows( \OWA\Module\Base\Controller\PropertyProfiles::class, [],
            $this->user( self::SITE_EDITOR ) ) );
    }

    public function testEditSettingsWithoutAccessIsRefusedOnAPropertysSettings(): void
    {
        $this->settings()->addCapabilityToRole( self::SITE_EDITOR, 'edit_settings' );
        $editor = $this->editorOfA();

        foreach ( [
            \OWA\Module\Base\Controller\GoalEventDefinitions::class,
            \OWA\Module\Base\Controller\CustomDimensions::class,
            \OWA\Module\Base\Controller\PropertyRetention::class,
        ] as $class ) {
            $this->assertTrue( $this->allows( $class, [ 'propertyId' => self::PROPERTY_A ], $editor ), $class . ' on A' );
            $this->assertFalse( $this->allows( $class, [ 'propertyId' => self::PROPERTY_B ], $editor ), $class . ' on B' );
        }

        $this->assertFalse( $this->allows( \OWA\Module\Base\Controller\RetentionPreviewRest::class,
            [ 'property_id' => self::PROPERTY_B ], $editor ), 'the preview names its Property as property_id' );
        $this->assertTrue( $this->allows( \OWA\Module\Base\Controller\OptionsGeneral::class, [], $editor ),
            'an install screen needs no Property' );
    }

    // ------------------------------------------------------------- settings

    /** The settings nav of a screen in this Property's context. */
    private function settingsNav( string $propertyId ): array
    {
        $controller = new \OWA\Module\Base\Controller\Users( [] );

        return (array) ( new ReflectionMethod( $controller, 'getHierarchyNav' ) )->invoke( $controller, '', $propertyId );
    }

    public function testTheSettingsNavigationRendersForACustomRoleWithEditSites(): void
    {
        $this->editorOfA();

        $template = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' );

        $onA = $this->settingsNav( self::PROPERTY_A );

        $this->assertArrayHasKey( 'Property', $onA );
        $labels = array_column( array_merge( ...array_column( $template->settingsColumn( $onA ), 'items' ) ), 'label' );
        $this->assertContains( 'Access', $labels, 'edit_sites is checked with no Property, and does not throw' );

        $onB = $this->settingsNav( self::PROPERTY_B );

        $this->assertArrayNotHasKey( 'Property', $onB, 'a Property the user has no access to is not linked' );
        $this->assertArrayNotHasKey( 'Profiles', $onB );
        $this->assertIsArray( $template->settingsColumn( $onB ) );

        $links = \OWA\Core\Navigation::links( 'owatest_access_links', [
            [ 'href' => '#', 'label' => 'x', 'capability' => 'edit_sites' ],
        ] );
        $this->assertCount( 1, $links );
    }

    public function testAnAdministratorsSettingsNavigationLinksAnyProperty(): void
    {
        $this->user( 'admin' );

        $this->assertArrayHasKey( 'Property', $this->settingsNav( self::PROPERTY_B ) );
    }

    public function testTheRailIsOfTheProperty(): void
    {
        $nav = [ 'pages' => [ 'anchortext' => 'Pages', 'priviledge' => 'view_reports',
            'ref' => [ 'do' => 'base.report', 'reportId' => 'pages' ] ] ];

        $template = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' );

        $this->user( 'viewer', [ self::SITE_A => self::PROPERTY_A ] );

        $this->assertContains( 'pages', array_column( $template->navRail( $nav, self::PROPERTY_A ), 'id' ) );
        $this->assertNotContains( 'pages', array_column( $template->navRail( $nav, self::PROPERTY_B ), 'id' ) );
    }
}
