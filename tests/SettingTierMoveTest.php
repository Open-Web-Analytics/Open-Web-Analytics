<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/DeclaresProbeSettings.php';

/**
 * Settings that describe ONE observed thing live on that thing, so a box
 * watching two sites can anonymize, exclude office IPs and announce new
 * sessions per site.
 *
 * Two seams are covered here. First, the store: getSiteSetting() reads the
 * scoped table, not owa_site.settings, so an edit made on the Profile screen
 * is read back from where it was written. Second, the READ SITES: each of
 * these settings is read with a site to ask about.
 */
final class SettingTierMoveTest extends TestCase
{
    use DeclaresProbeSettings;

    private string $key;
    private string $siteId = '';
    private string $propertyId = '';

    protected function setUp(): void
    {
        $this->key = 'tier_probe_' . substr( md5( uniqid( '', true ) ), 0, 8 );

        // Declared, as only a declared setting may be stored at a scope.
        $this->declareProbe( 'base', $this->key,
            [ 'storable' => true, 'scopes' => [ 'install', 'organization', 'property', 'profile' ] ] );

        if ( ! owa_test_db_available() ) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id, property_id' );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! empty( $row['property_id'] ) ) {

                $this->siteId     = $row['site_id'];
                $this->propertyId = $row['property_id'];
                break;
            }
        }
    }

    protected function tearDown(): void
    {
        $this->forgetProbes();

        if ( ! $this->siteId ) {
            return;
        }

        foreach ( [ $this->key, 'anonymize_ips', 'log_named_users' ] as $name ) {

            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', $name );
            \OWA\Core\CoreAPI::clearScopedSetting( 'property', $this->propertyId, 'base', $name );
        }
    }

    private function skipUnlessParented(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a Profile with a Property.' );
        }
    }

    /* ------------------------------------------------------------------ *
     * One store, one reader
     * ------------------------------------------------------------------ */

    /**
     * The seam that would have been silent.
     *
     * Update022 COPIED owa_site.settings into scoped rows. With the screens
     * writing rows and this reader on the blob, an edit would save, report
     * success, and change nothing any caller could see.
     */
    public function testGetSiteSettingReadsTheScopedStore(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $this->key, 'from-the-row' );

        $this->assertSame(
            'from-the-row',
            \OWA\Core\CoreAPI::getSiteSetting( $this->siteId, $this->key ),
            'getSiteSetting() answered from owa_site.settings, so writes and reads '
            . 'are looking at two different stores.' );
    }

    /**
     * A false written on a Profile has to survive.
     *
     * This is why a scoped write does not go through Settings::persistSetting():
     * that prunes a value equal to the code default, which at a scoped level
     * deletes the override and hands the Profile its parent's value back.
     */
    public function testAFalseWrittenOnAProfileSurvives(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'property', $this->propertyId, 'base', $this->key, true );

        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', $this->key, false );

        $this->assertFalse(
            \OWA\Core\CoreAPI::getSiteSetting( $this->siteId, $this->key ),
            'The Profile fell back to its Property, so "off" could not be saved.' );
    }

    /** What the blob could never do: a Profile with no value of its own. */
    public function testASiteSettingInheritsFromItsProperty(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'property', $this->propertyId, 'base', $this->key, 'from-property' );

        $this->assertSame(
            'from-property',
            \OWA\Core\CoreAPI::getSiteSetting( $this->siteId, $this->key ) );
    }

    /**
     * An unknown site must NOT pick up install-wide values.
     *
     * The scope walk ends by falling through to the install default, which is
     * right for a Profile that exists and has set nothing. For a site id that
     * names nothing it would mean an event naming an unregistered site quietly
     * observing with this installation's settings.
     */
    public function testAnUnknownSiteAnswersNothingRatherThanTheInstallValue(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $installValue = \OWA\Core\CoreAPI::getSetting( 'base', 'timezone' );

        $this->assertNotEmpty( $installValue, 'Needs an install value to be a real test.' );

        $this->assertEmpty(
            \OWA\Core\CoreAPI::getSiteSetting(
                'no-such-site-' . bin2hex( random_bytes( 8 ) ), 'timezone' ),
            'A site id naming no site inherited the install value.' );
    }

    /** The entity accessor answers the same way, since TEH reads through it. */
    public function testTheSiteEntityAccessorAlsoReadsTheStore(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $this->key, 'via-entity' );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $this->siteId ) );

        $this->assertSame( 'via-entity', $site->getSiteSetting( $this->key ),
            'Site::getSiteSetting() still reads its own settings blob, so '
            . 'makeUrlCanonical sees stale values.' );
    }

    /**
     * The settings FORM needs a whole map, not one key at a time.
     *
     * Otherwise the controller carries a hardcoded key list that has to be
     * kept in step with the template by hand.
     */
    public function testTheEffectiveMapLayersInstallThenPropertyThenProfile(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'property', $this->propertyId, 'base', $this->key, 'property-value' );

        $map = \OWA\Core\CoreAPI::getEffectiveSettings( 'profile', $this->siteId, 'base' );

        $this->assertSame( 'property-value', $map[ $this->key ] ?? null,
            'An inherited value was missing from the map, so the form would render it blank '
            . 'and saving would write a blank over it.' );

        $this->assertArrayHasKey( 'timezone', $map,
            'Install-wide keys were dropped, so the form only shows overridden settings.' );

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $this->key, 'profile-value' );

        $map = \OWA\Core\CoreAPI::getEffectiveSettings( 'profile', $this->siteId, 'base' );

        $this->assertSame( 'profile-value', $map[ $this->key ] ?? null,
            'The Profile row did not win over its Property.' );
    }

    /**
     * The resolver is memoized, so a write in the same request has to bust it.
     *
     * Caching was not optional: the tracking path reads four scoped settings
     * per event and each read walks the hierarchy. But a stale read is a wrong
     * answer, and the obvious victim is a settings screen that saves and then
     * re-renders from cache.
     */
    public function testAWriteIsVisibleToTheNextReadInTheSameRequest(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $this->key, 'first' );

        $this->assertSame( 'first',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ) );

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $this->key, 'second' );

        $this->assertSame( 'second',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ),
            'The cache served the pre-write value, so a save would appear not to take '
            . 'until the next request.' );
    }

    /** A miss is cached too, and clearing a row has to invalidate that. */
    public function testClearingIsVisibleToTheNextReadInTheSameRequest(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'property', $this->propertyId, 'base', $this->key, 'inherited' );
        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $this->key, 'mine' );

        $this->assertSame( 'mine',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ) );

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', $this->key );

        $this->assertSame( 'inherited',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ),
            'The cleared row was still cached, so returning to inheriting did not take.' );
    }

    /** fetch() warns on a module with no map; the screens iterate one. */
    public function testAModuleWithNoSettingsMapAnswersEmptyWithoutAWarning(): void
    {
        $config = \OWA\Core\CoreAPI::configSingleton();

        set_error_handler( static function ( $no, $str ) {
            throw new \RuntimeException( $str );
        } );

        try {
            $this->assertSame( [], $config->getModuleSettings( 'no_such_module_here' ) );

        } finally {
            restore_error_handler();
        }
    }

    /* ------------------------------------------------------------------ *
     * The read sites that had no site in scope
     * ------------------------------------------------------------------ */

    /**
     * ipAddressDefault() is registered as a FILTER, so the dispatcher hands it
     * ( $value, $event ) -- it just never named them. Anonymisation is now a
     * per-Profile call, so the event has to be reachable there.
     *
     * Asserted against the address this callback actually resolves rather than
     * a literal: RequestContainer snapshots $_SERVER when it is built, so a
     * test that writes REMOTE_ADDR here is not read. A first draft did exactly
     * that and still passed, because the bootstrap address happens to anonymise
     * to the value it was asserting.
     */
    public function testAnonymisationFollowsTheProfileNotTheInstall(): void
    {
        $this->skipUnlessParented();

        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( 'site_id', $this->siteId );

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', 'anonymize_ips', false );

        $raw = \OWA\Module\Base\Classes\TrackingEventHelpers::ipAddressDefault( '', $event );

        $this->assertNotEmpty( $raw, 'No address resolved, so there is nothing to mask.' );

        $masked = \OWA\Core\Lib::anonymizeIp( $raw );

        $this->assertNotSame( $raw, $masked,
            'The resolved address is already its own masked form, so this test could '
            . 'not tell the two settings apart.' );

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', 'anonymize_ips', true );

        $this->assertSame(
            $masked,
            \OWA\Module\Base\Classes\TrackingEventHelpers::ipAddressDefault( '', $event ),
            'The Profile asked for anonymised IPs and got the full address, so the '
            . 'setting is still being read install-wide.' );

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', 'anonymize_ips', false );

        $this->assertSame(
            $raw,
            \OWA\Module\Base\Classes\TrackingEventHelpers::ipAddressDefault( '', $event ),
            'A Profile that turned anonymisation off still got a masked address.' );
    }

    /**
     * The one that could not be scoped in place.
     *
     * announce_visitors and notice_email are per-Profile, and handlers are
     * registered at module-registration time, before there is a site to ask
     * about. So the handler is registered whatever the install-wide value is
     * (false by default, which is what every boot without a stored value sees).
     */
    public function testTheAnnouncementHandlerIsRegisteredWhateverTheInstallSays(): void
    {
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $classes  = [];

        foreach ( $dispatch->listenersFor( 'base.new_session' ) as $id ) {

            $listener  = $dispatch->listeners[ $id ] ?? null;
            $classes[] = is_array( $listener ) && is_object( $listener[0] ) ? get_class( $listener[0] ) : '';
        }

        $this->assertContains( \OWA\Module\Base\Handler\NotifyHandlers::class, $classes,
            'The handler is registered based on an install-wide read, so a per-Profile '
            . 'value cannot take effect.' );
    }

    /** The handler decides, from the Profile the event names. */
    public function testTheProfileDecidesWhetherASessionIsAnnounced(): void
    {
        $this->skipUnlessParented();

        $s       = \OWA\Core\CoreAPI::serviceSingleton();
        $action  = $s->getMapValue( 'actions', 'base.notifyNewSession' );
        $install = [];

        foreach ( [ 'announce_visitors', 'notice_email' ] as $name ) {
            $install[ $name ] = \OWA\Core\CoreAPI::getSetting( 'base', $name );
        }

        $s->setMapValue( 'actions', 'base.notifyNewSession',
            [ 'class_name' => RecordedAnnouncement::class, 'file' => __FILE__ ] );
        RecordedAnnouncement::$heard = [];

        $event = \OWA\Core\CoreAPI::getEventDispatch()->makeEvent( 'base.new_session' );
        $event->set( 'site_id', $this->siteId );

        try {
            \OWA\Core\CoreAPI::setSetting( 'base', 'announce_visitors', false );
            \OWA\Core\CoreAPI::setSetting( 'base', 'notice_email', '' );
            \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', 'announce_visitors', true );
            \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', 'notice_email', 'ops@owatest.example.com' );

            ( new \OWA\Module\Base\Handler\NotifyHandlers() )->notify( $event );

            $this->assertSame( [ $this->siteId ], RecordedAnnouncement::$heard,
                'The Profile asked for announcements and the install-wide value refused them.' );

            RecordedAnnouncement::$heard = [];
            \OWA\Core\CoreAPI::setSetting( 'base', 'announce_visitors', true );
            \OWA\Core\CoreAPI::setSetting( 'base', 'notice_email', 'ops@owatest.example.com' );
            \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', 'announce_visitors', false );

            ( new \OWA\Module\Base\Handler\NotifyHandlers() )->notify( $event );

            $this->assertSame( [], RecordedAnnouncement::$heard,
                'The Profile turned announcements off and the install-wide value sent one.' );
        } finally {
            $s->setMapValue( 'actions', 'base.notifyNewSession', $action );

            foreach ( $install as $name => $value ) {
                \OWA\Core\CoreAPI::setSetting( 'base', $name, $value );
                \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', $name );
            }
        }
    }

    /**
     * Same shape, different cause: logEvent() tested log_named_users before it
     * had built the event, so $event->get() there would have been a fatal.
     */
    public function testTheNamedUserCheckRunsAfterTheEventExists(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', 'log_named_users', false );

        $this->assertTrue( \OWA\Core\CoreAPI::isTrackingEventType( 'page_view' ) );

        $cu  = \OWA\Core\CoreAPI::getCurrentUser();
        $was = $cu->getUserData( 'user_id' );
        $cu->setUserData( 'user_id', 'tier_probe_user' );

        try {
            // A raw array, as a beacon arrives: the check reads the site_id of the event built from it.
            $this->assertFalse(
                \OWA\Core\CoreAPI::logEvent( 'page_view', [ 'site_id' => $this->siteId ] ),
                'A signed-in user was logged on a Profile that does not log named users.' );
        } finally {
            $cu->setUserData( 'user_id', $was );
        }
    }

    /** Excluding an IP is a per-Profile judgement about one office, not a box-wide one. */
    public function testTheExcludedIpListIsTheEventsProfiles(): void
    {
        $this->skipUnlessParented();

        $ip      = \OWA\Module\Base\Classes\TrackingEventHelpers::requestIp();
        $saved   = [];
        $dir     = sys_get_temp_dir() . '/owa-tier-ip-' . bin2hex( random_bytes( 4 ) ) . '/';

        $this->assertNotSame( '', $ip, 'The request has no address to exclude.' );

        foreach ( [ 'excluded_ips', 'queue_tracking_events' ] as $name ) {
            $saved[ $name ] = \OWA\Core\CoreAPI::getSetting( 'base', $name );
        }

        \OWA\Module\Base\Classes\TrackerIngest::$queue = new \OWA\Module\Base\Classes\FileEventQueue( [ 'path' => $dir ] );

        $queued = static function () use ( $dir ): int {
            return is_file( $dir . 'events.txt' )
                ? count( array_filter( file( $dir . 'events.txt', FILE_IGNORE_NEW_LINES ) ) ) : 0;
        };

        try {
            \OWA\Core\CoreAPI::setSetting( 'base', 'excluded_ips', '' );
            \OWA\Core\CoreAPI::setSetting( 'base', 'queue_tracking_events', true );
            \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', 'excluded_ips', '198.51.100.1, ' . $ip );

            $this->assertTrue( (bool) \OWA\Core\CoreAPI::isIpAddressExcluded( $ip, $this->siteId ),
                'isIpAddressExcluded() did not consult the Profile.' );
            $this->assertFalse( (bool) \OWA\Core\CoreAPI::isIpAddressExcluded( $ip ),
                'The install-wide list is empty.' );

            \OWA\Core\CoreAPI::logEvent( 'page_view', [ 'site_id' => $this->siteId, 'page_location' => 'https://tier.test/a' ] );

            $this->assertSame( 0, $queued(), 'An event from an address its Profile excludes was queued.' );

            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', 'excluded_ips' );

            \OWA\Core\CoreAPI::logEvent( 'page_view', [ 'site_id' => $this->siteId, 'page_location' => 'https://tier.test/a' ] );

            $this->assertSame( 1, $queued(), 'With the Profile\'s list cleared the event was still refused.' );
        } finally {
            \OWA\Module\Base\Classes\TrackerIngest::$queue = null;
            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', 'excluded_ips' );

            foreach ( $saved as $name => $value ) {
                \OWA\Core\CoreAPI::setSetting( 'base', $name, $value );
            }

            exec( 'rm -rf ' . escapeshellarg( $dir ) );
        }
    }
}

/** Records the site of each announcement it is asked to send. */
class RecordedAnnouncement extends \OWA\Core\Controller
{
    /** @var list<string> */
    public static $heard = [];

    public function action()
    {
        self::$heard[] = (string) $this->getParam( 'site' )->get( 'site_id' );
    }
}
