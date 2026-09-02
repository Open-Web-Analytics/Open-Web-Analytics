<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Settings that describe ONE observed thing now live on that thing.
 *
 * The install-wide blob was the only place several of these could be stored,
 * so a box watching two sites had to anonymize both or neither, exclude the
 * same office IPs from both, and announce new sessions to one address. That is
 * a storage limit that had leaked into the product.
 *
 * Two seams are covered here. First, the store: getSiteSetting()/
 * persistSiteSetting() had to stop reading owa_site.settings, or an edit made
 * on the Profile screen would be written to the new table and read back from
 * the old blob. Second, the READ SITES: each moved setting had to be given a
 * site to ask about, and two of them had none in scope where they were being
 * read.
 */
final class SettingTierMoveTest extends TestCase
{
    private string $key;
    private string $siteId = '';
    private string $propertyId = '';

    protected function setUp(): void
    {
        $this->key = 'tier_probe_' . substr( md5( uniqid( '', true ) ), 0, 8 );

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
        if ( ! $this->siteId ) {
            return;
        }

        foreach ( array( $this->key, 'anonymize_ips' ) as $name ) {

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

    private function source( string $path ): string
    {
        return (string) file_get_contents( OWA_DIR . $path );
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

    /** The write side of the same seam. */
    public function testPersistSiteSettingWritesAScopedRow(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::persistSiteSetting( $this->siteId, $this->key, 'written' );

        $this->assertSame(
            'written',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId, false ),
            'persistSiteSetting() did not leave a row on the Profile itself.' );
    }

    /**
     * A false written through the site API has to survive.
     *
     * This is why the write does not go through Settings::persistSetting():
     * that prunes a value equal to the code default, which at a scoped level
     * deletes the override and hands the Profile its parent's value back.
     */
    public function testAFalseWrittenThroughTheSiteApiSurvives(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting(
            'property', $this->propertyId, 'base', $this->key, true );

        \OWA\Core\CoreAPI::persistSiteSetting( $this->siteId, $this->key, false );

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
            . 'makeUrlCanonical/domain aliases/default page see stale values.' );
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

        $previous = set_error_handler( static function ( $no, $str ) {
            throw new \RuntimeException( $str );
        } );

        try {
            $this->assertSame( array(), $config->getModuleSettings( 'no_such_module_here' ) );

        } finally {
            set_error_handler( $previous );
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
     * announce_visitors and notice_email were tested in
     * Module::_registerEventHandlers, which runs at module-registration time --
     * before any event, and so before there is a site to ask about. Leaving the
     * test there would have meant the settings could only ever be install-wide
     * no matter where they were stored.
     */
    public function testTheAnnouncementDecisionIsNotMadeAtRegistrationTime(): void
    {
        $src = $this->source( 'modules/Base/Module.php' );

        $this->assertStringNotContainsString(
            "getSetting( 'base', 'announce_visitors' )", $src,
            'The handler is registered based on an install-wide read, so a per-Profile '
            . 'value cannot take effect.' );

        $handler = $this->source( 'modules/Base/Handler/NotifyHandlers.php' );

        $this->assertStringContainsString(
            "'announce_visitors', 'profile'", $handler,
            'The decision did not move to the handler, where the event names a site.' );
    }

    /**
     * Same shape, different cause: logEvent() tested log_named_users before it
     * had built the event, so $event->get() there would have been a fatal.
     */
    public function testTheNamedUserCheckRunsAfterTheEventExists(): void
    {
        $src = $this->source( 'Core/CoreAPI.php' );

        $check = strpos( $src, "'log_named_users'" );
        $built = strpos( $src, "supportClassFactory( 'base', 'event' )" );

        $this->assertIsInt( $check );
        $this->assertIsInt( $built );

        $this->assertGreaterThan( $built, $check,
            'The per-Profile named-user check sits above the line that builds $event, '
            . 'so it dereferences an undefined variable on every tracked event.' );
    }

    /** Excluding an IP is a per-Profile judgement about one office, not a box-wide one. */
    public function testTheExcludedIpCheckTakesASite(): void
    {
        $src = $this->source( 'Core/CoreAPI.php' );

        $this->assertStringContainsString(
            'isIpAddressExcluded( $ip_address, $siteId', $src,
            'isIpAddressExcluded() cannot consult a Profile.' );

        $this->assertStringContainsString(
            "isIpAddressExcluded( \$event->get('ip_address'), \$event->get('site_id') )", $src,
            'The caller has an event in hand but is not passing its site.' );
    }
}
