<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Classes\TouchBackfill: the last-touch evidence for history recorded before
 * ingest wrote it (Update066).
 *
 * One visitor, four sessions: tagged, direct, a search referral, direct. Each
 * landing's prior touch is the non-direct landing before it; the store holds
 * the latest.
 */
final class TouchBackfillTest extends TestCase
{
    const SITE    = 'owa-touch-backfill-site';
    const VISITOR = 7783000000000001;
    const LONER   = 7783000000000002;

    /** @var int */
    private $t0;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes raw');
        }

        $this->clean();
        $this->t0 = (time() - 86400 * 3) * 1000000;
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        $db = owa_coreAPI::dbSingleton();
        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            owa_coreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
        $db->query(sprintf('DELETE FROM %s WHERE visitor_id IN (%d, %d)',
            owa_coreAPI::entityFactory('base.visitor_acquisition')->getTableName(), self::VISITOR, self::LONER));
    }

    /** A landing beacon: its session_start and page_view rows. */
    private function landing(int $visitor, int $session, int $ts, array $evidence): void
    {
        foreach (['session_start', 'page_view'] as $type) {
            $raw = owa_coreAPI::entityFactory('base.event_raw');
            $raw->setProperties($evidence + [
                'id'         => \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, $session, $ts, $type),
                'event_type' => $type,
                'site_id'    => self::SITE,
                'visitor_id' => $visitor,
                'session_id' => $session,
                'ts'         => $ts,
                'yyyymmdd'   => (int) date('Ymd', intdiv($ts, 1000000)),
                'is_goal_event' => 0,
            ]);
            $this->assertTrue($raw->create());
        }
    }

    /** @return array session => [prior_touch_source, prior_touch_referer_host, prior_touch_ts] of its landing rows */
    private function stamps(): array
    {
        $out = [];

        foreach ((array) owa_coreAPI::dbSingleton()->get_results(sprintf(
            "SELECT session_id, event_type, prior_touch_source, prior_touch_referer_host, prior_touch_ts FROM %s
              WHERE site_id = '%s' ORDER BY session_id, event_type",
            owa_coreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE)) as $row) {
            $out[(int) $row['session_id']][$row['event_type']] =
                [$row['prior_touch_source'], $row['prior_touch_referer_host'], $row['prior_touch_ts']];
        }

        return $out;
    }

    public function testEachLandingTakesTheNonDirectLandingBeforeIt(): void
    {
        $h = 3600 * 1000000;
        $this->landing(self::VISITOR, 1, $this->t0, ['tagged_source' => 'newsletter', 'tagged_medium' => 'email']);
        $this->landing(self::VISITOR, 2, $this->t0 + $h, []);
        $this->landing(self::VISITOR, 3, $this->t0 + 2 * $h, ['referer_host' => 'www.google.com']);
        $this->landing(self::VISITOR, 4, $this->t0 + 3 * $h, []);

        $backfill = new \OWA\Module\Base\Classes\TouchBackfill();
        $this->assertContains(self::SITE, $backfill->sites());
        $this->assertTrue($backfill->site(self::SITE));

        $stamps = $this->stamps();
        $none = [null, null, null];

        $this->assertSame(['page_view' => $none, 'session_start' => $none], $stamps[1], 'nothing came before');

        foreach (['session_start', 'page_view'] as $type) {
            $this->assertSame(['newsletter', null, (string) $this->t0], $stamps[2][$type], "session 2 $type");
            $this->assertSame(['newsletter', null, (string) $this->t0], $stamps[3][$type],
                'a non-direct landing is stamped too; the cube reads its own first');
            $this->assertSame([null, 'www.google.com', (string) ($this->t0 + 2 * $h)], $stamps[4][$type], "session 4 $type");
        }

        $store = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT last_touch_source, last_touch_referer_host, last_touch_ts, acq_ts FROM %s WHERE visitor_id = %d',
            owa_coreAPI::entityFactory('base.visitor_acquisition')->getTableName(), self::VISITOR));

        $this->assertSame([null, 'www.google.com', (string) ($this->t0 + 2 * $h), null], array_values($store),
            'the latest non-direct landing, and no acquisition invented');

        // Again, and nothing changes.
        $this->assertTrue($backfill->site(self::SITE));
        $this->assertSame($stamps, $this->stamps());
    }

    /** A newer touch ingest already recorded is not replaced by older history. */
    public function testANewerStoredTouchStays(): void
    {
        $this->landing(self::LONER, 9, $this->t0, ['tagged_source' => 'old']);

        $future = $this->t0 + 86400 * 1000000;
        $store = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $store->setProperties(['visitor_id' => self::LONER, 'site_id' => self::SITE,
            'last_touch_source' => 'newer', 'last_touch_ts' => $future, 'last_seen' => 202601]);
        $this->assertTrue($store->create());

        $this->assertTrue((new \OWA\Module\Base\Classes\TouchBackfill())->site(self::SITE));

        $row = owa_coreAPI::dbSingleton()->get_row(sprintf('SELECT last_touch_source FROM %s WHERE visitor_id = %d',
            owa_coreAPI::entityFactory('base.visitor_acquisition')->getTableName(), self::LONER));

        $this->assertSame('newer', $row['last_touch_source']);
    }
}
