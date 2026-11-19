<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * A beacon from the 2.0 tracker is decoded once, by PHP, and its URLs are
 * stored with the path decoded and the query string as the page had it. A
 * beacon with no format version (a cached 1.x tag, the PHP SDK) is decoded a
 * second time, as 1.x did (conf/beacon_compat.php, decoded_once_from_version).
 *
 * Each beacon goes through log.php's own steps -- the request container,
 * Beacon\Compat::decodeOlderBeacon(), admission -- and is stored through real
 * ingest, in the request and through the queue.
 */
final class BeaconDecodeOnceTest extends IngestionTestCase
{
    /** @var string */
    private $site;

    /** Columns that differ between any two beacons: who and when. */
    const IDENTITY = ['id', 'visitor_id', 'session_id', 'ts', 'yyyymmdd', 'visitor_fsts',
        'session_start_ts', 'prior_session_start_ts', 'created_at', 'updated_at'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
    }

    private function ns(): string
    {
        return (string) \OWA\Core\CoreAPI::getSetting('base', 'ns');
    }

    /**
     * A page's location.href (path and query), and what a 2.0 beacon stores
     * for it: page_location's path and query, page_path, page_query.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function urls(): array
    {
        return [
            'encoded UTF-8 and a space' => ['/caf%C3%A9/a%20b?q=a%20b&t=50%25', '/café/a b?q=a%20b&t=50%25', '/café/a b', 'q=a%20b&t=50%25'],
            'UTF-8 in the query'        => ['/caf%C3%A9/x?q=caf%C3%A9', '/café/x?q=caf%C3%A9', '/café/x', 'q=caf%C3%A9'],
            'a literal % and &'         => ['/50%25off/a%26b?x=1%262&y=%2541', '/50%off/a&b?x=1%262&y=%2541', '/50%off/a&b', 'x=1%262&y=%2541'],
            'a plus sign'               => ['/a+b?q=a+b', '/a+b?q=a+b', '/a+b', 'q=a+b'],
            'an encoded ? in the path'  => ['/what%3Fnot', '/what%3Fnot', '/what%3Fnot', null],
            'invalid UTF-8'             => ['/caf%E9/x?q=%E9', '/caf%E9/x?q=%E9', '/caf%E9/x', 'q=%E9'],
            'valid and invalid UTF-8'   => ['/caf%C3%A9/x%E9', '/café/x%E9', '/café/x%E9', null],
        ];
    }

    /** @return array<string, array> urls(), in the request and through the queue */
    public static function urlsByPath(): array
    {
        return self::byPath(self::urls());
    }

    /**
     * urls() with valid UTF-8 only, for a 1.x beacon. Decoded twice, an invalid
     * byte is no longer an escape, and what becomes of it is the 1.x path's
     * existing handling (read as Windows-1252 in the request, replaced in a
     * queued envelope), which this change leaves alone.
     *
     * @return array<string, array>
     */
    public static function validUtf8UrlsByPath(): array
    {
        return self::byPath(array_filter(self::urls(),
            static fn (array $case) => mb_check_encoding(rawurldecode($case[0]), 'UTF-8')));
    }

    /** @return array<string, array> each case, in the request and through the queue */
    private static function byPath(array $cases): array
    {
        $out = [];

        foreach ($cases as $name => $case) {
            $out[$name . ', direct'] = array_merge($case, [false]);
            $out[$name . ', queued'] = array_merge($case, [true]);
        }

        return $out;
    }

    /**
     * A page view's params as PHP decodes the beacon: each value as the page
     * had it, the tracker having encoded it once.
     *
     * @param array<string, string> $more
     * @return array<string, string>
     */
    private function wire(string $href, string $visitor, string $session, bool $current, array $more = []): array
    {
        $wire = $more + [
            'site'  => $this->site,
            'v_id'  => $visitor,
            's_id'  => $session,
            'e_t'   => 'page_view',
            'p_l'   => 'https://owa-test-site' . $href,
            'p_r'   => 'https://www.example.net' . $href,
            'p_t'   => 'A page',
            's_new' => '1',
            'v_new' => '1',
            'v_fts' => (string) time(),
            's_sts' => (string) time(),
            'v_nps' => '0',
        ];

        if ($current) {
            $wire['_v'] = '2';
        }

        return $wire;
    }

