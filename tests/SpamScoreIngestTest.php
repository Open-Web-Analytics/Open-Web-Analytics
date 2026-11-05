<?php

require_once __DIR__ . '/IngestionTestCase.php';

use OWA\Module\Base\Classes\Spam\Blocks;
use OWA\Module\Base\Classes\TrackerIngest;

/**
 * Spam scoring through the real ingest path: logEvent -> base.processRequest
 * -> Handler\EventRawHandlers, in the request and through the queue.
 *
 * strict, with the threshold at 7.5. A beacon whose agent claims Chrome 133:
 *
 *   with Sec-Fetch-Mode, without Sec-CH-UA   NO_CLIENT_HINTS=2.5               stored
 *   with neither                             NO_FETCH_METADATA=5.0 + 2.5 = 7.5 blocked
 *
 * Its own Profile, whose domain is the page's host, so page_origin is quiet.
 * Blocked counts go to a scratch copy of owa_spam_block.
 */
final class SpamScoreIngestTest extends IngestionTestCase
{
    private const DOMAIN = 'spam-ingest.example';
    private const TABLE  = 'spam_block_phpunit';

    /** @var string */
    private $site;

    /** @var array settings to restore */
    private $saved = array();

    /** @var string */
    private $queueDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-spam-ingest-site');

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($site->generateId($this->site));

        if (!$site->wasPersisted()) {
            \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager')
                ->createNewSite(self::DOMAIN, 'Spam ingest test site', '', '', $this->site);
            \OWA\Core\CoreAPI::forgetRegisteredSites();
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS owa_' . self::TABLE);

        $scratch = \OWA\Core\CoreAPI::entityFactory('base.spam_block');
        $scratch->setTableName(self::TABLE);
        $this->assertTrue($scratch->createTable(), 'the scratch owa_spam_block');

        Blocks::$table = 'owa_' . self::TABLE;

        foreach (array('spam_detection', 'spam_threshold', 'queue_tracker_ingest') as $key) {
            $this->saved[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        }

        \OWA\Core\CoreAPI::setSetting('base', 'spam_detection', 'strict');
        \OWA\Core\CoreAPI::setSetting('base', 'spam_threshold', '7.5');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $key, $value);
        }

