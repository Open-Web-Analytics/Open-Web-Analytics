<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Observation Settings screen: arriving at it, and failing validation on it.
 *
 * Two defects, both of which made the screen behave worst at the moment it was
 * least able to explain itself.
 *
 * Reaching it without a siteId threw. getByColumn() raises "No value passed."
 * on an empty value, so a bookmark or a stale link produced an uncaught
 * exception instead of a screen.
 *
 * And failing validation redrew the form from the raw request bag, which holds
 * siteId, do and nonce -- not the fields, which arrive nested under config[].
 * Every select fell to its first option and every number to its default, so
 * correcting the one reported error and saving again wrote those defaults over
 * the rest of the screen.
 */
final class ProfileSettingsScreenTest extends TestCase
{
    private function data( $controller ): array
    {
        $property = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $property->setAccessible( true );

        return (array) $property->getValue( $controller );
    }

    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the screen loads the site list and the scope chain' );
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole( 'admin' );
        $user->setAuthStatus( true );
    }

    private function aSiteId(): string
    {
        $sites = (array) \OWA\Core\CoreAPI::getSitesList();

        if ( ! $sites ) {
            $this->markTestSkipped( 'needs at least one Observation Profile' );
        }

        $first = reset( $sites );

        return (string) ( is_array( $first ) ? $first['site_id'] : $first );
    }

    public function testTheScreenRendersWithNoSiteIdInsteadOfThrowing(): void
    {
        $this->requireDb();

        $controller = new \OWA\Module\Base\Controller\ProfileSettings( array() );
        $controller->action();

        $data = $this->data( $controller );

        $this->assertArrayHasKey( 'siteId', $data );
        $this->assertArrayHasKey( 'site', $data );
        $this->assertArrayHasKey( 'hierarchy_nav', $data,
            'the wrapper reads this, and an unset view var throws in ViewScope' );
        $this->assertSame( 'base.profileSettings', $data['subview'] ?? null );
    }

    public function testWithNoSiteIdItResolvesOneTheUserCanSee(): void
    {
        $this->requireDb();
        $expected = $this->aSiteId();

        $controller = new \OWA\Module\Base\Controller\ProfileSettings( array() );
        $controller->action();

        $this->assertSame( $expected, $this->data( $controller )['siteId'],
            'the screen should show a Profile rather than nothing at all' );
    }

    public function testAnExplicitSiteIdIsStillHonoured(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $controller = new \OWA\Module\Base\Controller\ProfileSettings(
            array( 'siteId' => $site_id ) );
        $controller->action();

        $data = $this->data( $controller );

        $this->assertSame( $site_id, $data['siteId'] );
        $this->assertSame( $site_id, $data['site']['site_id'] ?? null,
            'the template reads the Profile it renders from here' );
    }

    /**
     * The redisplay. What the form sent -- each value and each switch -- has to
     * come back, or the next Save writes over it.
     */
    public function testAValidationFailureRedisplaysWhatTheFormSent(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $controller = new \OWA\Module\Base\Controller\SitesEditSettings( array(
            'siteId'   => $site_id,
            'config'   => array( 'base.default_page' => 'home.html' ),
            'override' => array( 'base.default_page' => '1' ),
        ) );

        $controller->errorAction();

        $data = $this->data( $controller );

        $this->assertSame( 'home.html', $data['posted']['config']['base.default_page'] ?? null );
        $this->assertSame( '1', $data['posted']['override']['base.default_page'] ?? null );

        $html = \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet(
            \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.profileObservation' ),
            'profile', $site_id, 'owa_', $data['posted'] );

        $this->assertMatchesRegularExpression(
            '#<input type="text" size="50" name="owa_config\[base\.default_page\]" value="home\.html" id=#', $html,
            'the typed value comes back, enabled' );
    }

    /**
     * The wiring: the screen saves through the switch. On stores the value even
     * when it equals the inherited one; off removes it.
     */
    public function testTheOverrideSwitchDecidesWhatIsStored(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();
        $key     = 'enableEcommerceReporting';

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );

        $inherited = \OWA\Module\Base\Classes\SettingsForm::inheritance( 'base', $key, 'profile', $site_id )['inherited'];

        $this->assertNotNull( $inherited, 'a boolean has a default, so it has a switch' );

        $save = function ( array $params ) use ( $site_id ) {

            $c = new \OWA\Module\Base\Controller\SitesEditSettings( array( 'siteId' => $site_id ) + $params );
            $c->action();
        };

        try {
            $save( array(
                'config'   => array( 'base.enableEcommerceReporting' => $inherited ? '1' : '0' ),
                'override' => array( 'base.enableEcommerceReporting' => '1' ),
            ) );

            $this->assertSame( (bool) $inherited,
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                'switched on, the value is this Profile\'s own even when it matches what it inherits' );

            // Switched off: the disabled control is not submitted, so nothing arrives for it.
            $save( array() );

            $this->assertNull(
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                'switched off, the Profile goes back to inheriting' );

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );
        }
    }

    /** default_page has nothing above it: a plain field, saved by its value. */
    public function testAFieldWithNothingAboveIsSavedByItsValue(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', 'default_page' );

        if ( (string) \OWA\Module\Base\Classes\SettingsForm::inheritance( 'base', 'default_page', 'profile', $site_id )['inherited'] !== '' ) {
            $this->markTestSkipped( 'this install sets default_page above the Profile' );
        }

        $save = function ( $value ) use ( $site_id ) {

            $c = new \OWA\Module\Base\Controller\SitesEditSettings( array(
                'siteId' => $site_id, 'config' => array( 'base.default_page' => $value ) ) );
            $c->action();
        };

        try {
            $save( 'index.html' );
            $this->assertSame( 'index.html',
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', 'default_page' ) );

            $save( '' );
            $this->assertNull(
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', 'default_page' ) );

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', 'default_page' );
        }
    }

    /** A setting the screen does not show cannot be written through it. */
    public function testAPostCannotReachASettingTheScreenDoesNotShow(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', 'excluded_ips' );

        $c = new \OWA\Module\Base\Controller\SitesEditSettings( array(
            'siteId'   => $site_id,
            'config'   => array( 'base.excluded_ips' => '203.0.113.9' ),
            'override' => array( 'base.excluded_ips' => '1' ),
        ) );
        $c->action();

        $this->assertNull( \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', 'excluded_ips' ) );
    }
}
