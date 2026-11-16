<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * A beacon's values are decoded twice -- PHP's decode, then
 * RequestContainer::decodeRequestParams() -- and stored in that form, as
 * before. The second decode cuts a URL that holds an encoded ? or # in its
 * path, or & or # in its query, so those are read from the copy taken before
 * it (TrackingEventHelpers::arrivedUrls(), arrivedUrlIfCut()): the campaign
 * tags, page_path, page_query and referer_query.
 *
 * Each case is stored twice through real ingest: as the old log.php left the
 * params (no copies, which is also how an event built in process or queued
 * before this looks), and as log.php leaves them now. Every stored column is
 * identical except, where the old row was cut, the ones the cut broke.
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
     * Path and query of the page as the browser has it (the tracker sends
     * encodeURIComponent() of it and PHP decodes that), its title, and the
     * columns that are complete now where they were cut.
     *
     * @return array<string,array{0:string,1:string,2:array}>
     */
    public static function cases(): array
    {
        return [
            'plain'             => ['/plain?{c}=spring&x=1', 'Plain', []],
            'an encoded space'  => ['/a%20b?{c}=spring%20sale&q=a%20b', 'A %20 title', []],
            'encoded UTF-8'     => ['/caf%C3%A9?{c}=caf%C3%A9&q=caf%C3%A9', 'Caf%C3%A9', []],
            'a decoded é'       => ['/café?{c}=café&q=café', 'Café', []],
            'an encoded comma'  => ['/c%2Cd?{c}=a%2Cb&q=a%2Cb', 'C%2CD', []],
            'a literal %'       => ['/50%25off?{c}=50%25&q=50%25', '50%25 off', []],
            'a plus sign'       => ['/a+b?{c}=a+b&q=a+b', 'A+B', []],
            'invalid UTF-8'     => ['/caf%E9?{c}=caf%E9&q=%E9', 'Caf%E9', []],
            'valid and invalid UTF-8' => ['/caf%C3%A9?{c}=caf%E9&q=caf%C3%A9', 'Caf%C3%A9 %E9', []],
            'an encoded & in a query value' => ['/amp?{c}=spring%26summer&q=a%26b', 'Amp', [
                'tagged_campaign' => 'spring&summer',
                'page_query'      => 'q=a%26b',
                'referer_query'   => '{c}=spring%26summer&q=a%26b',
            ]],
            'an encoded # in a query value' => ['/hash?{c}=a%23b&q=1', 'Hash', [
                'tagged_campaign' => 'a#b',
                'page_query'      => 'q=1',
                'referer_query'   => '{c}=a%23b&q=1',
            ]],
            'an encoded ? in the path' => ['/what%3Fnot', 'What', [
                'page_path'     => '/what?not',
                'page_query'    => null,
                'referer_query' => null,
            ]],
            'an encoded # in the path' => ['/a%23b?q=1', 'Hash path', [
                'page_path'     => '/a#b',
                'page_query'    => 'q=1',
                'referer_query' => 'q=1',
            ]],
        ];
    }

    /** The params as PHP's decoding of a beacon leaves them. */
    private function wire(string $pathQuery, string $title, string $visitor, string $session): array
    {
        $pathQuery = str_replace('{c}', $this->ns() . 'campaign', $pathQuery);

        return [
            'site'  => $this->site,
            'v_id'  => $visitor,
            's_id'  => $session,
            'e_t'   => 'page_view',
            'p_l'   => 'https://owa-test-site' . $pathQuery,
            'p_r'   => 'https://www.example.net' . $pathQuery,
            'p_t'   => $title,
            's_new' => '1',
            'v_new' => '1',
            'v_fts' => (string) time(),
            's_sts' => (string) time(),
            'v_nps' => '0',
        ];
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

    /** As log.php hands a beacon's params to its event: the copies, then the second decode, then admission. */
    private static function beacon(array $wire): array
    {
        $H = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

        return self::decodedTwice($wire) + $H::arrivedUrls($wire);
    }

    /** The old log.php: every value decoded a second time (decodeRequestParams()), then admitted. */
    private static function decodedTwice(array $wire): array
    {
        return \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams(array_map(
            static fn($v) => is_string($v) ? rawurldecode($v) : $v, $wire));
    }

    /** Every case, ingested in the request and through the queue. */
    public static function casesByPath(): array
    {
        $out = [];

        foreach (self::cases() as $name => $case) {
            $out[$name . ', direct'] = array_merge($case, [false]);
            $out[$name . ', queued'] = array_merge($case, [true]);
        }

        return $out;
    }

    /** @dataProvider casesByPath */
    public function testEveryStoredFieldKeepsItsFormAndOnlyACutOneChanges(string $pathQuery, string $title, array $complete, bool $queued): void
    {
        [$v1, $s1] = [$this->uniqueGuid(), $this->uniqueSessionId()];
        [$v2, $s2] = [$this->uniqueGuid(), $this->uniqueSessionId()];

        $before = $this->store(self::decodedTwice($this->wire($pathQuery, $title, $v1, $s1)), $v1, $s1, $queued);
        $after  = $this->store(self::beacon($this->wire($pathQuery, $title, $v2, $s2)), $v2, $s2, $queued);

        $complete = array_map(fn($v) => is_string($v) ? str_replace('{c}', $this->ns() . 'campaign', $v) : $v, $complete);

        $this->assertSame(array_keys($before), array_keys($after));

        foreach (array_keys($before) as $column) {

            if (array_key_exists($column, $complete)) {
                $this->assertSame($complete[$column], $after[$column], "$column, now whole");
                $this->assertNotSame($before[$column], $after[$column], "$column was cut before");
            } else {
                $this->assertSame($before[$column], $after[$column], "$column keeps its form");
            }
        }
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
