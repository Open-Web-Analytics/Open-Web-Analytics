<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The pass, end to end: raw rows in, denormalised rows out of owa_event.
 *
 * Runs the real statement and the real swap against the configured database,
 * because every interesting part of this is SQL -- the window frames, the
 * classifier, the join to the visitor store -- and none of it can be asserted
 * by reading PHP.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE. Three visitors that differ in what they
 * arrived with and in whether the visitor store knows them, and one session
 * still running. A fixture where every row carries the same shape passes
 * against a statement that stamps one row's values onto all of them.
 *
 * It rebuilds a whole partition, which is the unit the swap works in, so the
 * fixture rows are removed and the partition rebuilt again in tearDown.
 */
final class DenormalisationPassTest extends TestCase
{
    /** Visitors, sessions and events of the fixture, all numeric ids. */
    const SITE = 'owa-denorm-test-site';

    const VISITOR_TAGGED  = 7771000000000001;
    const VISITOR_REFERRED = 7771000000000002;
    const VISITOR_DIRECT  = 7771000000000003;
    const VISITOR_OPEN    = 7771000000000004;
    const VISITOR_LONG_REF = 7771000000000005;

    /** @var array id => row, as seeded */
    private $seeded = [];

    /** @var int */
    private $yyyymmdd;

    /**
     * The fixture's clock, fixed once per test.
     *
     * An event id is derived from its ts, so a helper calling time() again
     * would compute a different id the moment the second ticked over -- and
     * look up a row that was never seeded.
     *
     * @var int microseconds, two hours ago, so the seeded sessions read closed
     */
    private $t0;

