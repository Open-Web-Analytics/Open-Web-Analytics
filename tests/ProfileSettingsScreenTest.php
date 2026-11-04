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
     * The refusal redraws the Observation Settings screen, which is the one
     * template that reads `posted`. It drew base.sitesProfile, the Profile's
     * details form: the reported error appeared above a form without the
     * fields it named.
     */
    public function testAValidationFailureRedrawsTheObservationSettingsScreen(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $screen = new \OWA\Module\Base\Controller\ProfileSettings( array( 'siteId' => $site_id ) );
        $screen->action();

        $refusal = new \OWA\Module\Base\Controller\SitesEditSettings( array(
            'siteId'   => $site_id,
            'config'   => array( 'base.default_page' => 'home.html' ),
            'override' => array( 'base.default_page' => '1' ),
        ) );
        $refusal->errorAction();

        $shown   = $this->data( $screen );
        $refused = $this->data( $refusal );

        $this->assertSame( 'base.profileSettings', $refused['subview'] ?? null );
        $this->assertSame( $shown['view'] ?? null, $refused['view'] ?? null );
        $this->assertSame( $site_id, $refused['siteId'] ?? null );
        $this->assertSame( $site_id, $refused['site']['site_id'] ?? null,
            'the template reads the Profile it renders from here' );

        foreach ( array( 'site_hierarchy', 'hierarchy_nav' ) as $key ) {
            $this->assertArrayHasKey( $key, $refused, 'the wrapper reads this, and an unset view var throws in ViewScope' );
        }
    }

    /** The view hands the refused post to the template, which passes it to each fieldset. */
    public function testTheScreenPassesThePostToItsFields(): void
    {
        $view = (string) file_get_contents( OWA_DIR . 'modules/Base/View/ProfileSettings.php' );

        $this->assertMatchesRegularExpression( "#body->set\(\s*'posted',\s*\\$this->get\(\s*'posted'\s*\)\s*\)#", $view );
        $this->assertStringContainsString( '$view->posted',
            (string) file_get_contents( OWA_DIR . 'modules/Base/templates/profile_settings.php' ) );
    }

    /**
     * The wiring: the screen saves through the switch. On stores the value even
     * when it equals the inherited one; off removes it.
     *
     * A field has a switch only when something above it holds a value, and
     * campaignKeys is empty by default and the one setting on this screen a
     * Property may hold. So the Property is given a value first.
     */
    /**
     * A save goes back to Observation Settings, not to the Profile's Details.
     *
     * A Profile id nothing has, so the save it runs -- every override switched
     * off -- clears no real Profile's values.
     */
    public function testASaveReturnsToTheObservationSettingsScreen(): void
    {
        $this->requireDb();

        $c = new \OWA\Module\Base\Controller\SitesEditSettings( array( 'siteId' => 'owa-no-such-profile-redirect' ) );
        $c->action();

        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $data->setAccessible( true );
        $data = (array) $data->getValue( $c );

        $this->assertSame( 'base.profileSettings', $data['do'] ?? null );
        $this->assertSame( 'owa-no-such-profile-redirect', $data['siteId'] ?? null );
    }

    public function testTheOverrideSwitchDecidesWhatIsStored(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();
        $key     = 'campaignKeys';

        $property_id = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $site_id );

        if ( $property_id === '' ) {
            $this->markTestSkipped( 'needs a Profile with a Property' );
        }

        $held = array(
            'property' => \OWA\Core\CoreAPI::getScopedSettingRow( 'property', $property_id, 'base', $key ),
            'profile'  => \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
        );

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );
        \OWA\Core\CoreAPI::setScopedSetting( 'property', $property_id, 'base', $key, array( 'source' => 'src' ) );

        $inherited = \OWA\Module\Base\Classes\SettingsForm::inheritance( 'base', $key, 'profile', $site_id )['inherited'];

        $this->assertSame( array( 'source' => 'src' ), $inherited, 'the Property\'s value is what the Profile inherits' );

        $save = function ( array $params ) use ( $site_id ) {

            $c = new \OWA\Module\Base\Controller\SitesEditSettings( array( 'siteId' => $site_id ) + $params );
            $c->action();
        };

        try {
            $save( array(
                'config'   => array( 'base.campaignKeys' => 'source=src' ),
                'override' => array( 'base.campaignKeys' => '1' ),
            ) );

            $this->assertSame( $inherited,
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                'switched on, the value is this Profile\'s own even when it matches what it inherits' );

            // Switched off: the disabled control is not submitted, so nothing arrives for it.
            $save( array() );

            $this->assertNull(
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                'switched off, the Profile goes back to inheriting' );

        } finally {
            foreach ( array( 'profile' => $site_id, 'property' => $property_id ) as $type => $id ) {

                \OWA\Core\CoreAPI::clearScopedSetting( $type, $id, 'base', $key );

                if ( $held[ $type ] !== null ) {
                    \OWA\Core\CoreAPI::setScopedSetting( $type, $id, 'base', $key, $held[ $type ] );
                }
            }
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

    /**
     * A setting the screen does not show cannot be written through it.
     * tracker_page_views is a Profile setting, but the Tracking Tag screen's.
     */
    public function testAPostCannotReachASettingTheScreenDoesNotShow(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $held = \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', 'tracker_page_views' );

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', 'tracker_page_views' );

        try {
            $c = new \OWA\Module\Base\Controller\SitesEditSettings( array(
                'siteId'   => $site_id,
                'config'   => array( 'base.tracker_page_views' => '0' ),
                'override' => array( 'base.tracker_page_views' => '1' ),
            ) );
            $c->action();

            $this->assertNull( \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', 'tracker_page_views' ) );

        } finally {
            if ( $held !== null ) {
                \OWA\Core\CoreAPI::setScopedSetting( 'profile', $site_id, 'base', 'tracker_page_views', $held );
            }
        }
    }

    /**
     * The Profile settings the tracking path and the visitor announcement read
     * at Profile scope, each with the control its declared type takes.
     */
    private const OFFERED = array(
        'anonymize_ips'     => '<select name="owa_config[base.anonymize_ips]"',
        'excluded_ips'      => '<input type="text" size="50" name="owa_config[base.excluded_ips]"',
        'log_named_users'   => '<select name="owa_config[base.log_named_users]"',
        'campaignKeys'      => '<input type="text" size="50" name="owa_config[base.campaignKeys]"',
        'announce_visitors' => '<select name="owa_config[base.announce_visitors]"',
        'notice_email'      => '<input type="text" size="50" name="owa_config[base.notice_email]"',
    );

    private function screenHtml( string $site_id = '' ): string
    {
        $html = '';

        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'profile_observation' ) as $set ) {
            $html .= \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet( $set, 'profile', $site_id, 'owa_' );
        }

        return $html;
    }

    /** For a Profile not yet saved each field shows the install's value, so no database is needed. */
    public function testTheScreenOffersEachProfileSettingWithItsControl(): void
    {
        $html = $this->screenHtml();

        foreach ( self::OFFERED as $key => $control ) {
            $this->assertStringContainsString( $control, $html, "base.$key is not offered on the Profile" );
        }

        foreach ( array( 'domain_aliases', 'query_string_filters', 'default_page' ) as $key ) {
            $this->assertStringContainsString( 'name="owa_config[base.' . $key . ']"', $html, "base.$key is still offered" );
        }

        foreach ( array( 'Pages and domains', 'Campaign parameters', 'Privacy and exclusions', 'Visitor announcements' ) as $legend ) {
            $this->assertStringContainsString( '<legend>' . $legend . '</legend>', $html );
        }
    }

    /** The template and the save controller read one list. */
    public function testTheTemplateRendersTheGroupItsControllerSaves(): void
    {
        foreach ( array( 'modules/Base/templates/profile_settings.php',
                         'modules/Base/Controller/SitesEditSettings.php' ) as $file ) {

            $code = (string) file_get_contents( OWA_DIR . $file );

            $this->assertMatchesRegularExpression( "#groupFieldSets\(\s*'profile_observation'\s*\)#", $code, $file );
            $this->assertStringNotContainsString( "registeredFieldSet( 'base.profileObservation' )", $code,
                "$file names one fieldset of the group" );
        }
    }

    /**
     * Each saves at the Profile through the screen, and is removed when
     * switched off or emptied. Override is posted for every field: a plain one
     * (nothing set above it) is saved by its value and ignores the switch.
     */
    public function testEachOfferedSettingIsSavedAtTheProfile(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $posted = array(
            'anonymize_ips'     => array( '1', true ),
            'excluded_ips'      => array( '203.0.113.9, 198.51.100.7', '203.0.113.9, 198.51.100.7' ),
            'log_named_users'   => array( '0', false ),
            'campaignKeys'      => array( 'source=src, medium=channel', array( 'source' => 'src', 'medium' => 'channel' ) ),
            'announce_visitors' => array( '1', true ),
            'notice_email'      => array( 'owner@example.test', 'owner@example.test' ),
        );

        $held     = array();
        $config   = array();
        $override = array();

        foreach ( $posted as $key => list( $value ) ) {
            $held[ $key ] = \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key );
            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );

            $config[ 'base.' . $key ]   = $value;
            $override[ 'base.' . $key ] = '1';
        }

        try {
            $c = new \OWA\Module\Base\Controller\SitesEditSettings( array(
                'siteId' => $site_id, 'config' => $config, 'override' => $override ) );
            $c->action();

            foreach ( $posted as $key => list( , $stored ) ) {
                $this->assertSame( $stored,
                    \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                    "base.$key was not stored at the Profile" );
            }

            $this->assertSame( array( 'source' => 'src', 'medium' => 'channel' ),
                \OWA\Core\CoreAPI::getSetting( 'base', 'campaignKeys', 'profile', $site_id ),
                'the reader resolves the Profile\'s value' );

            // Every switch off, and the text fields emptied as a person would.
            $c = new \OWA\Module\Base\Controller\SitesEditSettings( array(
                'siteId' => $site_id,
                'config' => array( 'base.excluded_ips' => '', 'base.campaignKeys' => '', 'base.notice_email' => '' ) ) );
            $c->action();

            foreach ( array_keys( $posted ) as $key ) {
                $this->assertNull( \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $site_id, 'base', $key ),
                    "base.$key stayed at the Profile" );
            }

        } finally {
            foreach ( $held as $key => $value ) {
                \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $site_id, 'base', $key );

                if ( $value !== null ) {
                    \OWA\Core\CoreAPI::setScopedSetting( 'profile', $site_id, 'base', $key, $value );
                }
            }
        }
    }

    /** A campaign parameter list not in role=parameter form is reported on the form. */
    public function testAMalformedCampaignParameterListIsReported(): void
    {
        $this->requireDb();
        $site_id = $this->aSiteId();

        $problems = array();

        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'profile_observation' ) as $set ) {
            $problems += \OWA\Module\Base\Classes\SettingsForm::scopedProblems( $set, 'profile', $site_id,
                array( 'base.campaignKeys' => 'sorce=src' ), array( 'base.campaignKeys' => '1' ) );
        }

        $this->assertArrayHasKey( 'base.campaignKeys', $problems );
        $this->assertStringStartsWith( 'Campaign Parameter Names is a comma-separated list of name=value pairs',
            $problems['base.campaignKeys'] );
    }
}
