<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Controller\CubeRebuildCli;

/**
 * A routine build catches up on partitions an outage, or a failed run, left
 * behind -- with nothing recording how far a build got.
 *
 * A routine run covers yesterday and today. Builder::catchUpFrom() walks back
 * from yesterday: a partition last built before its period ended (unsettled),
 * or empty in the cube while raw has rows, is rebuilt; the first settled one
 * ends the walk. That is sound because a build goes oldest first and stops at
 * its first failure, so what a run leaves built is contiguous.
 *
 * A build stamps built_at with the current time, so "built during an outage"
 * is made by building a partition and then setting its built_at back.
 */
final class CubeCatchUpTest extends TestCase
{
    const PROPERTY = 7781000000000001;
    const SITE     = 'cube-catch-up-fixture';
    const VISITOR  = 8892000000000001;

    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Cube catch-up fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = owa_coreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Cube catch-up fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        // Dated partitions reaching back past every day these tests use.
        owa_coreAPI::dbSingleton()->extendPartitionsBack(
            Cubes::tableFor(self::PROPERTY), self::day(20));
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
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this builds a cube.');
        }

        $db = owa_coreAPI::dbSingleton();

        // Every test starts from an empty cube and no raw rows of ours.
        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            owa_coreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
        $db->query(sprintf('DELETE FROM %s', Cubes::tableFor(self::PROPERTY)));
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

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    private static function day(int $ago): int
    {
        return (int) date('Ymd', strtotime("-$ago days"));
    }

    private static function seedRaw(int $yyyymmdd): void
    {
        $n       = ++self::$seq;
        $ts      = strtotime((string) $yyyymmdd . ' 12:00:00') * 1000000 + $n;
        $session = 8893000000000000 + $n;

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(
                                   self::SITE, self::VISITOR, $session, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
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

    /** The cube partition holding a day. */
    private function spanFor(int $yyyymmdd): array
    {
        $spans = (new Builder(self::PROPERTY))->partitions($yyyymmdd, $yyyymmdd);

        $this->assertCount(1, $spans, "one partition holds $yyyymmdd");

        return $spans[0];
    }

    /** Build exactly one day, as an operator naming a range would. */
    private function buildDay(int $yyyymmdd): CubeRebuildCli
    {
        return $this->runBuild(['property' => (string) self::PROPERTY,
                           'from' => (string) $yyyymmdd, 'to' => (string) $yyyymmdd]);
    }

    /** A routine run: no dates, as the scheduler runs it. */
    private function routine(): CubeRebuildCli
    {
        return $this->runBuild(['property' => (string) self::PROPERTY]);
    }

    private function runBuild(array $params): CubeRebuildCli
    {
        $class = new ReflectionClass(CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $p = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, $params);

        $cli->action();

        return $cli;
    }

    /** Pretend a partition was last built at a given moment. */
    private function setBuiltAt(array $span, int $usec): void
    {
        owa_coreAPI::dbSingleton()->query(sprintf(
            'UPDATE %s SET built_at = %d WHERE yyyymmdd >= %d AND yyyymmdd < %d',
            Cubes::tableFor(self::PROPERTY), $usec, (int) $span['start'], (int) $span['less_than']));
    }

    private function cubeRowsOn(int $yyyymmdd): int
    {
        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT COUNT(*) AS n FROM %s WHERE yyyymmdd = %d',
            Cubes::tableFor(self::PROPERTY), $yyyymmdd));

        return (int) ($row['n'] ?? 0);
    }

    /** Skip when the routine window would build the day anyway, proving nothing. */
    private function requireOutsideTheWindow(int $yyyymmdd): void
    {
        if ((int) $this->spanFor($yyyymmdd)['less_than'] > self::day(1)) {
            $this->markTestSkipped("$yyyymmdd shares a partition with yesterday on this date");
        }
    }

    public function testAPartitionIsSettledOnlyOnceBuiltAfterItsPeriodAndItsSessionsEnded(): void
    {
        $span    = ['name' => 'p', 'start' => '20260901', 'less_than' => '20260902'];
        $ended   = strtotime('20260902') * 1000000;
        $session = ((int) owa_coreAPI::getSetting('base', 'session_length') ?: 1800) * 1000000;
        $builder = new Builder(self::PROPERTY);

        $this->assertFalse($builder->isSettled($span, null), 'never built');
        $this->assertFalse($builder->isSettled($span, $ended - 1), 'built while the day was open');
        $this->assertFalse($builder->isSettled($span, $ended + $session - 1),
            'built after midnight, while its last sessions could still be open');
        $this->assertTrue($builder->isSettled($span, $ended + $session));
    }

    /**
     * THE OUTAGE. Day A was last built during A itself, then nothing ran;
     * day B collected in the meantime and was never built. A routine run
     * starts at A and B reaches the cube.
     */
    public function testARoutineRunCatchesUpAfterAnOutage(): void
    {
        $a = self::day(6);
        $b = self::day(4);

        $this->requireOutsideTheWindow($b);

        self::seedRaw($a);
        $this->buildDay($a);
        $this->setBuiltAt($this->spanFor($a), strtotime("$a 15:00:00") * 1000000);

        self::seedRaw($b);

        $this->assertSame(0, $this->cubeRowsOn($b), 'B has not been built');

        $this->routine();

        $this->assertSame(1, $this->cubeRowsOn($b), 'the catch-up reached B');
        $this->assertTrue((new Builder(self::PROPERTY))->isSettled(
            $this->spanFor($a), (new Builder(self::PROPERTY))->builtAt($this->spanFor($a))),
            'A was rebuilt, and is now settled');
    }

    /**
     * A SETTLED PARTITION ENDS THE WALK. A late row arriving on a settled day
     * is left alone by a routine run -- that is the lateness policy, not this
     * catch-up -- and an operator's from= picks it up.
     */
    public function testASettledPartitionIsNotRebuiltByARoutineRun(): void
    {
        $a = self::day(6);

        $this->requireOutsideTheWindow($a);

        self::seedRaw($a);
        $this->buildDay($a);

        $this->assertTrue((new Builder(self::PROPERTY))->isSettled(
            $this->spanFor($a), (new Builder(self::PROPERTY))->builtAt($this->spanFor($a))),
            'built today, after A ended');

        self::seedRaw($a);
        $this->routine();

        $this->assertSame(1, $this->cubeRowsOn($a), 'the routine run did not reach back');
        $this->assertNull((new Builder(self::PROPERTY))->catchUpFrom(self::day(1)));
    }

    /**
     * The walk ENDS at the first settled partition, even with an unsettled one
     * older than it: a run only leaves a contiguous stretch built, so anything
     * behind a settled partition is not this catch-up's to find.
     */
    public function testTheWalkEndsAtTheFirstSettledPartition(): void
    {
        $old = self::day(8);
        $a   = self::day(6);

        $this->requireOutsideTheWindow($a);

        if ($this->spanFor($old)['name'] === $this->spanFor($a)['name']) {
            $this->markTestSkipped('both days share one partition on this date');
        }

        self::seedRaw($old);
        self::seedRaw($a);
        $this->runBuild(['property' => (string) self::PROPERTY,
                         'from' => (string) $old, 'to' => (string) $a]);
        $this->setBuiltAt($this->spanFor($old), strtotime("$old 15:00:00") * 1000000);

        $this->assertNull((new Builder(self::PROPERTY))->catchUpFrom(self::day(1)),
            'A is settled, so the walk stops there');
    }

    /** A named range is built as named, with no catch-up. */
    public function testANamedRangeDoesNotCatchUp(): void
    {
        $a = self::day(6);
        $b = self::day(4);

        $this->requireOutsideTheWindow($b);

        self::seedRaw($a);
        self::seedRaw($b);

        $this->buildDay(self::day(1));

        $this->assertSame(0, $this->cubeRowsOn($a));
        $this->assertSame(0, $this->cubeRowsOn($b));
    }

    /**
     * A DORMANT CUBE costs nothing: empty in raw and in the cube since its last
     * settled day, so there is nothing to catch up.
     */
    public function testADormantCubeHasNothingToCatchUp(): void
    {
        $a = self::day(15);

        $this->requireOutsideTheWindow($a);

        self::seedRaw($a);
        $this->buildDay($a);

        $this->assertNull((new Builder(self::PROPERTY))->catchUpFrom(self::day(1)));
    }

    /**
     * A RUN STOPS AT ITS FIRST FAILURE, oldest first, and says where.
     *
     * Forced with a NOT NULL column that has no default: the build copies
     * only the columns raw also has, so under STRICT_ALL_TABLES every build
     * statement is refused. That fails the same way on MySQL and MariaDB,
     * where an instant column (1731 at the swap) is MySQL's alone. The later
     * partition keeps the built_at it had, so it is still unsettled for the
     * next run, and the message the scheduler keeps names the cube, the
     * partition and the reason.
     */
    public function testARunStopsAtItsFirstFailureAndSaysWhere(): void
    {
        $a = self::day(6);
        $b = self::day(4);

        $this->requireOutsideTheWindow($b);

        if ($this->spanFor($a)['name'] === $this->spanFor($b)['name']) {
            $this->markTestSkipped('both days share one partition on this date');
        }

        self::seedRaw($a);
        self::seedRaw($b);
        $this->runBuild(['property' => (string) self::PROPERTY,
                    'from' => (string) $a, 'to' => (string) $b]);

        $was = strtotime("$b 15:00:00") * 1000000;
        $this->setBuiltAt($this->spanFor($a), strtotime("$a 15:00:00") * 1000000);
        $this->setBuiltAt($this->spanFor($b), $was);

        $db    = owa_coreAPI::dbSingleton();
        $table = Cubes::tableFor(self::PROPERTY);

        $this->assertStringContainsString('STRICT_ALL_TABLES',
            (string) ($db->get_row('SELECT @@SESSION.sql_mode AS m')['m'] ?? ''),
            'the probe relies on the default sql_mode; an earlier test left it changed');

        $this->assertNotFalse($db->query(sprintf(
            'ALTER TABLE %s ADD COLUMN catch_up_probe INT NOT NULL, ALGORITHM=INPLACE', $table)),
            'adding the probe column: ' . $db->lastQueryError());

        try {
            $cli = $this->routine();

            $outcome = $cli->getCliOutcome();

            $this->assertSame('failed', $outcome['outcome']);
            $this->assertStringStartsWith(
                $table . ' stopped at ' . $this->spanFor($a)['name'] . ': ', $outcome['message']);
            $this->assertLessThanOrEqual(250, strlen($outcome['message']),
                'the scheduler keeps 250 characters');

            $this->assertSame($was, (new Builder(self::PROPERTY))->builtAt($this->spanFor($b)),
                'the partition after the failure was not built');
        } finally {
            $db->query(sprintf('ALTER TABLE %s DROP COLUMN catch_up_probe, ALGORITHM=INPLACE', $table));
        }
    }
}