    /**
     * What log.php hands logEvent() for those params: the request container,
     * Compat::decodeOlderBeacon(), admission, and the copies an older beacon
     * keeps.
     *
     * @param array<string, string> $wire
     * @return array<string, mixed>
     */
    private static function beacon(array $wire): array
    {
        $saved = [$_GET, $_POST, $_SERVER];

        $_GET  = $wire;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'owa.example.test';
        $_SERVER['REQUEST_URI']    = '/owa/log.php';

        try {
            $request = new \OWA\Core\RequestContainer();
            $arrived = \OWA\Module\Base\Classes\Beacon\Compat::decodeOlderBeacon($request);

            return \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams(
                (array) $request->getAllOwaParams()) + $arrived;
        } finally {
            [$_GET, $_POST, $_SERVER] = $saved;
        }
    }

    /** @return array<string, mixed> the page_view row a 2.0 or a 1.x beacon for $href stores */
    private function pageView(string $href, bool $current, bool $queued, array $more = []): array
    {
        [$visitor, $session] = [$this->uniqueGuid(), $this->uniqueSessionId()];

        return $this->store(self::beacon($this->wire($href, $visitor, $session, $current, $more)), $visitor, $session, $queued);
    }

    /**
     * The table: a 2.0 beacon's page URL and referrer have the path decoded
     * once and the query as the page had it, and the readings follow.
     *
     * @dataProvider urlsByPath
     */
    public function testACurrentBeaconStoresThePathDecodedAndTheQueryAsSent(string $href, string $stored, string $path, ?string $query, bool $queued): void
    {
        $row = $this->pageView($href, true, $queued);

        $this->assertSame('https://owa-test-site' . $stored, $row['page_location']);
        $this->assertSame($path, $row['page_path']);
        $this->assertSame($query, $row['page_query']);
        $this->assertSame('https://www.example.net' . $stored, $row['referer_url']);
        $this->assertSame($query, $row['referer_query']);
        $this->assertSame('2', (string) $row['beacon_version']);
    }

    /**
     * A 1.x beacon, with no format version, is decoded a second time, as
     * today: its page URL is what that decode makes of it.
     *
     * @dataProvider validUtf8UrlsByPath
     */
    public function testAnOlderBeaconIsDecodedASecondTime(string $href, string $stored, string $path, ?string $query, bool $queued): void
    {
        $row = $this->pageView($href, false, $queued);

        $this->assertSame('https://owa-test-site' . rawurldecode($href), $row['page_location']);
        $this->assertNull($row['beacon_version']);
    }

    /** A 2.0 beacon's title and event properties are what the page had; a 1.x beacon's are decoded again. */
    public function testTitlesAndPropertiesAreExact(): void
    {
        $more = ['p_t' => 'Save 50%25', 'eps_note' => 'code %2541'];

        $current = $this->pageView('/sale', true, false, $more);
        $older   = $this->pageView('/sale', false, false, $more);

        $this->assertSame('Save 50%25', $current['page_title']);
        $this->assertSame('code %2541', json_decode((string) $current['params'], true)['note'] ?? null);

        $this->assertSame('Save 50%', $older['page_title']);
        $this->assertSame('code %41', json_decode((string) $older['params'], true)['note'] ?? null);
    }

    /**
     * A campaign tag is read out of the query as a parameter value: a%20b and
     * a+b are "a b", and an encoded & is part of the value. page_location
     * keeps the query as it was sent; page_query drops the tag.
     */
    public function testACampaignTagInAnEncodedQueryIsReadAsAValue(): void
    {
        $c = $this->ns() . 'campaign';

        $row = $this->pageView('/land?' . $c . '=spring%20sale%26more&x=a+b', true, false);

        $this->assertSame('spring sale&more', $row['tagged_campaign']);
        $this->assertSame('https://owa-test-site/land?' . $c . '=spring%20sale%26more&x=a+b', $row['page_location']);
        $this->assertSame('x=a+b', $row['page_query']);

        $plus = $this->pageView('/land?' . $c . '=a+b', true, false);

        $this->assertSame('a b', $plus['tagged_campaign']);
    }

    /**
     * The page_view row a beacon's params store: ingested in the request, or
     * queued (the envelope serialised as the queues do) and drained.
     */
    private function store(array $params, string $visitor, string $session, bool $queued): array
    {
        if (!$queued) {
            $this->fireEvent('page_view', $params);

            return $this->row($visitor, $session);
        }

        $intake = new BeaconDecodeCapturingIntake();
        $was    = \OWA\Core\CoreAPI::getSetting('base', 'queue_tracking_events');

        \OWA\Module\Base\Classes\TrackerIngest::$queue = $intake;
        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);

