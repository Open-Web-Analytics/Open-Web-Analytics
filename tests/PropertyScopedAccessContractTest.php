<?php

require_once( __DIR__ . '/SettingsSingletonSnapshot.php' );

use PHPUnit\Framework\TestCase;

/**
 * Every controller using PropertyScopedAccess refuses a user who holds every
 * capability but does not have the Property the request names, and allows
 * the same user once the Property is on their access list.
 *
 * The controllers are found by reading the tree, so a new one is covered
 * without being listed. Those that act on a Property and are listed below
 * must be among them.
 *
 * Users are built in memory, and the Property id is not numeric, so nothing
 * here reads the database.
 */
final class PropertyScopedAccessContractTest extends TestCase
{
    use SettingsSingletonSnapshot;

    const PROPERTY = 'owatest-contract-property';
    const SITE     = 'owatest-contract-site';
    const ROLE     = 'owatest_every_capability';

    /** Controllers that act on a Property, or on an object of one. */
    const PROPERTY_SCOPED = [
        \OWA\Core\ReportController::class,
        \OWA\Module\Base\Controller\CubeStatusDetail::class,
        \OWA\Module\Base\Controller\CustomDimensionDelete::class,
        \OWA\Module\Base\Controller\CustomDimensionEdit::class,
        \OWA\Module\Base\Controller\CustomDimensionSave::class,
        \OWA\Module\Base\Controller\CustomDimensions::class,
        \OWA\Module\Base\Controller\CustomReportDelete::class,
        \OWA\Module\Base\Controller\CustomReportEdit::class,
        \OWA\Module\Base\Controller\CustomReportMarkFavorite::class,
        \OWA\Module\Base\Controller\CustomReportSave::class,
        \OWA\Module\Base\Controller\CustomReports::class,
        \OWA\Module\Base\Controller\GoalEventDefinitionDelete::class,
        \OWA\Module\Base\Controller\GoalEventDefinitionEdit::class,
        \OWA\Module\Base\Controller\GoalEventDefinitionSave::class,
        \OWA\Module\Base\Controller\GoalEventDefinitions::class,
        \OWA\Module\Base\Controller\ProfileSettings::class,
        \OWA\Module\Base\Controller\PropertyAccess::class,
        \OWA\Module\Base\Controller\PropertyAccessUpdate::class,
        \OWA\Module\Base\Controller\PropertyDelete::class,
        \OWA\Module\Base\Controller\PropertyEdit::class,
        \OWA\Module\Base\Controller\PropertyProfile::class,
        \OWA\Module\Base\Controller\PropertyProfiles::class,
        \OWA\Module\Base\Controller\PropertyRetention::class,
        \OWA\Module\Base\Controller\PropertyRetentionUpdate::class,
        \OWA\Module\Base\Controller\RealtimeRest::class,
        \OWA\Module\Base\Controller\ReportsRest::class,
        \OWA\Module\Base\Controller\RetentionPreviewRest::class,
        \OWA\Module\Base\Controller\SiteAddAllowedUserRest::class,
        \OWA\Module\Base\Controller\SitesAdd::class,
        \OWA\Module\Base\Controller\SitesDelete::class,
        \OWA\Module\Base\Controller\SitesEdit::class,
        \OWA\Module\Base\Controller\SitesEditSettings::class,
        \OWA\Module\Base\Controller\SitesEditTagSettings::class,
        \OWA\Module\Base\Controller\SitesInvocation::class,
        \OWA\Module\Base\Controller\SitesProfile::class,
        \OWA\Module\Base\Controller\VisualizationEdit::class,
        \OWA\Module\Base\Controller\VisualizationSave::class,
        \OWA\Module\Domstream\Controller\DomstreamsRestController::class,
        \OWA\Module\Domstream\Controller\ReportDomstreams::class,
    ];

    /** @var mixed the current user before the test */
    private $previous_user;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->snapshotSettings();
        $this->settings()->addCapabilityToRole( self::ROLE,
            (array) ( \OWA\Core\CoreAPI::getSetting( 'base', 'capabilities' )['admin'] ?? [] ) );
        $this->previous_user = \OWA\Core\CoreAPI::serviceSingleton()->getCurrentUser();
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->current_user = $this->previous_user;
        $this->restoreSettings();
    }

    /**
     * The concrete controller classes that use the trait, themselves or
     * through a parent.
     *
     * @return list<class-string>
     */
    private static function traitUsers(): array
    {
        $root  = dirname( __DIR__ );
        $files = array_merge(
            glob( $root . '/Core/*.php' ) ?: [],
            glob( $root . '/Core/Controller/*.php' ) ?: [],
            glob( $root . '/modules/*/Controller/*.php' ) ?: [] );

        $found = [];

        foreach ( $files as $file ) {

            $source = (string) file_get_contents( $file );

            if ( ! preg_match( '/^namespace\s+([\w\\\\]+);/m', $source, $ns )
                 || ! preg_match( '/^(?:final\s+)?class\s+(\w+)/m', $source, $class ) ) {
                continue;
            }

            $name = $ns[1] . '\\' . $class[1];

            if ( ! class_exists( $name ) || ( new ReflectionClass( $name ) )->isAbstract() ) {
                continue;
            }

            for ( $c = $name; $c; $c = get_parent_class( $c ) ) {

                if ( in_array( \OWA\Core\PropertyScopedAccess::class, class_uses( $c ), true ) ) {
                    $found[] = $name;
                    break;
                }
            }
        }

        sort( $found );

        return $found;
    }

    /** A signed-in user of a role holding every capability, granted these Profiles. */
    private function user( array $grants ): \OWA\Module\Base\Classes\ServiceUser
    {
        $sites = [];

        foreach ( $grants as $siteId => $propertyId ) {
            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->set( 'site_id', $siteId );
            $site->set( 'property_id', $propertyId );
            $sites[ $siteId ] = $site;
        }

        $user = new \OWA\Module\Base\Classes\ServiceUser();
        $user->setRole( self::ROLE );
        $user->setUserData( 'user_id', 'owatest-contract' );
        $user->setAuthStatus( true );
        $user->assignedSites = $sites;

        \OWA\Core\CoreAPI::serviceSingleton()->current_user = $user;

        return $user;
    }

    private static function allows( string $class, \OWA\Module\Base\Classes\ServiceUser $user ): bool
    {
        $controller = new $class( [ 'propertyId' => self::PROPERTY ] );

        return (bool) ( new ReflectionMethod( $controller, 'isCurrentUserCapable' ) )->invoke( $controller, $user );
    }

    public function testEveryPropertyScopedControllerUsesTheTrait(): void
    {
        $missing = array_diff( self::PROPERTY_SCOPED, self::traitUsers() );

        $this->assertSame( [], array_values( $missing ), 'acts on a Property without PropertyScopedAccess' );
    }

    public function testEveryTraitUserRefusesTheCapabilityWithoutAccess(): void
    {
        $users = self::traitUsers();

        $this->assertGreaterThan( count( self::PROPERTY_SCOPED ), count( $users ), 'the tree was not read' );

        $without = $this->user( [] );

        $this->assertTrue( $without->isCapable( 'edit_sites' ), 'precondition: the role holds the capabilities' );

        foreach ( $users as $class ) {
            $this->assertFalse( self::allows( $class, $without ), $class . ' allowed a Property not on the access list' );
        }

        $with = $this->user( [ self::SITE => self::PROPERTY ] );

        foreach ( $users as $class ) {
            $this->assertTrue( self::allows( $class, $with ), $class . ' refused a Property on the access list' );
        }
    }
}
