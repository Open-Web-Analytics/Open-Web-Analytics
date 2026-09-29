<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Status;

/**
 * The Reporting Cubes screen's green, yellow and red.
 *
 * Every level is derived from something that already exists -- partitions and
 * their built_at, raw, the rebuild-cube job's row -- so these assert the
 * mapping from those facts to a level, and that the facts are read from the
 * cube rather than assumed.
 *
 * The scheduler's own state differs by installation (the dev install runs cron,
 * CI's scratch install never has), so the checks that depend on it are asserted
 * through the pure mapping with a job row the test supplies.
 */
final class CubeStatusTest extends TestCase
{
    const PROPERTY = 7782000000000001;
    const SITE     = 'cube-status-fixture';

    /** Has collected, has no cube. */
    const UNCUBED_PROPERTY = 7782000000000002;
    const UNCUBED_SITE     = 'cube-status-uncubed';

    const VISITOR = 8894000000000001;

    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        foreach ([self::PROPERTY => self::SITE, self::UNCUBED_PROPERTY => self::UNCUBED_SITE]
                 as $property_id => $site_id) {

            $property = owa_coreAPI::entityFactory('base.property');
            $property->setProperties([
                'id'            => $property_id,
                'name'          => 'Cube status fixture',
                'domain'        => 'example.test',
                'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
                'creation_date' => time(),
            ]);

            if (!$property->create()) {
                throw new \RuntimeException('seeding owa_property failed');
            }

            $site = owa_coreAPI::entityFactory('base.site');
            $site->setProperties([
                'id'          => $property_id * 10,
                'site_id'     => $site_id,
                'property_id' => $property_id,
                'name'        => 'Cube status fixture profile',
                'domain'      => 'example.test',
            ]);

            if (!$site->create()) {
                throw new \RuntimeException('seeding owa_site failed');
            }
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        owa_coreAPI::dbSingleton()->extendPartitionsBack(Cubes::tableFor(self::PROPERTY), self::day(20));

        self::seedRaw(self::UNCUBED_SITE, self::day(5));
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available() && $this->needsDatabase()) {
            $this->markTestSkipped('OWA database not reachable; this reads a cube.');
        }