        Blocks::$table        = null;
        TrackerIngest::$queue = null;

        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS owa_' . self::TABLE);
        }

        if ($this->queueDir !== '') {
            exec('rm -rf ' . escapeshellarg($this->queueDir));
        }

        parent::tearDown();
    }

    /** A first page view of a new visitor, with these request headers as the edge captures them. */
    private function beacon(bool $fetchMetadata, string $visitor = ''): array
    {
        $props = array(
            'site_id'    => $this->site,
            'visitor_id' => $visitor !== '' ? $visitor : $this->uniqueGuid(),
            'session_id' => $this->uniqueSessionId(),
            'page_url'      => 'https://www.' . self::DOMAIN . '/spam',
            'page_location' => 'https://www.' . self::DOMAIN . '/spam',
            'page_title'    => 'Spam',
            'is_new_session_start'   => true,
            'is_new_visitor_created' => true,
            'num_prior_sessions'     => 0,

            'HTTP_USER_AGENT'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36',
            'request_accept_encoding' => 'gzip, deflate, br, zstd',
            'request_accept_language' => 'en-US,en;q=0.9',
            'request_referer'         => 'https://www.' . self::DOMAIN . '/spam',
        );

        if ($fetchMetadata) {
            $props['request_sec_fetch_mode'] = 'no-cors';
            $props['request_sec_fetch_site'] = 'same-site';
            $props['request_sec_fetch_dest'] = 'empty';
        }

        return $props;
    }

    /** @return array[] the raw rows for one visitor, keyed by event_type */
    private function rowsFor(string $visitor): array
    {
        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            'SELECT * FROM owa_event_raw WHERE site_id = ? AND visitor_id = ?', array($this->site, $visitor));

        $byType = array();

        foreach ($rows as $row) {
            $row = (array) $row;
            $byType[$row['event_type']] = $row;
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');
        }

        $this->trackForCleanup('base.visitor_acquisition', $visitor, 'visitor_id');

        return $byType;
    }

    /** @return array<string,int> rule => blocked, for this test's Profile */
    private function blocked(): array
    {
        $out = array();

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            'SELECT rule, SUM(blocked) AS n FROM owa_' . self::TABLE . ' WHERE site_id = ? GROUP BY rule',
            array($this->site)) as $row) {
            $row = (array) $row;
            $out[$row['rule']] = (int) $row['n'];
        }

        ksort($out);

        return $out;
    }

    public function testABeaconUnderTheThresholdStoresItsScoreOnEveryRow(): void
    {
        $props = $this->beacon(true);
        $this->fireEvent('base.page_request', $props);

        $rows = $this->rowsFor($props['visitor_id']);

        foreach (array('page_view', 'session_start', 'first_visit') as $type) {
            $this->assertArrayHasKey($type, $rows, "$type was not stored");
            $this->assertEquals(2.5, (float) $rows[$type]['spam_score'], "$type's score");
            $this->assertSame('NO_CLIENT_HINTS=2.5', $rows[$type]['spam_rules'], "$type's rules");
        }

        $this->assertSame(array(), $this->blocked(), 'nothing was blocked');
    }

    public function testABeaconAtTheThresholdWritesNothingAndIsCounted(): void
    {
        $props = $this->beacon(false);
        $this->fireEvent('base.page_request', $props);

        $this->assertSame(array(), $this->rowsFor($props['visitor_id']), 'a blocked beacon writes no row at all');

        $acquisition = \OWA\Core\CoreAPI::entityFactory('base.visitor_acquisition');
        $acquisition->load($props['visitor_id'], 'visitor_id');
        $this->assertFalse((bool) $acquisition->wasPersisted(), 'nor the visitor store');

        $this->assertSame(array('*' => 1, 'NO_CLIENT_HINTS' => 1, 'NO_FETCH_METADATA' => 1), $this->blocked());

        $day = (int) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT MAX(yyyymmdd) AS d FROM owa_' . self::TABLE . ' WHERE site_id = ?', array($this->site))['d'];
        $this->assertSame((int) $this->lastEvent()->get('yyyymmdd'), $day, 'the day raw would have filed it under');
    }

    /** In the request, each blocked beacon writes its keys. */
    public function testDirectIngestCountsPerBeacon(): void
    {
        $before = Blocks::$writes;

        $this->fireEvent('base.page_request', $this->beacon(false));
        $this->fireEvent('base.page_request', $this->beacon(false));

        $this->assertSame(6, Blocks::$writes - $before, 'three keys, twice');
        $this->assertSame(array('*' => 2, 'NO_CLIENT_HINTS' => 2, 'NO_FETCH_METADATA' => 2), $this->blocked());
    }

    /** A blank threshold stores the beacon, scored. */
    public function testABlankThresholdStoresEverything(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'spam_threshold', '');

        $props = $this->beacon(false);
        $this->fireEvent('base.page_request', $props);

        $rows = $this->rowsFor($props['visitor_id']);

        $this->assertArrayHasKey('page_view', $rows);
        $this->assertEquals(7.5, (float) $rows['page_view']['spam_score']);
        $this->assertSame('NO_FETCH_METADATA=5.0 NO_CLIENT_HINTS=2.5', $rows['page_view']['spam_rules']);
        $this->assertSame(array(), $this->blocked());
    }

    /** Normal runs the user agent rules alone, so the same beacon scores 0.0 and is stored. */
    public function testNormalScoresTheSameBeaconZero(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'spam_detection', 'normal');

        $props = $this->beacon(false);
        $this->fireEvent('base.page_request', $props);

        $rows = $this->rowsFor($props['visitor_id']);

        $this->assertArrayHasKey('page_view', $rows);
        $this->assertEquals(0.0, (float) $rows['page_view']['spam_score']);
        $this->assertNotNull($rows['page_view']['spam_score'], '0.0, not NULL: the row was scored');
        $this->assertNull($rows['page_view']['spam_rules']);
    }

    /**
     * Queued: the headers ride the envelope, the drain scores them, and the
     * drain writes each key once however many beacons it blocked.
     */
    public function testTheQueuedPathKeepsTheHeadersAndCountsOncePerDrain(): void
    {
        $this->queueDir = sys_get_temp_dir() . '/owa-spam-queue-' . bin2hex(random_bytes(4)) . '/';
        TrackerIngest::$queue = new \OWA\Module\Base\Classes\FileEventQueue(array('path' => $this->queueDir));
        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracker_ingest', true);

        $stored = $this->beacon(true);
        $this->assertTrue((bool) $this->fireEvent('base.page_request', $stored));
        $this->assertTrue((bool) $this->fireEvent('base.page_request', $this->beacon(false)));
        $this->assertTrue((bool) $this->fireEvent('base.page_request', $this->beacon(false)));

        $this->assertSame(array(), $this->rowsFor($stored['visitor_id']), 'queued, not yet ingested');

        // The envelopes carry what the edge captured: read from the queue's file, before the drain receives them.
        $lines = array_values(array_filter(array_map('trim',
            (array) @file($this->queueDir . 'events.txt'))));
        $this->assertCount(3, $lines, 'three beacons queued');

        $first = (array) (json_decode($lines[0], true)['e']['properties'] ?? array());
        $this->assertSame('no-cors', $first['request_sec_fetch_mode'] ?? null);
        $this->assertSame('https://www.' . self::DOMAIN . '/spam', $first['request_referer'] ?? null);
        $this->assertSame('https://www.' . self::DOMAIN . '/spam', $first['received_page_location'] ?? null);

        $before = Blocks::$writes;
        $counts = TrackerIngest::drain(time() + 30);

        $this->assertSame(3, $counts['ingested']);
        $this->assertSame(3, Blocks::$writes - $before, 'one statement per key for the whole drain');
        $this->assertFalse(Blocks::batching(), 'the drain closed its batch');
        $this->assertSame(array('*' => 2, 'NO_CLIENT_HINTS' => 2, 'NO_FETCH_METADATA' => 2), $this->blocked());

        $rows = $this->rowsFor($stored['visitor_id']);
        $this->assertArrayHasKey('page_view', $rows, 'the beacon under the threshold was stored by the drain');
        $this->assertSame('NO_CLIENT_HINTS=2.5', $rows['page_view']['spam_rules']);
    }

    /** A batch totals in memory and writes on flush. */
    public function testABatchWritesOnFlush(): void
    {
        Blocks::begin();
        Blocks::record(20261104, $this->site, array('NO_FETCH_METADATA'));
        Blocks::record(20261104, $this->site, array('NO_FETCH_METADATA', 'NO_CLIENT_HINTS'));

        $this->assertSame(array(), $this->blocked(), 'nothing written while the batch is open');
        $this->assertSame(2, Blocks::pending()['20261104|' . $this->site . '|*']);

        $this->assertTrue(Blocks::flush());
        $this->assertSame(array('*' => 2, 'NO_CLIENT_HINTS' => 1, 'NO_FETCH_METADATA' => 2), $this->blocked());

        // Added to, not replaced.
        Blocks::record(20261104, $this->site, array());
        $this->assertSame(3, $this->blocked()['*']);
    }

    public function testTheTableIsKeyedByDayProfileAndRule(): void
    {
        $keys = array();

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            "SHOW KEYS FROM owa_" . self::TABLE . " WHERE Key_name = 'PRIMARY'") as $row) {
            $row = (array) $row;
            $keys[(int) $row['Seq_in_index']] = $row['Column_name'];
        }

        ksort($keys);

        $this->assertSame(array('yyyymmdd', 'site_id', 'rule'), array_values($keys));
    }
}
