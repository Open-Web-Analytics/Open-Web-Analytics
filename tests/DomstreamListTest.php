<?php

use PHPUnit\Framework\TestCase;

/**
 * A row in the domstreams report is a RECORDING, not a row of owa_domstream.
 *
 * WHAT THIS EXISTS FOR
 *
 * The tracker flushes its event queue on a timer, so one recording is stored as
 * however many rows it took to hold it, all sharing a domstream_guid. The list
 * groups them back together -- and the previous query, having grouped, then
 * selected `duration`, `page_url` and the viewport as BARE columns.
 *
 * That is not an error on this install, because sql_mode is set to '' on every
 * connection and ONLY_FULL_GROUP_BY is therefore off: MySQL answers with an
 * arbitrary row's value. For duration it is not cosmetic. `duration` is
 * CUMULATIVE elapsed seconds at each flush, so a recording stored in three rows
 * carries three different durations and the list showed whichever one the
 * optimiser reached first.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE
 *
 * One recording of three chunks with durations 10, 45 and 120. Every candidate
 * answer is a different number -- MAX 120, MIN 10, SUM 175, first-row 10 -- so
 * the test can tell them apart. A fixture where a recording is one row, or
 * where the chunks agree, would pass against the bug it was written for.
 */
final class DomstreamListTest extends TestCase
{
    private const SITE = 'domstream-list-test-site';

    /** Recording A: three chunks. B: one. C: another session, for the segment. */
    private const GUID_A = '9100000000000000001';
    private const GUID_B = '9100000000000000002';
    private const GUID_C = '9100000000000000003';

    private const SESSION_1 = '9100000000000000101';
    private const SESSION_2 = '9100000000000000102';

