<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Controller\CubeRebuildCli;

/**
 * A routine run rebuilds today's partition once it is due: NEXT_BUILD_FACTOR
 * times its last build's duration after that build began, and no sooner than
 * NEXT_BUILD_FLOOR (Builder::nextBuildDue()).
 *
 * The rule's tests need no database. The run's tests build a cube against the
 * configured database, and set built_at and the build record by hand, because
 * both are stamped with the current time by the code under test.
 */
final class CubeTodaySpacingTest extends TestCase
{
    const PROPERTY = 7788000000000001;
    const SITE     = 'cube-spacing-fixture';
    const VISITOR  = 8888000000000001;

    const SECOND = 1000000;

    private static int $seq = 0;

    private static bool $seeded = false;

    public function testTheFloorAndTheFactor(): void
    {
        $this->assertSame(300, Builder::NEXT_BUILD_FLOOR);
        $this->assertSame(10, Builder::NEXT_BUILD_FACTOR);

        $b = 1795000000000000;

        $this->assertSame($b + 300 * self::SECOND, Builder::dueAt($b, null), 'an unknown duration waits the floor');
        $this->assertSame($b + 300 * self::SECOND, Builder::dueAt($b, 0));
        $this->assertSame($b + 300 * self::SECOND, Builder::dueAt($b, 30000), 'ten times 30s is the floor');
        $this->assertSame($b + 300010000, Builder::dueAt($b, 30001));
        $this->assertSame($b + 600 * self::SECOND, Builder::dueAt($b, 60000), 'a minute\'s build waits ten');
        $this->assertSame($b + 300 * self::SECOND, Builder::dueAt($b, -5), 'a negative duration is none');
    }

    public function testWhenARunIsDue(): void
    {
        $due = 1795000000000000;

        $this->assertTrue(Builder::isDue(null, $due), 'never built');
        $this->assertTrue(Builder::isDue($due, $due + 1));
        $this->assertTrue(Builder::isDue($due, $due));
        $this->assertTrue(Builder::isDue($due, $due - Builder::NEXT_BUILD_EARLY * self::SECOND));
        $this->assertFalse(Builder::isDue($due, $due - Builder::NEXT_BUILD_EARLY * self::SECOND - 1));
    }

    /**
     * A quick build is rebuilt by every five-minute run. Its build began some
     * seconds into its run, so the next run starts less than five minutes
     * after it.
     */
    public function testTheFloorDoesNotSkipARun(): void
    {
        $run = 1795000000000000;

        $built_at = $run + 8 * self::SECOND;

        $this->assertTrue(Builder::isDue(Builder::dueAt($built_at, 2000), $run + 300 * self::SECOND));
    }

    /** A build of a minute and a half skips the next two runs and is rebuilt by the third. */
    public function testALongBuildSkipsRuns(): void
    {
        $run      = 1795000000000000;
        $built_at = $run + 2 * self::SECOND;
        $due      = Builder::dueAt($built_at, 90000);

        $this->assertFalse(Builder::isDue($due, $run + 300 * self::SECOND));
        $this->assertFalse(Builder::isDue($due, $run + 600 * self::SECOND));
        $this->assertTrue(Builder::isDue($due, $run + 900 * self::SECOND));
    }