    /** @var int microseconds, now: a session still inside the idle timeout */
    private $t_open;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping pass test.');
        }

        $this->yyyymmdd = (int) date('Ymd');
        $this->t0       = (time() - 7200) * 1000000;
        $this->t_open   = time() * 1000000;

        $this->seedRaw();
        $this->seedVisitorStore();
        $this->rebuild();
    }

    protected function tearDown(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            $this->table('base.event_raw'), $db->prepare(self::SITE)));

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            $this->table('base.visitor_acquisition'), $db->prepare(self::SITE)));

        // Leave owa_event holding what raw now says, which is nothing of ours.
        $this->rebuild();
    }

    private function table(string $entity): string
    {
        return owa_coreAPI::entityFactory($entity)->getTableName();
    }

    private function seedRaw(): void
    {
        $t = $this->t0;

        // A tagged arrival, three events, one session. Only the landing event
        // carries tags -- which is the whole reason the pass exists.
        $this->seed('page_view', self::VISITOR_TAGGED, 8881000000000001, $t, [
            'page_location'   => 'https://example.test/landing?utm_source=newsletter',
            'page_path'       => '/landing',
            'page_query'      => 'utm_source=newsletter',
            'page_title'      => 'Landing',
            'tagged_source'   => 'newsletter',
            'tagged_medium'   => 'email',
            'tagged_campaign' => 'spring',
            'tagged_ad'       => 'banner-a',
        ]);

        $this->seed('page_view', self::VISITOR_TAGGED, 8881000000000001, $t + 60000000, [
            'page_location' => 'https://example.test/two',
            'page_path'     => '/two',
            'page_title'    => 'Two',
        ]);

        $this->seed('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000, [
            'page_location' => 'https://example.test/two',
            'page_path'     => '/two',
            'page_title'    => 'Two',
        ]);

        // No tags, a search engine referrer, and no row in the visitor store.
        $this->seed('page_view', self::VISITOR_REFERRED, 8881000000000002, $t, [
            'page_location' => 'https://example.test/from-search',
            'page_path'     => '/from-search',
            'page_title'    => 'From search',
            'referer_url'   => 'https://www.google.com/search?q=web+analytics',
        ]);

        // Nothing at all: no tags, no referrer.
        $this->seed('page_view', self::VISITOR_DIRECT, 8881000000000003, $t, [
            'page_location' => 'https://example.test/direct',
            'page_path'     => '/direct',
            'page_title'    => 'Direct',
        ]);

        // A referrer that parses to a host far wider than `source` holds. No
        // scheme and no path, so the whole 900-character string is the host.
        $this->seed('page_view', self::VISITOR_LONG_REF, 8881000000000005, $t, [
            'page_location' => 'https://example.test/long-referrer',
            'page_path'     => '/long-referrer',
            'page_title'    => 'Long referrer',
            'referer_url'   => str_repeat('a', 900),
        ]);

        // Still inside the idle timeout, so its last event is not an exit yet.
        $this->seed('page_view', self::VISITOR_OPEN, 8881000000000004, $this->t_open, [
            'page_location' => 'https://example.test/open',
            'page_path'     => '/open',
            'page_title'    => 'Open',
        ]);
    }

    /**
     * One raw row, with the id the pass will find it under.
     */
    private function seed(string $type, int $visitor, int $session, int $ts, array $row): void
    {
        $id = \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, $session, $ts, $type);

        $row += [
            'id'            => $id,
            'event_type'    => $type,
            'site_id'       => self::SITE,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'ts'            => $ts,
            'yyyymmdd'      => $this->yyyymmdd,
            'is_goal_event' => 0,
        ];

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties($row);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');

        $this->seeded[$id] = $row;
    }

    /**
     * An acquisition for the tagged visitor only. The others have no row, which
     * is what the sentinel is for.
     */
    private function seedVisitorStore(): void
    {
        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties([
            'visitor_id'   => self::VISITOR_TAGGED,
            'site_id'      => self::SITE,
            'acq_source'   => 'partner-site',
            'acq_medium'   => 'referral',
            'acq_campaign' => 'launch',
            'acq_ad'       => 'tile-b',
            'acq_ts'       => $this->t0,
            'last_seen'    => (int) substr((string) $this->yyyymmdd, 0, 6),
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_visitor_acquisition');
    }

    private function rebuild(): void
    {
        $pass  = new \OWA\Module\Base\Classes\DenormalisationPass();
        $spans = $pass->partitions($this->yyyymmdd, $this->yyyymmdd);

        $this->assertNotEmpty($spans, 'owa_event has no dated partition covering today');

        foreach ($spans as $span) {
            $result = $pass->rebuild($span);
            $this->assertTrue($result['ok'], 'rebuild of ' . $span['name'] . ' failed');
        }
    }

    /**
     * One denormalised row, by the id its raw row was seeded under.
     */
    private function built(string $type, int $visitor, int $session, int $ts): array
    {
        $id = \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, $session, $ts, $type);

        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT * FROM %s WHERE id = %d AND yyyymmdd = %d',
            $this->table('base.event'), $id, $this->yyyymmdd));

        $this->assertNotEmpty($row, "no denormalised row for $type/$visitor");

        return $row;
    }

    public function testEveryRawRowBecomesExactlyOneEventRow(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $in = $db->get_row(sprintf("SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            $this->table('base.event_raw'), $db->prepare(self::SITE), $this->yyyymmdd));

        $out = $db->get_row(sprintf("SELECT COUNT(*) AS n FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            $this->table('base.event'), $db->prepare(self::SITE), $this->yyyymmdd));

        $this->assertSame(7, (int) $in['n']);
        $this->assertSame((int) $in['n'], (int) $out['n'],
            'The pass enriches. It creates nothing and drops nothing.');
    }

    public function testTheSessionsTagsAreStampedOnEveryRowOfIt(): void
    {
        $t = $this->t0;

        // The second and third events carry no tags of their own.
        foreach ([
            ['page_view', $t + 60000000],
            ['click', $t + 120000000],
        ] as [$type, $ts]) {

            $row = $this->built($type, self::VISITOR_TAGGED, 8881000000000001, $ts);

            $this->assertSame('newsletter', $row['source'], "$type source");
            $this->assertSame('email', $row['medium'], "$type medium");
            $this->assertSame('spring', $row['campaign'], "$type campaign");
            $this->assertSame('banner-a', $row['ad'], "$type ad");

            $this->assertNull($row['tagged_source'],
                'raw still says what it observed: no tags on a mid-session row');
        }
    }

    public function testTheLandingPageIsStampedOnEveryRowOfTheSession(): void
    {
        $row = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $this->t0 + 120000000);

        $this->assertSame('https://example.test/landing?utm_source=newsletter',
            $row['landing_page_location']);
        $this->assertSame('/landing', $row['landing_page_path']);
        $this->assertSame('utm_source=newsletter', $row['landing_page_query']);
        $this->assertSame('Landing', $row['landing_page_title']);

        // The row's own page is unchanged: the landing page is a second fact
        // about it, not a replacement.
        $this->assertSame('/two', $row['page_path']);
    }

    public function testAnUntaggedReferrerIsClassified(): void
    {
        $row = $this->built('page_view', self::VISITOR_REFERRED, 8881000000000002, $this->t0);

        $this->assertSame('google.com', $row['source'], 'the referring host, without www');
        $this->assertSame('organic-search', $row['medium']);
        $this->assertNull($row['campaign'], 'no tag means no campaign, which is an absence');
    }

    public function testNoReferrerAndNoTagsIsDirect(): void
    {
        $row = $this->built('page_view', self::VISITOR_DIRECT, 8881000000000003, $this->t0);

        $this->assertSame('direct', $row['source']);
        $this->assertSame('direct', $row['medium']);
    }

    public function testAcquisitionIsStampedFromTheVisitorStore(): void
    {
        // Seeded acquisition differs from the session's own attribution, so a
        // statement copying `source` into acq_source would fail here.
        $row = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $this->t0 + 120000000);

        $this->assertSame('partner-site', $row['acq_source']);
        $this->assertSame('referral', $row['acq_medium']);
        $this->assertSame('launch', $row['acq_campaign']);
        $this->assertSame('tile-b', $row['acq_ad']);
    }

    public function testAVisitorWithNoStoreRowGetsTheSentinel(): void
    {
        $row = $this->built('page_view', self::VISITOR_REFERRED, 8881000000000002, $this->t0);

        $sentinel = \OWA\Module\Base\Classes\V2Event::UNRESOLVED;

        $this->assertSame($sentinel, $row['acq_source']);
        $this->assertSame($sentinel, $row['acq_medium']);
        $this->assertSame($sentinel, $row['acq_campaign']);
        $this->assertSame($sentinel, $row['acq_ad']);

        // NULL and the sentinel are different statements, and the row makes
        // both: nothing was observed, and nothing could be resolved.
        $this->assertNull($row['acq_search_terms']);
    }

    public function testAnOverlongParsedHostIsClampedRatherThanFailingTheRun(): void
    {
        // Without the clamp this row aborts the INSERT under STRICT_ALL_TABLES
        // and nothing is rebuilt, so setUp() fails before reaching this.
        $row = $this->built('page_view', self::VISITOR_LONG_REF, 8881000000000005, $this->t0);

        $this->assertSame(255, strlen($row['source']));
        $this->assertSame(str_repeat('a', 255), $row['source']);
    }

    public function testIsExitMarksTheLastEventOfAClosedSession(): void
    {
        $t = $this->t0;

        $this->assertSame(0, (int) $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t)['is_exit']);
        $this->assertSame(0, (int) $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t + 60000000)['is_exit']);
        $this->assertSame(1, (int) $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000)['is_exit']);
    }

    public function testASessionStillInsideTheTimeoutHasNoExit(): void
    {
        // The last event of a running session is not the exit yet, and a later
        // rebuild is what settles it.
        $row = $this->built('page_view', self::VISITOR_OPEN, 8881000000000004, $this->t_open);

        $this->assertSame(0, (int) $row['is_exit']);
    }

    public function testPrevEventTsWalksTheVisitorsOwnEvents(): void
    {
        $t = $this->t0;

        $first  = $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t);
        $second = $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t + 60000000);
        $third  = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000);

        $this->assertNull($first['prev_event_ts']);
        $this->assertSame($t, (int) $second['prev_event_ts']);
        $this->assertSame($t + 60000000, (int) $third['prev_event_ts']);
    }

    public function testBuiltAtIsStampedAndConstantWithinThePartition(): void
    {
        $t = $this->t0;

        $a = $this->built('page_view', self::VISITOR_TAGGED, 8881000000000001, $t);
        $b = $this->built('page_view', self::VISITOR_DIRECT, 8881000000000003, $t);

        $this->assertGreaterThan(0, (int) $a['built_at']);
        $this->assertSame($a['built_at'], $b['built_at'],
            'One run writes one partition, so the as-of is the same on all of it.');
    }

    public function testRebuildingTwiceProducesTheSameRows(): void
    {
        $t   = $this->t0;
        $before = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000);

        $this->rebuild();

        $after = $this->built('click', self::VISITOR_TAGGED, 8881000000000001, $t + 120000000);

        unset($before['built_at'], $after['built_at']);

        $this->assertSame($before, $after,
            'Convergent: the scheduler runs a missed occurrence once, not once per occurrence.');
    }

    public function testLastSeenIsAdvancedForVisitorsInThePartition(): void
    {
        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT last_seen FROM %s WHERE visitor_id = %d',
            $this->table('base.visitor_acquisition'), self::VISITOR_TAGGED));

        $this->assertSame((int) substr((string) $this->yyyymmdd, 0, 6), (int) $row['last_seen']);
    }
}
