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
        $this->assertArrayHasKey( 'config', $data );
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
        $this->assertNotEmpty( $data['config'],
            'a real Profile has effective settings to show' );
    }

    /**
     * The redisplay. What the form sent has to come back, or the next Save
     * writes defaults over it.
     */
    public function testAValidationFailureRedisplaysWhatTheFormSent(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $controller = new \OWA\Module\Base\Controller\SitesEditSettings( array(
            'siteId' => $site_id,
            'config' => array( 'default_page_size' => '77' ),
        ) );

        $controller->errorAction();

        $data = $this->data( $controller );

        $this->assertSame( '77', $data['config']['default_page_size'] ?? null,
            'the value the admin typed must survive the redisplay' );
    }

    /**
     * ...and a field the form did not send still shows what the Profile
     * observes with, rather than an empty control.
     */
    public function testFieldsTheFormDidNotSendKeepTheirEffectiveValue(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $effective = (array) \OWA\Core\CoreAPI::getEffectiveSettings( 'profile', $site_id, 'base' );

        if ( ! $effective ) {
            $this->markTestSkipped( 'no effective settings to compare against' );
        }

        $untouched = array_key_first( $effective );

        $controller = new \OWA\Module\Base\Controller\SitesEditSettings( array(
            'siteId' => $site_id,
            'config' => array( 'default_page_size' => '77' ),
        ) );

        $controller->errorAction();

        $data = $this->data( $controller );

        $this->assertSame( $effective[ $untouched ], $data['config'][ $untouched ] ?? null,
            "$untouched was not on the post, so it should still show its effective value" );
    }

    /**
     * The wiring, not just the decision.
     *
     * overrideAction() is asserted on its own in ProfileSettingOverrideTest;
     * this is the check that action() actually consults it. Posting back a
     * value the Profile inherits must leave no row behind.
     */
    public function testSavingAnInheritedValueCreatesNoOverride(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();
        $key     = 'default_page_size';

        // Start from inheriting, whatever earlier runs left behind.
        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );

        $inherited = \OWA\Core\CoreAPI::getSetting( 'base', $key );

        $controller = new \OWA\Module\Base\Controller\SitesEditSettings( array(
            'siteId' => $site_id,
            'config' => array( $key => (string) $inherited ),
        ) );

        $controller->action();

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
            'saving the screen unchanged must not detach the Profile from what it inherits' );
    }

    /**
     * And the round trip: a real change is stored, and setting it back to the
     * inherited value removes the override rather than pinning it.
     */
    public function testChangingAValueStoresItAndSettingItBackClearsIt(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();
        $key     = 'default_page_size';

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );

        $inherited = \OWA\Core\CoreAPI::getSetting( 'base', $key );
        $changed   = (string) ( (int) $inherited + 7 );

        try {
            $save = function ( $value ) use ( $site_id, $key ) {

                $c = new \OWA\Module\Base\Controller\SitesEditSettings( array(
                    'siteId' => $site_id,
                    'config' => array( $key => $value ),
                ) );
                $c->action();
            };

            $save( $changed );

            $this->assertSame( $changed,
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                'a value that differs from the inherited one is an override' );

            $save( (string) $inherited );

            $this->assertNull(
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                'setting it back to the inherited value is the way back to inheriting' );

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );
        }
    }
}