    public function testWhichPartitionHoldsToday(): void
    {
        $this->assertTrue(Builder::holdsToday(['start' => 20261119, 'less_than' => 20261120], 20261119));
        $this->assertFalse(Builder::holdsToday(['start' => 20261118, 'less_than' => 20261119], 20261119), 'yesterday');
        $this->assertFalse(Builder::holdsToday(['start' => 20261120, 'less_than' => 20261121], 20261119));
        $this->assertTrue(Builder::holdsToday(['start' => 20261101, 'less_than' => 20261201], 20261119), 'a month');
    }

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Cube spacing fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Cube spacing fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        self::$seeded = true;
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available() || !self::$seeded) {
            return;
        }

        self::dropFixture();
    }

    private static function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), self::SITE));
        }

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        \OWA\Core\CoreAPI::clearScopedSetting('property', (string) self::PROPERTY, 'base', Builder::LAST_BUILD_SETTING);

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this builds a cube.');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE));
        $db->query(sprintf('DELETE FROM %s', Cubes::tableFor(self::PROPERTY)));
    }

    private function today(): int
    {
        return (int) date('Ymd');
    }

    private function span(): array
    {
        return (new Builder(self::PROPERTY))->partitions($this->today(), $this->today())[0];
    }

    /** One page view today, written the way ingest writes it: it arrives now. */
    private function seedRaw(): void
    {
        $n       = ++self::$seq;
        $ts      = (time() - 7200) * self::SECOND + $n;
        $session = 8888100000000000 + $n;

        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR, $session, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => self::VISITOR,
            'session_id'    => $session,
            'ts'            => $ts,
            'yyyymmdd'      => $this->today(),
            'page_location' => 'https://example.test/',
            'page_path'     => '/',
            'is_goal_event' => 0,
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');
    }

    private function build(array $params): void
    {
        $class = new ReflectionClass(CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();
        $class->getProperty('params')->setValue($cli, $params + ['property' => (string) self::PROPERTY]);
        $cli->action();
    }

    private function builtAt(): ?int
    {
        return (new Builder(self::PROPERTY))->builtAt($this->span());
    }

    private function record(): mixed
    {
        \OWA\Core\CoreAPI::settingCacheFlush();

        return \OWA\Core\CoreAPI::getScopedSettingRow('property', (string) self::PROPERTY, 'base', Builder::LAST_BUILD_SETTING);
    }

    /** Pretend today's partition was last built at $usec, taking $msec. */
    private function builtAgo(int $seconds, int $msec): int
    {
        $usec = (int) round(microtime(true) * self::SECOND) - $seconds * self::SECOND;

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %s SET built_at = %d WHERE yyyymmdd = %d',
            Cubes::tableFor(self::PROPERTY), $usec, $this->today()));

        \OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', Builder::LAST_BUILD_SETTING,
            ['partition' => $this->span()['name'], 'built_at' => $usec, 'msec' => $msec]);

        return $usec;
    }

    /**
     * Needs a scratch-DB run.
     *
     * @covers-setting base.cube_last_build
     */
    public function testABuildRecordsHowLongItTook(): void
    {
        $this->requireDb();
        $this->seedRaw();

        $before = (int) round(microtime(true) * self::SECOND);
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);
        $after  = (int) round(microtime(true) * self::SECOND);

        $record = $this->record();

        $this->assertIsArray($record);
        $this->assertSame($this->span()['name'], $record['partition']);
        $this->assertSame($this->builtAt(), $record['built_at']);
        $this->assertGreaterThanOrEqual(0, $record['msec']);
        $this->assertLessThanOrEqual(intdiv($after - $before, 1000) + 1, $record['msec']);

        $builder = new Builder(self::PROPERTY);
        $this->assertSame(Builder::dueAt($this->builtAt(), $record['msec']), $builder->nextBuildDue($this->span()));

        $days = \OWA\Module\Base\Classes\Cube\Status::recentDays((string) self::PROPERTY, Cubes::tableFor(self::PROPERTY));

        $this->assertSame($builder->nextBuildDue($this->span()), $days[0]['next_build'], 'the status screen says when');
        $this->assertNull($days[1]['next_build'], 'and only for today');
    }

    /**
     * Today is rebuilt by a routine run once due, and not before; a named
     * range is built as named. Needs a scratch-DB run.
     *
     * @covers-setting base.cube_last_build
     */
    public function testARoutineRunRebuildsTodayOnceItIsDue(): void
    {
        $this->requireDb();
        $this->seedRaw();
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);

        $built = $this->builtAt();
        $this->seedRaw();
        $this->build([]);
        $this->assertSame($built, $this->builtAt(), 'built a moment ago: not due');

        $built = $this->builtAgo(400, 1000);
        $this->build([]);
        $this->assertNotSame($built, $this->builtAt(), 'past the floor: rebuilt');

        $this->seedRaw();
        $built = $this->builtAgo(400, 60000);
        $this->build([]);
        $this->assertSame($built, $this->builtAt(), 'a minute\'s build is due ten minutes after it began');

        $built = $this->builtAgo(601, 60000);
        $this->build([]);
        $this->assertNotSame($built, $this->builtAt(), 'and is rebuilt then');

        $this->seedRaw();
        $built = $this->builtAt();
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);
        $this->assertNotSame($built, $this->builtAt(), 'a named range is not spaced');
    }

    /**
     * A record of another build leaves the duration unknown: the floor.
     * Needs a scratch-DB run.
     *
     * @covers-setting base.cube_last_build
     */
    public function testARecordOfAnotherBuildIsTheFloor(): void
    {
        $this->requireDb();
        $this->seedRaw();
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);

        $built = $this->builtAt();

        \OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', Builder::LAST_BUILD_SETTING,
            ['partition' => 'p19700101', 'built_at' => $built, 'msec' => 3600000]);

        $this->assertSame(Builder::dueAt($built, null), (new Builder(self::PROPERTY))->nextBuildDue($this->span()));
    }
}
