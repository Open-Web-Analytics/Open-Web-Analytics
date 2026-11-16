<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * A beacon's values are decoded once by PHP, then once more on purpose
 * (TrackingEventHelpers::decodeBeaconValues()): every stored field keeps the form it
 * had when the request decoded everything twice. What is parsed out of the
 * page URL and referrer -- the campaign tags, page_path, page_query and
 * referer_query -- is parsed from them as they arrived, so a second decode no
 * longer cuts them at an encoded & or #.
 *
 * Each case is stored twice through real ingest: as the old request handling
 * left the params (every value decoded again, no arrived copies, which is also
 * how an event built in process or queued before this looks), and as log.php
 * leaves them now. The rows are identical except where the old one was cut.
 */
final class BeaconDecodeOnceTest extends IngestionTestCase
{
    /** @var string */
    private $site;

    /** The columns compared. */
    const COLUMNS = ['page_location', 'page_path', 'page_query', 'page_title', 'host',
        'referer_url', 'referer_host', 'referer_query', 'tagged_campaign'];

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

    /** The page_view row a beacon's params store, by the direct path. */
    private function store(array $params, string $visitor, string $session): array
    {
        $this->fireEvent('page_view', $params);

        return $this->row($visitor, $session);
    }

    private function row(string $visitor, string $session): array
    {
        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            "SELECT * FROM owa_event_raw WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $this->site, (int) $visitor, (int) $session));

        $out = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');

            if ($row['event_type'] === 'page_view') {
                $out = array_intersect_key($row, array_flip(self::COLUMNS));
            }
        }

        $this->trackForCleanup('base.visitor_acquisition', $visitor, 'visitor_id');
        $this->assertNotSame([], $out, 'the page view was stored');

        return $out;
    }

    /** As log.php hands a beacon's params to its event. */
    private static function beacon(array $wire): array
    {
        $H = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

        return $H::admitBeaconParams($H::decodeBeaconValues($wire), $wire);
    }

    /** The old request handling: every value decoded a second time, then admitted. */
    private static function decodedTwice(array $wire): array
    {
        return \OWA\Module\Base\Classes\TrackingEventHelpers::admitRequestParams(array_map(
            static fn($v) => is_string($v) ? rawurldecode($v) : $v, $wire));
    }

    /** @dataProvider cases */
    public function testEveryStoredFieldKeepsItsFormAndOnlyACutOneChanges(string $pathQuery, string $title, array $complete): void
    {
        [$v1, $s1] = [$this->uniqueGuid(), $this->uniqueSessionId()];
        [$v2, $s2] = [$this->uniqueGuid(), $this->uniqueSessionId()];

        $before = $this->store(self::decodedTwice($this->wire($pathQuery, $title, $v1, $s1)), $v1, $s1);
        $after  = $this->store(self::beacon($this->wire($pathQuery, $title, $v2, $s2)), $v2, $s2);

        $complete = array_map(fn($v) => is_string($v) ? str_replace('{c}', $this->ns() . 'campaign', $v) : $v, $complete);

        foreach (self::COLUMNS as $column) {

            if (array_key_exists($column, $complete)) {
                $this->assertSame($complete[$column], $after[$column], "$column, now whole");
                $this->assertNotSame($before[$column], $after[$column], "$column was cut before");
            } else {
                $this->assertSame($before[$column], $after[$column], "$column keeps its form");
            }
        }
    }

    /** A queued beacon is stored as a direct one: the drain decodes nothing. */
    public function testAQueuedBeaconIsStoredAsADirectOne(): void
    {
        $intake = new BeaconDecodeCapturingIntake();
        $queued = \OWA\Core\CoreAPI::getSetting('base', 'queue_tracking_events');

        \OWA\Module\Base\Classes\TrackerIngest::$queue = $intake;
        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);

        try {
            foreach (['an encoded & in a query value', 'encoded UTF-8', 'a literal %'] as $case) {

                [$pathQuery, $title] = self::cases()[$case];

                [$v1, $s1] = [$this->uniqueGuid(), $this->uniqueSessionId()];
                [$v2, $s2] = [$this->uniqueGuid(), $this->uniqueSessionId()];

                \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', false);
                $direct = $this->store(self::beacon($this->wire($pathQuery, $title, $v1, $s1)), $v1, $s1);
                \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);

                $intake->sent = [];
                $this->fireEvent('page_view', self::beacon($this->wire($pathQuery, $title, $v2, $s2)));
                $this->assertCount(1, $intake->sent, "$case: queued, not ingested in the request");

                $envelope = json_decode(json_encode($intake->sent[0]), true);
                $this->assertTrue(\OWA\Module\Base\Classes\TrackerIngest::ingest(
                    \OWA\Module\Base\Classes\TrackerIngest::event($envelope)), "$case: drained");

                $this->assertSame($direct, $this->row($v2, $s2), $case);
            }
        } finally {
            \OWA\Module\Base\Classes\TrackerIngest::$queue = null;
            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', $queued);
        }
    }

    /** Each value decoded once more; the page URL and referrer also kept as they came; a nested value untouched. */
    public function testTheParamsAreDecodedOnceMoreAndTheUrlsKeptAsTheyCame(): void
    {
        $params = self::beacon([
            'p_l'   => 'https://x.test/a%20b?c=1%262',
            'p_r'   => 'https://r.test/?q=a%26b',
            'p_t'   => '100%25',
            'eps_x' => ['a%20b'],
        ]);

        $this->assertSame('https://x.test/a b?c=1&2', $params['p_l']);
        $this->assertSame('https://x.test/a%20b?c=1%262', $params['arrived_page_location']);
        $this->assertSame('https://r.test/?q=a%26b', $params['arrived_referer']);
        $this->assertSame('100%', $params['p_t']);
        $this->assertSame(['a%20b'], $params['eps_x']);

        $this->assertArrayNotHasKey('arrived_page_location',
            self::beacon(['arrived_page_location' => 'https://evil.test/']),
            'a beacon cannot name it');
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
