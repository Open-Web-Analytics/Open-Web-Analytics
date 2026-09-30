<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\VisitorExpiry;

/**
 * A visitor-store row goes only when raw holds none of its visitor's events.
 *
 * The fixtures are dated in the 1980s and 1990s and every call uses a cutoff
 * no later than 199001, because the store this runs against is shared: no real
 * row is ever old enough to be reached.
 */
final class VisitorExpiryTest extends TestCase
{
    const SITE    = 'visitor-expiry-fixture';
    const VISITOR = 8899100000000000;

    private static int $seq = 0;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This deletes rows from the visitor store.');
        }

        $this->clear();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->clear();
        }
    }

    private function clear(): void
    {
        $db = owa_coreAPI::dbSingleton();

        foreach ([VisitorExpiry::table(), VisitorExpiry::rawTable()] as $table) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'", $table, self::SITE));
        }
    }

    private function seed(?int $last_seen): int
    {
        $visitor = self::VISITOR + (++self::$seq);

        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id' => $visitor,
            'site_id'    => self::SITE,
            'acq_source' => 'alice.example',
            'acq_ts'     => 1000000,
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_visitor_acquisition');

        owa_coreAPI::dbSingleton()->query(sprintf('UPDATE %s SET last_seen = %s WHERE visitor_id = %d',
            VisitorExpiry::table(), $last_seen === null ? 'NULL' : (string) $last_seen, $visitor));

        return $visitor;
    }

    /** One event for the visitor in raw, today. */
    private function seedRaw(int $visitor): void
    {
        $n  = ++self::$seq;
        $ts = (time() - 3600) * 1000000 + $n;

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, 8899200000000000 + $n, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => $visitor,
            'session_id'    => 8899200000000000 + $n,
            'ts'            => $ts,
            'yyyymmdd'      => (int) date('Ymd'),
            'page_location' => 'https://example.test/',
            'page_path'     => '/',
            'is_goal_event' => 0,
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');
    }

    /** @return int[] the fixture visitors still in the store */
    private function remaining(): array
    {
        $ids = [];

        foreach ((array) owa_coreAPI::dbSingleton()->get_results(sprintf(
                "SELECT visitor_id FROM %s WHERE site_id = '%s' ORDER BY visitor_id",
                VisitorExpiry::table(), self::SITE)) as $row) {
            $ids[] = (int) $row['visitor_id'];
        }

        return $ids;
    }

    /** Before the cutoff month goes; the cutoff month itself is kept. */
    public function testARowLastSeenBeforeTheCutoffMonthGoes(): void
    {
        $gone = $this->seed(198912);
        $kept = $this->seed(199001);

        $this->assertSame(1, VisitorExpiry::deleteAll(199001));

        $this->assertSame([$kept], $this->remaining());
        $this->assertNotContains($gone, $this->remaining());
    }

    /**
     * RAW DECIDES. ingest sets last_seen only when it creates the row, so a
     * visitor back after a long gap has an old last_seen until the next build.
     * Their event is in raw, and the row it will be stamped from stays.
     */
    public function testARowWhoseVisitorStillHasARawEventIsKept(): void
    {
        $returning = $this->seed(198506);
        $this->seedRaw($returning);

        $gone = $this->seed(198506);

        $this->assertSame(1, VisitorExpiry::countExpired(199001));
        $this->assertSame(1, VisitorExpiry::deleteAll(199001));

        $this->assertSame([$returning], $this->remaining());
        $this->assertNotContains($gone, $this->remaining());
    }

    public function testAnUndatedRowIsKept(): void
    {
        $zero = $this->seed(0);
        $null = $this->seed(null);

        $this->assertSame(0, VisitorExpiry::deleteAll(199001));
        $this->assertSame([$zero, $null], $this->remaining());
    }

    public function testItDeletesInBatchesUntilNothingIsLeft(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seed(198001);
        }

        $this->assertSame(5, VisitorExpiry::countExpired(199001));

        $this->assertSame(2, VisitorExpiry::deleteBatch(199001, 2));
        $this->assertSame(2, VisitorExpiry::deleteBatch(199001, 2));
        $this->assertSame(1, VisitorExpiry::deleteBatch(199001, 2));
        $this->assertSame(0, VisitorExpiry::deleteBatch(199001, 2));

        $beats = 0;
        $this->seed(198001);
        $this->assertSame(1, VisitorExpiry::deleteAll(199001, function () use (&$beats) { $beats++; }, 2));
        $this->assertGreaterThan(0, $beats, 'the caller gets a heartbeat between batches');

        $this->assertSame([], $this->remaining());
    }

    /**
     * THE EARLIER OF RAW'S OLDEST MONTH AND FOURTEEN MONTHS AGO.
     *
     * Raw's horizon is what a rebuild can still read; fourteen months is how
     * long a returning visitor can still carry their cookie. Under a short
     * keep= the second binds, so a visitor seen last month keeps their row.
     */
    public function testTheCutoffIsTheEarlierOfRawAndTheCookieFloor(): void
    {
        $now = new DateTimeImmutable('2026-09-15');

        $this->assertSame(202109, VisitorExpiry::cutoff(202109, $now), 'raw kept longer than any cookie');
        $this->assertSame(202507, VisitorExpiry::cutoff(202603, $now), 'keep=6: the cookie floor binds');
        $this->assertSame(202507, VisitorExpiry::cutoff(202507, $now));
        $this->assertSame(202411, VisitorExpiry::cutoff(202609, new DateTimeImmutable('2026-01-01')),
            'across a year boundary');
        $this->assertNull(VisitorExpiry::cutoff(null, $now), 'an empty raw deletes nothing');
    }

    /** The oldest month raw holds, read from the data, as the plain MIN would give it. */
    public function testTheOldestRawMonthIsReadFromTheData(): void
    {
        $row = (array) owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT MIN(yyyymmdd) AS m FROM %s', VisitorExpiry::rawTable()));

        $this->assertSame(empty($row['m']) ? null : intdiv((int) $row['m'], 100),
            VisitorExpiry::oldestRawMonth());
    }
}
