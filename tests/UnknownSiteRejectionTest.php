<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Tracking must refuse an event naming a site this installation does not have.
 *
 * WHY IT MATTERS
 * The site id arrives in the request and was never checked, so an event naming
 * a site that does not exist was recorded in full: fact rows, sessions, the lot.
 * Nothing ever reads them -- reporting is entered through a site, and the site
 * cannot be selected -- and nothing ever removes them. Two real installations
 * were carrying 165 and 15,173 such rows.
 *
 * It also made tracking an unauthenticated write path: anyone could post events
 * naming any site id and add rows indefinitely, invisible in every report while
 * consuming partitions and open-file budget.
 *
 * The values are not hypothetical. Observed in production data:
 * 'yoursiteidgoeshere' and 'your_site_id' -- the documentation placeholder,
 * pasted into live tracking code -- 'No options are available.', which is a
 * select-box label submitted as a value, and one real site id truncated at six
 * different lengths.
 */
final class UnknownSiteRejectionTest extends TestCase
{
    /** @var string */
    private $site_id;

    /** @var string[] site row ids to remove */
    private $created = [];

    /**
     * @var string[] the Property ids those sites were given
     *
     * Tracked separately because creating a site MINTS A PROPERTY -- a site is
     * an Observation Profile now, and a Profile has to hang off something.
     * Removing only the site leaves the Property behind, and this suite creates
     * one per test: it had left 1,120 of them in the development database,
     * every one a row in the site picker's Properties column.
     */
    private $createdProperties = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        // A real site, created the way the admin UI and cmd=add-site create one,
        // so its id is derived by whatever scheme this installation uses.
        $domain = 'https://reject-test-' . bin2hex(random_bytes(4)) . '.example.com';
        $sm     = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site   = $sm->createNewSite($domain, 'Unknown site rejection test');

        $this->site_id   = $site->get('site_id');
        $this->track($site);
    }

    /** Remember a created site AND the Property it was given. */
    private function track($site): void
    {
        $this->created[] = $site->get('id');

        $property = $site->get('property_id');

        if ($property) {
            $this->createdProperties[] = $property;
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->created as $id) {
            $db->query(sprintf("DELETE FROM owa_site WHERE id = '%s'", $db->prepare($id)));
        }

        /*
         * The Properties too, and AFTER the sites -- a Property with a Profile
         * still pointing at it is a worse thing to leave behind than either
         * alone.
         */
        foreach ($this->createdProperties as $id) {
            $db->query(sprintf("DELETE FROM owa_property WHERE id = '%s'", $db->prepare($id)));
        }

        $this->created           = [];
        $this->createdProperties = [];

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    public function testARegisteredSiteIsAccepted(): void
    {
        $this->assertTrue(\OWA\Core\CoreAPI::isSiteRegistered($this->site_id),
            'a site created through SiteManager must be recognised, or tracking stops working');
    }

    /** @dataProvider rubbishSiteIds */
    public function testAnUnregisteredSiteIsRejected(string $label, $value): void
    {
        $this->assertFalse(\OWA\Core\CoreAPI::isSiteRegistered($value), $label . ' must not be accepted');
    }

    /** Every one of these was found in a real installation's fact tables. */
    public static function rubbishSiteIds(): array
    {
        return [
            'the docs placeholder'      => ['the docs placeholder', 'yoursiteidgoeshere'],
            'another placeholder'       => ['another placeholder', 'your_site_id'],
            'a UI label'                => ['a UI label', 'No options are available.'],
            'a truncated id'            => ['a truncated id', '40fe2ea31e18b4b6a4b648587876'],
            'a single character'        => ['a single character', 'x'],
            'empty'                     => ['empty', ''],
            'null'                      => ['null', null],
        ];
    }

    /** The event is refused outright, not recorded and ignored. */
    public function testAnEventForAnUnknownSiteIsNotLogged(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType('base.page_request');
        $event->setProperties([
            'site_id'  => 'yoursiteidgoeshere',
            'page_url' => 'https://example.com/',
            'guid'     => (string) random_int(1000000000, 9999999999),
        ]);

        $this->assertFalse(\OWA\Core\CoreAPI::logEvent('base.page_request', $event),
            'an event naming a site that does not exist must be refused');
    }

    /**
     * THE REGRESSION THIS FEATURE COULD CAUSE: a legitimate event must still log.
     *
     * Everything else here checks that bad events are refused. This checks the
     * expensive mistake -- refusing a good one, which would silently stop a
     * working installation from recording anything at all.
     */
    public function testAnEventForARegisteredSiteIsStillLogged(): void
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType('base.page_request');
        $event->setProperties([
            'site_id'          => $this->site_id,
            'page_url'         => 'https://example.com/accepted',
            'guid'             => (string) random_int(1000000000, 9999999999),
            'HTTP_USER_AGENT'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'ip_address'       => '203.0.113.10',
        ]);

        $this->assertNotFalse(\OWA\Core\CoreAPI::logEvent('base.page_request', $event),
            'an event for a registered site must get past the gate; refusing it would stop '
            . 'a working installation recording anything');
    }

    /**
     * The answer is memoised per process, because a queue worker handles many
     * events in one and the answer cannot change within it.
     *
     * Tested in the negative direction on purpose. Going the other way -- delete
     * the site, expect a miss -- does not work: Entity::getByColumn() caches
     * rows it finds, independently of the cache_objects setting, so a deleted
     * site still loads. Only positive results are cached, which is what makes
     * this feature safe: an unknown site is never cached, so a site created a
     * moment ago is recognised on the very next event rather than after an
     * expiry.
     */
    public function testTheAnswerIsMemoisedWithinAProcess(): void
    {
        $domain  = 'https://memo-test-' . bin2hex(random_bytes(4)) . '.example.com';
        // Pinned, not predicted. Identifiers are minted now, so a test cannot
        // know one before the site exists -- and this test has to ask about the
        // id BEFORE creating it, to prove the negative answer is memoised.
        $site_id = 'OWA-memo-' . bin2hex(random_bytes(6));

        $this->assertFalse(\OWA\Core\CoreAPI::isSiteRegistered($site_id),
            'the site does not exist yet');

        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite($domain, 'Memoisation test', '', '', $site_id);

        $this->track($site);

        $this->assertFalse(\OWA\Core\CoreAPI::isSiteRegistered($site_id),
            'the negative answer must be remembered rather than re-queried per event');

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $this->assertTrue(\OWA\Core\CoreAPI::isSiteRegistered($site_id),
            'and the truth is seen again once the memo is cleared');
    }

    /**
     * A site added through the admin UI or cmd=add-site must start being tracked
     * straight away.
     *
     * This is the failure mode that would make the whole feature unshippable:
     * reject a legitimate new site because a cache has not caught up, and
     * somebody's tracking is silently dead until it expires. Only found rows are
     * cached, so it cannot happen -- pinned here because it depends on that.
     */
    public function testANewlyCreatedSiteIsRecognisedImmediately(): void
    {
        $domain = 'https://fresh-site-' . bin2hex(random_bytes(4)) . '.example.com';

        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite($domain, 'Freshly created');

        $this->track($site);

        // No forget() here: this is a process that has never asked about it.
        $this->assertTrue(\OWA\Core\CoreAPI::isSiteRegistered($site->get('site_id')),
            'a site created moments ago must be trackable at once, not after a cache expires');
    }
}