        if (owa_test_db_available()) {
            $db = owa_coreAPI::dbSingleton();
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                owa_coreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
            $db->query(sprintf('DELETE FROM %s', Cubes::tableFor(self::PROPERTY)));
        }
    }

    /** The mapping tests need no database; the rest read a real cube. */
    private function needsDatabase(): bool
    {
        return !in_array($this->name(), [
            'testTheLevelIsTheWorstCheck',
            'testALastBuildThatStoppedOnThisCubeIsRed',
            'testTheBuiltCheckIsRedWhenTheBuildIsNotReachingIt',
            'testTheListRendersABadgePerCubeWithItsFirstProblem',
            'testTheDetailFlagsASettledDayThatDiffersFromRaw',
        ], true);
    }

    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        foreach ([self::SITE, self::UNCUBED_SITE] as $site) {
            foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
                $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                    owa_coreAPI::entityFactory($entity)->getTableName(), $site));
            }
        }

        foreach ([self::PROPERTY, self::UNCUBED_PROPERTY] as $property_id) {
            $db->query(sprintf('DELETE FROM %s WHERE id = %d',
                owa_coreAPI::entityFactory('base.property')->getTableName(), $property_id));

            foreach (['', '_rebuild', '_computed'] as $suffix) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor($property_id), $suffix));
            }
        }
    }

    private static function day(int $ago): int
    {
        return (int) date('Ymd', strtotime("-$ago days"));
    }

    private static function seedRaw(string $site, int $yyyymmdd): void
    {
        $n       = ++self::$seq;
        $ts      = strtotime((string) $yyyymmdd . ' 12:00:00') * 1000000 + $n;
        $session = 8895000000000000 + $n;

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id($site, self::VISITOR, $session, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => $site,
            'visitor_id'    => self::VISITOR,
            'session_id'    => $session,
            'ts'            => $ts,
            'yyyymmdd'      => $yyyymmdd,
            'page_location' => 'https://example.test/',
            'page_path'     => '/',
            'is_goal_event' => 0,
        ]);

        if (!$entity->create()) {
            throw new \RuntimeException('seeding owa_event_raw failed');
        }
    }

    private function build(int $from, int $to): void
    {
        $class = new ReflectionClass(\OWA\Module\Base\Controller\CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $p = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, ['property' => (string) self::PROPERTY,
                            'from' => (string) $from, 'to' => (string) $to]);

        $cli->action();
    }

    private function invoke(string $method, array $args)
    {
        $m = new ReflectionMethod(Status::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(null, $args);
    }

    private function checkOf(array $summary, string $key): array
    {
        foreach ($summary['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        $this->fail("no $key check");
    }

    private function render(string $template, array $vars): string
    {
        $t = new \OWA\Core\Template('base');

        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template($template));

        return $t->fetch();
    }

    public function testTheLevelIsTheWorstCheck(): void
    {
        $g = ['level' => Status::GREEN];
        $y = ['level' => Status::YELLOW];
        $r = ['level' => Status::RED];

        $this->assertSame(Status::GREEN, Status::worst([$g, $g]));
        $this->assertSame(Status::YELLOW, Status::worst([$g, $y, $g]));
        $this->assertSame(Status::RED, Status::worst([$y, $r, $g]));
    }

    /**
     * The builder records "<table> stopped at <partition>: <reason>" in the
     * run's message, which the scheduler keeps; the cube it names goes red, and
     * a cube the message does not name is not blamed for another's failure.
     */
    public function testALastBuildThatStoppedOnThisCubeIsRed(): void
    {
        $table = 'owa_event_' . self::PROPERTY;
        $job   = ['row' => [
            'last_run_at'  => time(),
            'last_status'  => 'failed',
            'last_message' => "$table stopped at p20260923: EXCHANGE PARTITION failed. 1 cube(s) stopped; the next run resumes there.",
        ]];

        $mine = $this->invoke('lastBuildCheck', [$job, $table]);

        $this->assertSame(Status::RED, $mine['level']);
        $this->assertStringContainsString('p20260923: EXCHANGE PARTITION failed', $mine['detail']);

        $other = $this->invoke('lastBuildCheck', [$job, 'owa_event_1']);

        $this->assertSame(Status::GREEN, $other['level']);
        $this->assertStringContainsString('another cube', $other['detail']);

        $never = $this->invoke('lastBuildCheck', [['row' => null], $table]);

        $this->assertSame(Status::YELLOW, $never['level']);
    }

    /** Behind is yellow while the next build will reach it, red while it is not. */
    public function testTheBuiltCheckIsRedWhenTheBuildIsNotReachingIt(): void
    {
        $this->assertSame(Status::GREEN, $this->invoke('builtCheck', [null, false])['level']);
        $this->assertSame(Status::YELLOW, $this->invoke('builtCheck', [20260901, false])['level']);
        $this->assertSame(Status::RED, $this->invoke('builtCheck', [20260901, true])['level']);
    }

    /** A freshly created cube has its daily front, a year of lead and an empty catch-all. */
    public function testAHealthyCubesPartitionsAreGreen(): void
    {
        $check = $this->invoke('partitionsCheck', [Cubes::tableFor(self::PROPERTY)]);

        $this->assertSame(Status::GREEN, $check['level'], $check['detail']);
        $this->assertStringContainsString('catch-all empty', $check['detail']);
    }

    /**
     * A ROW IN THE CATCH-ALL IS RED: no build can rebuild it, and it means the
     * dated partitions ran out.
     */
    public function testARowInTheCatchAllIsRed(): void
    {
        $day   = self::day(3);
        $table = Cubes::tableFor(self::PROPERTY);
        $db    = owa_coreAPI::dbSingleton();

        self::seedRaw(self::SITE, $day);
        $this->build($day, $day);

        $db->query("CREATE TEMPORARY TABLE cube_status_probe AS SELECT * FROM $table LIMIT 1");
        $db->query('UPDATE cube_status_probe SET yyyymmdd = 29991231');
        $db->query("INSERT INTO $table SELECT * FROM cube_status_probe");
        $db->query('DROP TEMPORARY TABLE cube_status_probe');

        $facts = Status::partitionFacts($table);

        $this->assertSame(1, $facts['catch_all_rows']);

        $check = $this->invoke('partitionsCheck', [$table]);

        $this->assertSame(Status::RED, $check['level']);
        $this->assertStringContainsString('catch-all', $check['detail']);
    }

    /**
     * BEHIND IS READ OFF THE CUBE: a day last built while it was still open is
     * where the next scheduled build starts, and the summary says so.
     */
    public function testACubeBuiltWhileADayWasOpenIsBehindFromThatDay(): void
    {
        $day   = self::day(6);
        $table = Cubes::tableFor(self::PROPERTY);

        self::seedRaw(self::SITE, $day);
        $this->build($day, $day);

        $span = (new Builder(self::PROPERTY))->partitions($day, $day)[0];

        if ((int) $span['less_than'] > self::day(1)) {
            $this->markTestSkipped("$day shares a partition with yesterday on this date");
        }

        owa_coreAPI::dbSingleton()->query(sprintf(
            'UPDATE %s SET built_at = %d WHERE yyyymmdd = %d',
            $table, strtotime("$day 15:00:00") * 1000000, $day));

        $summary = Status::summary((string) self::PROPERTY);

        $this->assertSame((int) $span['start'], $summary['behind_from']);
        $this->assertNotSame(Status::GREEN, $this->checkOf($summary, 'built')['level']);
    }

    /** The day table reads raw and the cube, day by day. */
    public function testTheDetailCountsRawAndCubeRowsPerDay(): void
    {
        $day = self::day(3);

        self::seedRaw(self::SITE, $day);
        self::seedRaw(self::SITE, $day);
        $this->build($day, $day);

        $days = Status::detail((string) self::PROPERTY)['days'];

        $this->assertCount(Status::DAYS, $days);

        $row = array_values(array_filter($days, fn($d) => $d['day'] === $day))[0];

        $this->assertSame(2, $row['raw']);
        $this->assertSame(2, $row['cube']);
        $this->assertNotNull($row['built_at']);
        $this->assertTrue($row['settled'], 'built today, after that day ended');
    }

    /** A Property that has collected but has no cube is listed, and not green. */
    public function testAPropertyAwaitingItsFirstBuildIsListed(): void
    {
        $ids = array_column(Status::all(), 'property_id');

        $this->assertContains((string) self::UNCUBED_PROPERTY, $ids);

        $summary = Status::summary((string) self::UNCUBED_PROPERTY);

        $this->assertFalse($summary['exists']);
        $this->assertNotSame(Status::GREEN, $summary['level']);
        $this->assertStringContainsString(Status::day(self::day(5)), $this->checkOf($summary, 'cube')['detail']);
    }

    public function testTheListRendersABadgePerCubeWithItsFirstProblem(): void
    {
        $html = $this->render('cube_status.php', ['cubes' => [
            ['property_id' => '1', 'name' => 'Alice site', 'table' => 'owa_event_1', 'level' => 'red',
             'checks' => [['key' => 'a', 'label' => 'Scheduled build', 'level' => 'green', 'detail' => 'fine'],
                          ['key' => 'b', 'label' => 'Partitions', 'level' => 'red', 'detail' => 'Rows in the catch-all.']]],
            ['property_id' => '2', 'name' => 'Bob site', 'table' => 'owa_event_2', 'level' => 'green',
             'checks' => [['key' => 'a', 'label' => 'Scheduled build', 'level' => 'green', 'detail' => 'fine']]],
        ]]);

        $this->assertStringContainsString('owa_cubeStatusRed', $html);
        $this->assertStringContainsString('owa_cubeStatusGreen', $html);
        $this->assertStringContainsString('Action needed', $html, 'the level is a word too, not colour alone');
        $this->assertStringContainsString('Rows in the catch-all.', $html, 'the first problem is the summary');
        $this->assertStringContainsString('Every check passes.', $html);
        $this->assertStringContainsString('base.cubeStatusDetail', $html);
    }

    public function testTheDetailFlagsASettledDayThatDiffersFromRaw(): void
    {
        $cube = [
            'property_id' => '1', 'name' => 'Alice site', 'table' => 'owa_event_1', 'level' => 'yellow',
            'exists' => true, 'behind_from' => null, 'scheduler' => [],
            'checks' => [['key' => 'a', 'label' => 'Built through', 'level' => 'yellow', 'detail' => 'Behind.']],
            'days' => [
                ['day' => 20260920, 'raw' => 5, 'cube' => 3, 'partition' => 'p20260920',
                 'built_at' => 1790000000000000, 'settled' => true],
                ['day' => 20260919, 'raw' => 0, 'cube' => 0, 'partition' => 'p20260919',
                 'built_at' => null, 'settled' => false],
            ],
            'partitions' => ['count' => 70, 'first' => 20260901, 'daily_through' => 20261031,
                             'lead_end' => 20271001, 'today_daily' => true, 'catch_all' => 'pmax',
                             'catch_all_rows' => 0],
            'dimensions' => [],
        ];

        $html = $this->render('cube_status_detail.php', ['cube' => $cube]);

        $this->assertStringContainsString('differs from raw', $html);
        $this->assertStringContainsString('No data', $html, 'an empty day has nothing to settle');
        $this->assertStringContainsString('cmd=cube-rebuild property=1', $html);
    }
}