    private static $seeded = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the domstream list is a database query');
        }

        $this->seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query(
            'DELETE FROM owa_domstream WHERE site_id = ?', array(self::SITE));
    }

    private function seed(): void
    {
        if (self::$seeded) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DELETE FROM owa_domstream WHERE site_id = ?', array(self::SITE));

        $day  = (int) date('Ymd');
        $base = mktime(9, 0, 0);

        /*
         * guid, session, duration, timestamp offset, bytes of `events`.
         *
         * The offsets are deliberately NOT in duration order for recording A:
         * the middle chunk is written last. started must still be the earliest
         * timestamp, and duration still the largest, whatever order the rows
         * come back in.
         */
        $chunks = array(
            array(self::GUID_A, self::SESSION_1,  10, 0,   100),
            array(self::GUID_A, self::SESSION_1, 120, 300, 250),
            array(self::GUID_A, self::SESSION_1,  45, 120, 150),

            array(self::GUID_B, self::SESSION_1,   7, 600,  80),

            array(self::GUID_C, self::SESSION_2,  33, 900, 400),
            array(self::GUID_C, self::SESSION_2,  60, 960, 600),
        );

        foreach ($chunks as $i => $chunk) {

            list($guid, $session, $duration, $offset, $bytes) = $chunk;

            $ds = \OWA\Core\CoreAPI::entityFactory('base.domstream');

            $ds->set('id', (string) (9100000000000009000 + $i));
            $ds->set('site_id', self::SITE);
            $ds->set('domstream_guid', $guid);
            $ds->set('session_id', $session);
            $ds->set('visitor_id', '9100000000000000201');
            $ds->set('document_id', '9100000000000000301');
            $ds->set('timestamp', $base + $offset);
            $ds->set('yyyymmdd', $day);
            $ds->set('duration', $duration);
            $ds->set('page_url', 'https://domstream.test/page');
            $ds->set('page_width', 1280);
            $ds->set('page_height', 800);
            $ds->set('events', str_repeat('e', $bytes));
            $ds->create();
        }

        self::$seeded = true;
    }

    private function controller(array $params = array())
    {
        // $params FIRST: with `+` the left operand wins, so defaults on the
        // left would silently ignore everything a caller passed.
        return new \OWA\Module\Base\Controller\ReportDomstreams($params + array(
            'siteId'    => self::SITE,
            'startDate' => (string) date('Ymd'),
            'endDate'   => (string) date('Ymd'),
        ));
    }

    /** @return array raw grouped rows, keyed by guid */
    private function recordings(array $params = array(), $subjects = null): array
    {
        $m = new ReflectionMethod('\OWA\Module\Base\Controller\ReportDomstreams', 'listRecordings');
        $m->setAccessible(true);

        $out = array();

        foreach ($m->invoke($this->controller($params), '', $subjects, 1) as $row) {
            $out[$row['domstream_guid']] = $row;
        }

        return $out;
    }

    private function total(array $params = array(), $subjects = null): int
    {
        $m = new ReflectionMethod('\OWA\Module\Base\Controller\ReportDomstreams', 'countRecordings');
        $m->setAccessible(true);

        return (int) $m->invoke($this->controller($params), '', $subjects);
    }

    public function testAMultiChunkRecordingIsOneRow(): void
    {
        $rows = $this->recordings();

        $this->assertCount(3, $rows,
            'six stored chunks are three recordings, not six rows');

        $this->assertSame(3, (int) $rows[self::GUID_A]['segments'],
            'recording A was flushed three times');
    }

    /**
     * The defect this file is named for.
     */
    public function testDurationIsTheWholeRecordingNotOneChunk(): void
    {
        $rows = $this->recordings();

        $this->assertSame(120, (int) $rows[self::GUID_A]['duration'],
            'duration is cumulative, so the recording lasted as long as its last flush');

        // Named explicitly, because each is a plausible wrong answer that a
        // symmetric fixture would not distinguish from the right one.
        $this->assertNotSame(175, (int) $rows[self::GUID_A]['duration'], 'summed the chunks');
        $this->assertNotSame(10,  (int) $rows[self::GUID_A]['duration'], 'took the first chunk');
    }

    public function testTheRecordingIsTimedFromWhenItStarted(): void
    {
        $rows = $this->recordings();

        $started = (int) $rows[self::GUID_A]['started'];

        $this->assertSame(mktime(9, 0, 0), $started,
            'a recording happened when it BEGAN, not when its last chunk arrived');
    }

    public function testSizeIsTheWholeRecording(): void
    {
        $rows = $this->recordings();

        $this->assertSame(500, (int) $rows[self::GUID_A]['bytes'],
            'the recording is as big as all of its chunks together');
    }

    /** A guid is a recording; the pager counts recordings. */
    public function testTheTotalCountsRecordingsRatherThanRows(): void
    {
        $this->assertSame(3, $this->total(),
            'counting rows would page a three-chunk recording as three');
    }

    /**
     * The segment restricts the list, and the count agrees with it.
     *
     * Both halves matter: a total computed under different conditions from the
     * rows gives a pager that offers pages which come back empty.
     */
    public function testASegmentRestrictsBothTheRowsAndTheTotal(): void
    {
        $rows = $this->recordings(array(), array(self::SESSION_2));

        // Keys back through array_keys() come out as ints -- PHP casts numeric
        // string keys -- so they are compared as strings.
        $this->assertSame(array(self::GUID_C), array_map('strval', array_keys($rows)),
            'only the recording made during the selected visit');

        $this->assertSame(1, $this->total(array(), array(self::SESSION_2)));
    }

    /** A segment that matched nobody lists nothing -- not everything. */
    public function testASegmentMatchingNobodyListsNothing(): void
    {
        $this->assertSame(array(), $this->recordings(array(), array()));
        $this->assertSame(0, $this->total(array(), array()));
    }

    /** No segment asked for is not the same as a segment that matched nobody. */
    public function testNoSegmentListsEverything(): void
    {
        $this->assertCount(3, $this->recordings(array(), null));
        $this->assertSame(3, $this->total(array(), null));
    }

    /**
     * The reporting period bounds the list.
     *
     * Closed at both ends, because owa_domstream is range-partitioned on
     * yyyymmdd and it is the heaviest fact table -- an open bound reads every
     * partition from there on.
     */
    public function testTheReportingPeriodBoundsTheList(): void
    {
        $elsewhere = array('startDate' => '20200101', 'endDate' => '20200102');

        $this->assertSame(array(), $this->recordings($elsewhere));
        $this->assertSame(0, $this->total($elsewhere));
    }

    /** Newest first, by when each recording started. */
    public function testRecordingsAreListedNewestFirst(): void
    {
        $m = new ReflectionMethod('\OWA\Module\Base\Controller\ReportDomstreams', 'listRecordings');
        $m->setAccessible(true);

        $order = array();

        foreach ($m->invoke($this->controller(), '', null, 1) as $row) {
            $order[] = $row['domstream_guid'];
        }

        $this->assertSame(
            array(self::GUID_C, self::GUID_B, self::GUID_A), $order,
            'C started last, A first');
    }

    /**
     * The cells the grid draws, end to end through the controller.
     *
     * The numbers above are the query's; these are what a reader sees.
     */
    public function testTheGridRowCarriesFormattedValues(): void
    {
        $m = new ReflectionMethod('\OWA\Module\Base\Controller\ReportDomstreams', 'asResultSet');
        $m->setAccessible(true);

        $set = $m->invoke($this->controller(), array_values($this->recordings()));

        $row = null;

        foreach ($set['resultsRows'] as $candidate) {
            if ($candidate['duration']['value'] === 120) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row, 'recording A is in the result set');

        $this->assertSame('0:02:00', $row['duration']['formatted_value'],
            'two minutes reads as two minutes');
        $this->assertSame('3', $row['segments']['formatted_value']);
        $this->assertSame('500 B', $row['size']['formatted_value']);

        // The player cell carries DATA, not markup: a named formatter builds
        // the link. If a report ever starts assembling the anchor itself, the
        // grid has no way to tell that markup from markup it built.
        $this->assertIsArray($row['play']['value']);
        $this->assertStringNotContainsString('<a', json_encode($row['play']['value']));

        foreach (array('overlay', 'url', 'width', 'height') as $key) {
            $this->assertArrayHasKey($key, $row['play']['value']);
        }

        $this->assertSame(1280, $row['play']['value']['width']);
        $this->assertSame(800, $row['play']['value']['height']);
    }

    /** A duration is a length, not a time of day. */
    public function testDurationsBeyondADayStillRead(): void
    {
        $m = new ReflectionMethod('\OWA\Module\Base\Controller\ReportDomstreams', 'asClock');
        $m->setAccessible(true);

        $this->assertSame('0:00:07', $m->invoke(null, 7));
        $this->assertSame('1:00:00', $m->invoke(null, 3600));

        // 26 hours. date('H:i:s') on a timestamp would wrap this to 02:00:00.
        $this->assertSame('26:00:00', $m->invoke(null, 93600));
    }
}
