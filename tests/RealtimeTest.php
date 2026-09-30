<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Realtime;

/**
 * A site's last thirty minutes, from raw.
 *
 * Rows are placed at known offsets before a fixed end, and the window is
 * handed that end, so "last 30" and "last 5" are asserted exactly rather than
 * against the clock.
 */
final class RealtimeTest extends TestCase
{
    const PROPERTY = 7789000000000001;
    const SITE     = 'realtime-fixture';
    const VISITOR  = 8899300000000000;

    private static int $seq = 0;

    private int $end;

    private array $goals = [];

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id' => self::PROPERTY, 'name' => 'Realtime fixture', 'domain' => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB, 'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = owa_coreAPI::entityFactory('base.site');
        $site->setProperties([
            'id' => self::PROPERTY * 10, 'site_id' => self::SITE, 'property_id' => self::PROPERTY,
            'name' => 'Realtime fixture profile', 'domain' => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (owa_test_db_available()) {
            self::dropFixture();
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This reads raw.');
        }

        // Now, less a minute, so every seeded row is in the past but today.
        $this->end = (int) round(microtime(true) * 1000000) - 60000000;

        $db = owa_coreAPI::dbSingleton();

        foreach (['base.event_raw', 'base.visitor_acquisition'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                owa_coreAPI::entityFactory($entity)->getTableName(), self::SITE));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->goals as $id) {
            owa_coreAPI::dbSingleton()->query('DELETE FROM owa_goal_event_condition WHERE goal_event_id = ?', [$id]);
            owa_coreAPI::dbSingleton()->query('DELETE FROM owa_goal_event WHERE id = ?', [$id]);
        }
    }

    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                owa_coreAPI::entityFactory($entity)->getTableName(), self::SITE));
        }

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            owa_coreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));
    }

    /** One raw event, $minutes before the window's end. */
    private function event(int $visitor, float $minutes, array $values = []): void
    {
        $n  = ++self::$seq;
        $ts = $this->end - (int) round($minutes * 60 * 1000000);
        $tz = new DateTimeZone(\OWA\Module\Base\Classes\JobStatus::timezone());

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties($values + [
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR + $visitor, 8899400000000000 + $n, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => self::VISITOR + $visitor,
            'session_id'    => 8899400000000000 + $visitor,
            'ts'            => $ts,
            'yyyymmdd'      => (int) (new DateTimeImmutable('@' . intdiv($ts, 1000000)))->setTimezone($tz)->format('Ymd'),
            'page_location' => 'https://example.test/home',
            'page_path'     => '/home',
            'page_title'    => 'Home',
            'is_goal_event' => 0,
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');
    }

    private function store(int $visitor, array $values): void
    {
        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');
        $entity->setProperties($values + ['visitor_id' => self::VISITOR + $visitor, 'site_id' => self::SITE]);

        $this->assertTrue($entity->create(), 'seeding owa_visitor_acquisition');
    }

    private function realtime(): Realtime
    {
        return new Realtime(self::SITE, $this->end);
    }

    public function testActiveUsersCountTheWindowsVisitorsOnly(): void
    {
        $this->event(1, 1);
        $this->event(1, 2);      // the same visitor again
        $this->event(2, 4);
        $this->event(3, 10);
        $this->event(4, 31);     // outside the window

        $users = $this->realtime()->summary()['activeUsers'];

        $this->assertSame(3, $users['last30']);
        $this->assertSame(2, $users['last5']);
    }

    public function testUsersPerMinuteAreOldestFirstEndingThisMinute(): void
    {
        $this->event(1, 0.5);
        $this->event(2, 0.2);
        $this->event(3, 29.5);

        $per = $this->realtime()->summary()['perMinute'];

        $this->assertCount(30, $per);
        $this->assertSame(2, $per[29], 'this minute');
        $this->assertSame(1, $per[0], 'thirty minutes ago');
        $this->assertSame(3, array_sum($per));
    }

    /**
     * EVERY STATEMENT CARRIES THE CLOSED WINDOW: the site, a day range that
     * prunes partitions, and a ts range site_ts can serve. Asserted on the SQL,
     * since a timing test on a fixture this small would pass either way.
     */
    public function testEveryStatementIsBoundedBySiteDaysAndTime(): void
    {
        $this->event(1, 1, ['is_goal_event' => 1]);

        $realtime = $this->realtime();
        $realtime->summary();
        $realtime->visitor((string) (self::VISITOR + 1));

        $this->assertNotEmpty($realtime->statements);

        foreach ($realtime->statements as $sql) {
            $this->assertMatchesRegularExpression(
                '/r\.site_id = \? AND r\.yyyymmdd BETWEEN \d{8} AND \d{8} AND r\.ts > \d+ AND r\.ts <= \d+/', $sql, $sql);
        }

        $explain = (array) owa_coreAPI::dbSingleton()->get_row(
            'EXPLAIN ' . $realtime->statements[0], [self::SITE]);

        $this->assertStringContainsString('site_ts', (string) ($explain['possible_keys'] ?? ''),
            'the window can be read through site_ts');
    }

    public function testPagesEventsDevicesAndRecentEvents(): void
    {
        $this->event(1, 3, ['device_type' => 'mobile']);
        $this->event(2, 2, ['device_type' => 'desktop', 'page_path' => '/pricing', 'page_title' => 'Pricing']);
        $this->event(2, 1, ['device_type' => 'desktop', 'event_type' => 'click', 'page_path' => '/pricing',
            'page_title' => 'Pricing']);

        $s = $this->realtime()->summary();

        $this->assertSame([
            ['path' => '/home', 'title' => 'Home', 'views' => 1, 'users' => 1],
            ['path' => '/pricing', 'title' => 'Pricing', 'views' => 1, 'users' => 1],
        ], $s['pages']);

        $this->assertSame([['name' => 'page_view', 'count' => 2], ['name' => 'click', 'count' => 1]], $s['events']);
        $this->assertSame([['type' => 'desktop', 'users' => 1], ['type' => 'mobile', 'users' => 1]],
            array_values(array_filter($s['devices'], fn ($d) => $d['type'] !== null)));

        $this->assertSame(['click', 'page_view', 'page_view'], array_column($s['recent'], 'type'), 'newest first');
        $this->assertSame((string) (self::VISITOR + 2), $s['recent'][0]['visitor'], 'as text');
    }

    public function testCountriesAndHowManyVisitorsHaveNoLocation(): void
    {
        $this->event(1, 1, ['country_code' => 'US', 'country' => 'united states']);
        $this->event(2, 1, ['country_code' => 'US', 'country' => 'united states']);
        $this->event(3, 1, ['country_code' => 'FR', 'country' => 'france']);
        $this->event(4, 1);

        $s = $this->realtime()->summary();

        $this->assertSame([
            ['code' => 'US', 'name' => 'united states', 'users' => 2],
            ['code' => 'FR', 'name' => 'france', 'users' => 1],
        ], $s['countries']);
        $this->assertSame(3, $s['located']);
        $this->assertSame(4, $s['activeUsers']['last30']);
    }

    /**
     * First-user acquisition as collected: the tag, else the referring host,
     * else (direct). Unknown -- no acquisition captured, whether or not a row
     * exists -- is NULL, as the build treats it (acq_ts, not the row).
     */
    public function testFirstUserSourceIsTheStoresAcquisitionAsCollected(): void
    {
        $this->store(1, ['acq_source' => 'newsletter', 'acq_medium' => 'email', 'acq_campaign' => 'autumn', 'acq_ts' => 1]);
        $this->store(2, ['acq_referer_host' => 'search.example', 'acq_ts' => 1]);
        $this->store(3, ['acq_ts' => 1]);
        // A row a user property made, with no acquisition behind it.
        $this->store(4, ['properties' => '{"plan":{"v":"pro","ts":1}}']);

        foreach ([1, 2, 3, 4, 5] as $v) {
            $this->event($v, 1);
        }

        $by = [];

        foreach ($this->realtime()->summary()['sources'] as $row) {
            $by[var_export($row['source'], true)] = $row;
        }

        $this->assertSame(['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'autumn', 'users' => 1],
            $by["'newsletter'"]);
        $this->assertSame(1, $by["'search.example'"]['users']);
        $this->assertSame(1, $by["'(direct)'"]['users']);
        $this->assertSame(2, $by['NULL']['users'], 'the property-only row and the visitor with none');
    }

    public function testGoalCompletionsAreBrokenDownByGoalWhereRawCanSay(): void
    {
        $this->goals[] = $this->goal('Pricing visit', [['page_path', 'begins', '/pricing']]);
        // A goal naming a column only the build derives does not compile:
        // counted in the total only.
        $this->goals[] = $this->goal('Organic arrival', [['medium', 'equals', 'organic']]);

        $this->event(1, 2, ['page_path' => '/pricing', 'is_goal_event' => 1]);
        $this->event(2, 1, ['page_path' => '/pricing/team', 'is_goal_event' => 1]);
        $this->event(3, 1, ['page_path' => '/home', 'is_goal_event' => 1]);
        $this->event(4, 1, ['page_path' => '/pricing']);

        $before = owa_coreAPI::dbSingleton()->lastQueryError();
        $goals  = $this->realtime()->summary()['goals'];

        $this->assertSame($before, owa_coreAPI::dbSingleton()->lastQueryError(),
            'a goal raw cannot express is skipped, not sent to the server to be refused');
        $this->assertSame(3, $goals['total']);
        $this->assertSame([['name' => 'Pricing visit', 'count' => 2]], $goals['byGoal']);
    }

    /** One visitor's window, newest first. */
    public function testOneVisitorsEvents(): void
    {
        $this->event(1, 5, ['page_path' => '/a']);
        $this->event(1, 2, ['page_path' => '/b']);
        $this->event(2, 1, ['page_path' => '/c']);
        $this->event(1, 40, ['page_path' => '/old']);

        $events = $this->realtime()->visitor((string) (self::VISITOR + 1));

        $this->assertSame(['/b', '/a'], array_column($events, 'path'));
        $this->assertSame([], $this->realtime()->visitor('1 OR 1=1'), 'only a number is a visitor id');
    }

    private function goal(string $name, array $conditions): string
    {
        $entity = owa_coreAPI::entityFactory('base.goal_event');
        $id     = $entity->generateId('goal_event:realtime:' . uniqid('', true));

        $entity->setProperties(['id' => $id, 'property_id' => self::PROPERTY, 'name' => $name,
            'trigger_event_type' => 'page_view', 'is_active' => 1,
            'creation_date' => \OWA\Core\CoreAPI::getRequestTimestamp()]);
        $this->assertTrue((bool) $entity->create());

        foreach ($conditions as $n => $c) {
            $row = owa_coreAPI::entityFactory('base.goal_event_condition');
            $row->setProperties(['id' => $row->generateId('goal_event_condition:' . $id . ':' . $n),
                'goal_event_id' => $id, 'sort_order' => $n + 1, 'condition_property' => $c[0],
                'condition_operator' => $c[1], 'condition_value' => $c[2],
                'creation_date' => \OWA\Core\CoreAPI::getRequestTimestamp()]);
            $this->assertTrue((bool) $row->create());
        }

        return $id;
    }
}