        try {
            $this->fireEvent('page_view', $params);
        } finally {
            \OWA\Module\Base\Classes\TrackerIngest::$queue = null;
            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', $was);
        }

        $this->assertCount(1, $intake->sent, 'queued, not ingested in the request');
        $this->assertSame([], $this->rowsOf($visitor, $session), 'nothing stored before the drain');

        $envelope = json_decode((string) json_encode($intake->sent[0],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), true);

        $this->assertTrue(\OWA\Module\Base\Classes\TrackerIngest::ingest(
            \OWA\Module\Base\Classes\TrackerIngest::event($envelope)), 'drained');

        return $this->row($visitor, $session);
    }

    /** @return array[] the visitor's session's raw rows */
    private function rowsOf(string $visitor, string $session): array
    {
        return array_map(static fn($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            "SELECT * FROM owa_event_raw WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $this->site, (int) $visitor, (int) $session)));
    }

    private function row(string $visitor, string $session): array
    {
        $out = [];

        foreach ($this->rowsOf($visitor, $session) as $row) {
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');

            if ($row['event_type'] === 'page_view') {
                $out = array_diff_key($row, array_flip(self::IDENTITY));
            }
        }

        $this->trackForCleanup('base.visitor_acquisition', $visitor, 'visitor_id');
        $this->assertNotSame([], $out, 'the page view was stored');

        return $out;
    }

    /** The copies are the URLs as PHP decoded them; a beacon cannot name them. */
    public function testTheCopiesAreTheUrlsAsTheyArrived(): void
    {
        $H = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

        $this->assertSame([
            'arrived_page_location' => 'https://x.test/a%20b?c=1%262',
            'arrived_referer'       => 'https://r.test/?q=a%26b',
        ], $H::arrivedUrls(['p_l' => 'https://x.test/a%20b?c=1%262', 'p_r' => 'https://r.test/?q=a%26b', 'p_t' => 'T']));

        $this->assertSame(['arrived_page_location' => 'https://x.test/old'], $H::arrivedUrls(['page_url' => 'https://x.test/old']),
            'an older tracker\'s name for the page URL');

        $this->assertArrayNotHasKey('arrived_page_location', $H::admitRequestParams(['arrived_page_location' => 'https://evil.test/']));
    }

    /** Only a URL the second decode cuts is read from its copy. */
    public function testOnlyACutUrlIsReadFromItsCopy(): void
    {
        $H = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');

        foreach ([
            'https://x.test/a%20b?q=caf%C3%A9&r=50%25' => false,
            'https://x.test/a%2526b?q=a%2526b'         => false,
            'https://x.test/a%26b?q=a%3Fb'             => false,
            'https://x.test/a%3Fb'                     => true,
            'https://x.test/a%23b'                     => true,
            'https://x.test/a?q=a%26b'                 => true,
            'https://x.test/a?q=a%23b'                 => true,
        ] as $url => $cut) {
            $event->set('arrived_page_location', $url);
            $this->assertSame($cut ? $url : null, $H::arrivedUrlIfCut($event, 'page_location'), $url);
        }

        $event->delete('arrived_page_location');
        $this->assertNull($H::arrivedUrlIfCut($event, 'page_location'), 'an event with no copy');
    }

    public function testAQueryIsDecodedPairByPairAndAValuesOwnSeparatorsStayEncoded(): void
    {
        $decode = [\OWA\Module\Base\Classes\V2Event::class, 'decodeQuery'];

        $this->assertSame('q=a b&r=café', $decode('q=a%20b&r=caf%C3%A9'));
        $this->assertSame('q=a%26b&h=a%23b', $decode('q=a%26b&h=a%23b'));
        $this->assertSame('q=50%&p=a+b&flag&&e=a=b', $decode('q=50%25&p=a+b&flag&&e=a%3Db'));
    }
}

/** An intake that keeps what it is sent. */
class BeaconDecodeCapturingIntake implements \OWA\Core\IntakeQueue
{
    public array $sent = [];

    public function provision() { return true; }

    public function send(array $envelope, $delay = 0, $replayed = false) { $this->sent[] = $envelope; return true; }

    public function receive($max, $visibility) { return []; }

    public function ack(\OWA\Core\IntakeMessage $message) { return true; }

    public function release(\OWA\Core\IntakeMessage $message, $delay) { return true; }

    public function deadLetter(\OWA\Core\IntakeMessage $message, $reason) { return true; }

    public function deadLetterQueue() { return null; }

    public function isProbablyEmpty() { return true; }

    public function stats() { return []; }
}
