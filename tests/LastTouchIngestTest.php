<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * The visitor's last non-direct touch, written and read at ingest (PLAN 2.29).
 *
 * A session arriving with tags or a referrer records itself on the visitor
 * store as last_touch_*, under a guard in the UPDATE itself. A returning
 * visitor's session is stamped with that touch as prior_touch_* on its landing
 * rows. The cube decides what the stamp means; these test only that the
 * evidence is written and read as stated.
 */
final class LastTouchIngestTest extends IngestionTestCase
{
    /** @var string */
    private $site;

    /** @var int the request clock, in seconds: one beacon is one request */
    private $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
        $this->clock = time() - 3600;
    }

    /**
     * Fire one beacon for a visitor and return THAT beacon's raw rows by event type.
     *
     * @param array $arrival 'tags' => [name => value] on the landing URL, 'referrer' => URL
     */
    private function beacon(string $visitor, string $session, int $prior_sessions, bool $landing,
                            array $arrival = []): array
    {
        $url = isset($arrival['tags'])
            ? $this->landingUrlWithTags('/v2/attributed', $arrival['tags'])
            : 'https://owa-test-site/v2/attributed';

        $props = [
            'site_id'    => $this->site,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'page_url'      => $url,
            'page_location' => $url,
            'page_title'    => 'Attributed',
            'is_new_session_start'   => $landing,
            'is_new_visitor_created' => $landing && $prior_sessions === 0,
            'fsts' => time(),
            'sts'  => time(),
            'nps'  => $prior_sessions,
            'num_prior_sessions' => $prior_sessions,
        ];

        if (isset($arrival['referrer'])) {
            $props['HTTP_REFERER'] = $arrival['referrer'];
        }

        // One beacon is one request in production, each with its own receipt
        // time; in one test process they would otherwise all share one.
        $now = $this->clock++;
        \OWA\Core\CoreAPI::requestContainerSingleton()->setTimestamp($now);

        $this->fireEvent('base.page_request', $props);

        $rows = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            'SELECT * FROM owa_event_raw WHERE site_id = %s AND visitor_id = %d AND session_id = %d AND ts = %d',
            "'" . $this->site . "'", (int) $visitor, (int) $session, $now * 1000000)) as $row) {
            $row = (array) $row;
            $rows[$row['event_type']] = $row;
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');
        }

        $this->trackForCleanup('base.visitor_acquisition', $visitor, 'visitor_id');

        return $rows;
    }

    /** @return array|null the visitor's store row */
    private function store(string $visitor): ?array
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf(
            'SELECT * FROM owa_visitor_acquisition WHERE visitor_id = %d', (int) $visitor));

        return $row ? (array) $row : null;
    }

    public function testATaggedSessionRecordsItselfAsTheLastTouch(): void
    {
        $visitor = $this->uniqueGuid();
        $rows = $this->beacon($visitor, $this->uniqueSessionId(), 0, true,
            ['tags' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'spring']]);

        $store = $this->store($visitor);

        $this->assertSame('newsletter', $store['last_touch_source']);
        $this->assertSame('email', $store['last_touch_medium']);
        $this->assertSame('spring', $store['last_touch_campaign']);
        $this->assertSame($rows['page_view']['ts'], $store['last_touch_ts'], 'the touch is when the session landed');
    }

    public function testAReferrerAloneIsATouch(): void
    {
        $visitor = $this->uniqueGuid();
        $this->beacon($visitor, $this->uniqueSessionId(), 0, true, ['referrer' => 'https://www.google.com/search?q=owa']);

        $store = $this->store($visitor);

        $this->assertSame('www.google.com', $store['last_touch_referer_host'], 'the host as received');
        $this->assertNull($store['last_touch_source'], 'no tag was collected');
        $this->assertNotNull($store['last_touch_ts']);
    }

    public function testADirectSessionRecordsNoTouch(): void
    {
        $visitor = $this->uniqueGuid();
        $this->beacon($visitor, $this->uniqueSessionId(), 0, true);

        $store = $this->store($visitor);

        $this->assertNotNull($store, 'the first session still writes its acquisition');
        $this->assertNull($store['last_touch_ts']);
    }

    /** Only the landing beacon carries the session's arrival, so only it writes. */
    public function testALaterBeaconOfTheSessionWritesNoTouch(): void
    {
        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();
        $this->beacon($visitor, $session, 0, true, ['tags' => ['source' => 'newsletter', 'medium' => 'email']]);
        $first = $this->store($visitor)['last_touch_ts'];

        $this->beacon($visitor, $session, 0, false, ['referrer' => 'https://www.example.net/a']);

        $this->assertSame($first, $this->store($visitor)['last_touch_ts']);
    }

    /**
     * A session starting from one of the site's own pages -- one that expired
     * between two page views, or restarted on an idle tab -- arrived from
     * nowhere new: it records no touch, and inherits the one before it.
     */
    public function testASelfReferredSessionIsDirect(): void
    {
        $visitor = $this->uniqueGuid();
        $first = $this->beacon($visitor, $this->uniqueSessionId(), 0, true,
            ['tags' => ['source' => 'newsletter', 'medium' => 'email']]);

        $rows = $this->beacon($visitor, $this->uniqueSessionId(), 1, true,
            ['referrer' => 'https://owa-test-site/v2/previous']);

        $this->assertNull($rows['session_start']['referer_host'], 'the site is not its own referrer');
        $this->assertSame('https://owa-test-site/v2/previous', $rows['session_start']['referer_url']);
        $this->assertSame('newsletter', $rows['session_start']['prior_touch_source']);

        $store = $this->store($visitor);
        $this->assertSame('newsletter', $store['last_touch_source'], 'the real touch is not displaced');
        $this->assertSame($first['page_view']['ts'], $store['last_touch_ts']);
    }

    /** A returning visitor's direct session is stamped with the touch before it. */
    public function testADirectReturnIsStampedWithThePriorTouch(): void
    {
        $visitor = $this->uniqueGuid();
        $first = $this->beacon($visitor, $this->uniqueSessionId(), 0, true,
            ['tags' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'spring']]);

        $session = $this->uniqueSessionId();
        $rows = $this->beacon($visitor, $session, 1, true);

        foreach (['page_view', 'session_start'] as $type) {
            $this->assertSame('newsletter', $rows[$type]['prior_touch_source'], $type);
            $this->assertSame('email', $rows[$type]['prior_touch_medium'], $type);
            $this->assertSame('spring', $rows[$type]['prior_touch_campaign'], $type);
            $this->assertSame($first['page_view']['ts'], $rows[$type]['prior_touch_ts'], $type);
        }

        $later = $this->beacon($visitor, $session, 1, false);
        $this->assertNull($later['page_view']['prior_touch_ts'] ?? null,
            'a later beacon of the session is not stamped');
    }

    /** A first visit has nothing before it to inherit. */
    public function testAFirstVisitIsNotStamped(): void
    {
        $rows = $this->beacon($this->uniqueGuid(), $this->uniqueSessionId(), 0, true);

        $this->assertNull($rows['page_view']['prior_touch_ts']);
    }

    /**
     * THE GUARD. A touch already stored as newer than this session is neither
     * displaced by it nor stamped onto it: a queue drain can deliver an older
     * beacon after a later session's touch was written.
     */
    public function testANewerStoredTouchIsNeitherDisplacedNorInherited(): void
    {
        $visitor = $this->uniqueGuid();
        $this->beacon($visitor, $this->uniqueSessionId(), 0, true, ['tags' => ['source' => 'newsletter', 'medium' => 'email']]);

        $future = (int) ((microtime(true) + 86400) * 1000000);
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_visitor_acquisition SET last_touch_source = 'later', last_touch_ts = ? WHERE visitor_id = ?",
            [$future, $visitor]);

        $this->beacon($visitor, $this->uniqueSessionId(), 1, true, ['tags' => ['source' => 'partner', 'medium' => 'referral']]);

        $store = $this->store($visitor);
        $this->assertSame('later', $store['last_touch_source']);
        $this->assertSame((string) $future, (string) $store['last_touch_ts']);

        $rows = $this->beacon($visitor, $this->uniqueSessionId(), 2, true);
        $this->assertNull($rows['page_view']['prior_touch_ts'], 'a touch after the session is not its to inherit');
    }

    /** A returning visitor with no store row gets one from their first non-direct touch. */
    public function testATouchCreatesTheRowWhenThereIsNone(): void
    {
        $visitor = $this->uniqueGuid();
        $this->assertNull($this->store($visitor));

        $this->beacon($visitor, $this->uniqueSessionId(), 3, true, ['tags' => ['source' => 'partner', 'medium' => 'cpc']]);

        $store = $this->store($visitor);
        $this->assertSame('partner', $store['last_touch_source']);
        $this->assertNull($store['acq_ts'], 'an acquisition is not invented for it');
    }
}
