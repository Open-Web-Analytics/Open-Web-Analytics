<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/DomstreamFixtures.php';

/**
 * A row in the recordings report is a RECORDING: every chunk sharing a
 * recording_id, grouped.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE. Recording A's three chunks end at 10s,
 * 120s and 45s (offset + duration), are stored out of order, and have
 * different durations -- so the recording's length (120s, the latest end), the
 * longest chunk (60s), the summed durations (105s) and the first chunk (10s)
 * are four different numbers, and the test can tell the right one from each
 * plausible wrong one.
 */
final class DomstreamReportListTest extends TestCase
{
    private const SITE = 'domstream-list-test-site';

    private const REC_A = '9100000000000000001';
    private const REC_B = '9100000000000000002';
    private const REC_C = '9100000000000000003';

    private const SESSION_1 = '9100000000000000101';
    private const SESSION_2 = '9100000000000000102';

    private static $seeded = false;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the recordings list is a database query');
        }

        DomstreamFixtures::ensure();
        $this->seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (function_exists('owa_test_db_available') && owa_test_db_available()) {
            DomstreamFixtures::deleteSite(self::SITE);
        }
    }

    private function seed(): void
    {
        if (self::$seeded) {
            return;
        }

        DomstreamFixtures::deleteSite(self::SITE);

        $base = mktime(9, 0, 0);

        // recording, session, seq, offset ms, duration ms, arrived +s, clicks, keys, samples, path
        foreach ([
            [self::REC_A, self::SESSION_1, 1,     0, 10000,   0, 1, 0, 10, '/page'],
            [self::REC_A, self::SESSION_1, 3, 10000, 35000, 300, 0, 1,  5, '/page'],
            [self::REC_A, self::SESSION_1, 2, 60000, 60000, 120, 2, 3, 20, '/page'],
            [self::REC_B, self::SESSION_1, 1,     0,  7000, 600, 0, 0,  3, '/other'],
            [self::REC_C, self::SESSION_2, 1,     0, 33000, 900, 1, 0,  4, '/page'],
            [self::REC_C, self::SESSION_2, 2, 33000, 27000, 960, 0, 0,  6, '/page'],
        ] as $c) {
            DomstreamFixtures::chunk(self::SITE, [
                'recording_id'   => $c[0],
                'session_id'     => $c[1],
                'seq'            => $c[2],
                'offset_ms'      => $c[3],
                'duration_ms'    => $c[4],
                'ts'             => ($base + $c[5]) * 1000000,
                'click_count'    => $c[6],
                'keypress_count' => $c[7],
                'sample_count'   => $c[8],
                'page_location'  => 'https://domstream.test' . $c[9],
                'page_path'      => $c[9],
                'viewport_w'     => 1280,
                'viewport_h'     => 800,
            ]);
        }

        self::$seeded = true;
    }

    private function controller(array $params = [])
    {
        return new \OWA\Module\Domstream\Controller\ReportDomstreams($params + [
            'siteId'    => self::SITE,
            'startDate' => (string) date('Ymd'),
            'endDate'   => (string) date('Ymd'),
        ]);
    }

    private function call(string $method, array $params, ...$args)
    {
        $m = new ReflectionMethod(\OWA\Module\Domstream\Controller\ReportDomstreams::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->controller($params), ...$args);
    }

    /** @return array grouped rows keyed by recording id */
    private function recordings(array $params = [], $subjects = null, string $path = ''): array
    {
        $out = [];

        foreach ($this->call('listRecordings', $params, $path, $subjects, 1) as $row) {
            $row = (array) $row;
            $out[(string) $row['recording_id']] = $row;
        }

        return $out;
    }

    private function total(array $params = [], $subjects = null, string $path = ''): int
    {
        return (int) $this->call('countRecordings', $params, $path, $subjects);
    }

    public function testAMultiChunkRecordingIsOneRow(): void
    {
        $rows = $this->recordings();

        $this->assertCount(3, $rows, 'six stored chunks are three recordings');
        $this->assertSame(35, (int) $rows[self::REC_A]['samples'], 'its samples are all its chunks\'');
    }

    public function testTheLengthIsTheLatestEndNotAChunk(): void
    {
        $length = (int) $this->recordings()[self::REC_A]['length_ms'];

        $this->assertSame(120000, $length, 'the recording ends where its latest chunk ends');
        $this->assertNotSame(60000, $length, 'took the longest chunk');
        $this->assertNotSame(105000, $length, 'summed the chunks');
        $this->assertNotSame(10000, $length, 'took the first chunk');
    }

    public function testClicksAndKeyPressesAreTheWholeRecording(): void
    {
        $row = $this->recordings()[self::REC_A];

        $this->assertSame(3, (int) $row['clicks']);
        $this->assertSame(4, (int) $row['keypresses']);
    }

    public function testTheRecordingIsTimedFromItsFirstArrival(): void
    {
        $this->assertSame(mktime(9, 0, 0) * 1000000, (int) $this->recordings()[self::REC_A]['started']);
    }

    public function testTheTotalCountsRecordingsRatherThanChunks(): void
    {
        $this->assertSame(3, $this->total());
    }

    public function testASegmentRestrictsBothTheRowsAndTheTotal(): void
    {
        $this->assertSame([self::REC_C], array_map('strval', array_keys($this->recordings([], [self::SESSION_2]))));
        $this->assertSame(1, $this->total([], [self::SESSION_2]));
    }

    public function testASegmentMatchingNobodyListsNothing(): void
    {
        $this->assertSame([], $this->recordings([], []));
        $this->assertSame(0, $this->total([], []));
    }

    /** Page Detail's "Recordings" link asks by the page's path. */
    public function testAPagePathListsThatPagesRecordings(): void
    {
        $this->assertSame([self::REC_B], array_map('strval', array_keys($this->recordings([], null, '/other'))));
        $this->assertSame(2, $this->total([], null, '/page'));
    }

    public function testTheReportingPeriodBoundsTheList(): void
    {
        $elsewhere = ['startDate' => '20200101', 'endDate' => '20200102'];

        $this->assertSame([], $this->recordings($elsewhere));
        $this->assertSame(0, $this->total($elsewhere));
    }

    public function testRecordingsAreListedNewestFirst(): void
    {
        $this->assertSame([self::REC_C, self::REC_B, self::REC_A],
            array_map('strval', array_keys($this->recordings())));
    }

    public function testTheGridRowCarriesFormattedValuesAndPlayerData(): void
    {
        $set = $this->call('asResultSet', [], array_values($this->recordings()));

        $row = null;
        foreach ($set['resultsRows'] as $candidate) {
            if ($candidate['length']['value'] === 120) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row, 'recording A is in the result set');
        $this->assertSame('0:02:00', $row['length']['formatted_value']);
        $this->assertSame('4', $row['keypresses']['formatted_value']);

        // The player cell carries DATA; the grid's overlayLink formatter builds
        // the link.
        $this->assertIsArray($row['play']['value']);
        $this->assertStringNotContainsString('<a', json_encode($row['play']['value']));
        $this->assertSame('Play', $row['play']['value']['label']);
        $this->assertSame(1280, $row['play']['value']['width']);
        $this->assertSame('https://domstream.test/page', $row['play']['value']['url']);
    }

    public function testLengthsBeyondADayStillRead(): void
    {
        $m = new ReflectionMethod(\OWA\Module\Domstream\Controller\ReportDomstreams::class, 'asClock');
        $m->setAccessible(true);

        $this->assertSame('26:00:00', $m->invoke(null, 93600));
    }
}
