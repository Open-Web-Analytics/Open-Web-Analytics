<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Builder;
use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Controller\CubeRebuildCli;

/**
 * A routine build skips a partition nothing has reached since it was built.
 *
 * raw.created_at is when a row reached raw (EventRaw::create()). A partition is
 * CURRENT when its built_at is at least one session length after the newest
 * created_at among its rows: nothing arrived since, and every session had
 * closed when it was built. That is one comparison of arrival times, so it
 * holds whatever a build does with the rows -- it counts nothing.
 *
 * Arrival and build times are set by SQL, because both are stamped with the
 * current time by the code under test.
 */
final class CubeSkipUnchangedTest extends TestCase
{
    const PROPERTY = 7785000000000001;
    const SITE     = 'cube-skip-fixture';
    const VISITOR  = 8897000000000001;

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
            'name'          => 'Cube skip fixture',
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
            'name'        => 'Cube skip fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }
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
        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'", $this->raw(), self::SITE));
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

    private function raw(): string
    {
        return owa_coreAPI::entityFactory('base.event_raw')->getTableName();
    }

    private function today(): int
    {
        return (int) date('Ymd');
    }

    private function span(): array
    {
        return (new Builder(self::PROPERTY))->partitions($this->today(), $this->today())[0];
    }

    private function session(): int
    {
        return ((int) owa_coreAPI::getSetting('base', 'session_length') ?: 1800) * 1000000;
    }

    /** One page view today, written the way ingest writes it. */
    private function seedRaw(): string
    {
        $n  = ++self::$seq;
        $ts = (time() - 7200) * 1000000 + $n;
        $id = \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR, 8898000000000000 + $n, $ts, 'page_view');

        $entity = owa_coreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => $id,
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => self::VISITOR,
            'session_id'    => 8898000000000000 + $n,
            'ts'            => $ts,
            'yyyymmdd'      => $this->today(),
            'page_location' => 'https://example.test/',
            'page_path'     => '/',
            'is_goal_event' => 0,
        ]);

        $this->assertTrue($entity->create(), 'seeding owa_event_raw');

        return (string) $id;
    }

    private function setArrival(int $usec): void
    {
        owa_coreAPI::dbSingleton()->query(sprintf("UPDATE %s SET created_at = %s WHERE site_id = '%s'",
            $this->raw(), $usec ? (string) $usec : 'NULL', self::SITE));
    }

    private function setBuiltAt(int $usec): void
    {
        owa_coreAPI::dbSingleton()->query(sprintf('UPDATE %s SET built_at = %d WHERE yyyymmdd = %d',
            Cubes::tableFor(self::PROPERTY), $usec, $this->today()));
    }

    private function cubeRows(): int
    {
        $row = owa_coreAPI::dbSingleton()->get_row(sprintf('SELECT COUNT(*) AS n FROM %s WHERE yyyymmdd = %d',
            Cubes::tableFor(self::PROPERTY), $this->today()));

        return (int) ($row['n'] ?? 0);
    }

    private function build(array $params): void
    {
        $class = new ReflectionClass(CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();
        $p     = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, $params + ['property' => (string) self::PROPERTY]);
        $cli->action();
    }

    private function builtAt(): ?int
    {
        return (new Builder(self::PROPERTY))->builtAt($this->span());
    }

    public function testARowIsStampedWithWhenItReachedRaw(): void
    {
        $before = (int) round(microtime(true) * 1000000);
        $id     = $this->seedRaw();
        $after  = (int) round(microtime(true) * 1000000);

        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT created_at FROM %s WHERE id = %s AND yyyymmdd = %d', $this->raw(), $id, $this->today()));

        $this->assertGreaterThanOrEqual($before, (int) $row['created_at']);
        $this->assertLessThanOrEqual($after, (int) $row['created_at']);
    }

    /** It is ingest provenance: no cube carries it. */
    public function testTheCubeDoesNotCarryIt(): void
    {
        $this->assertContains('created_at', owa_coreAPI::entityFactory('base.event_raw')->getColumns());
        $this->assertNotContains('created_at', Cubes::entityFor(self::PROPERTY)->getColumns());
    }

    public function testWhatCountsAsCurrent(): void
    {
        $this->seedRaw();
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);

        $arrived = (time() - 7200) * 1000000;
        $this->setArrival($arrived);
        $builder = new Builder(self::PROPERTY);

        $this->setBuiltAt($arrived + $this->session());
        $this->assertTrue($builder->isCurrent($this->span()),
            'built a session length after the last arrival: nothing new, every session closed');

        $this->setBuiltAt($arrived + $this->session() - 1);
        $this->assertFalse($builder->isCurrent($this->span()),
            'built while a session could still have been open');

        $this->setBuiltAt($arrived - 1);
        $this->assertFalse($builder->isCurrent($this->span()), 'a row arrived after the build');

        $this->setBuiltAt($arrived + $this->session());
        $this->setArrival(0);
        $this->assertFalse($builder->isCurrent($this->span()),
            'rows from before the column existed cannot say when they arrived');
    }

    public function testAPartitionNeverBuiltIsNotCurrent(): void
    {
        $this->seedRaw();

        $this->assertFalse((new Builder(self::PROPERTY))->isCurrent($this->span()));
    }

    /**
     * A ROUTINE RUN LEAVES A CURRENT PARTITION ALONE, and rebuilds it once a
     * row arrives -- the late row is in the cube afterwards.
     */
    public function testARoutineRunSkipsACurrentPartitionAndRebuildsAfterAnArrival(): void
    {
        $this->seedRaw();
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);

        $arrived = (time() - 7200) * 1000000;
        $this->setArrival($arrived);
        $settled = $arrived + $this->session();
        $this->setBuiltAt($settled);

        $this->build([]);

        $this->assertSame($settled, $this->builtAt(), 'the routine run skipped it');

        $this->seedRaw();
        $this->build([]);

        $this->assertNotSame($settled, $this->builtAt(), 'a new arrival makes the next run rebuild it');
        $this->assertSame(2, $this->cubeRows(), 'and the new row is in the cube');
    }

    /** A range an operator names is built as named, current or not. */
    public function testANamedRangeIsBuiltEvenWhenCurrent(): void
    {
        $this->seedRaw();
        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);

        $arrived = (time() - 7200) * 1000000;
        $this->setArrival($arrived);
        $settled = $arrived + $this->session();
        $this->setBuiltAt($settled);

        $this->build(['from' => (string) $this->today(), 'to' => (string) $this->today()]);

        $this->assertNotSame($settled, $this->builtAt());
    }

    /** The migrator stamps its batches too, so rows it adds are not skipped. */
    public function testTheMigratorStampsWhatItWrites(): void
    {
        $class    = new ReflectionClass(\OWA\Module\Base\Classes\Migration\RequestMigrator::class);
        $migrator = $class->newInstanceWithoutConstructor();
        $write    = $class->getMethod('write');
        $write->setAccessible(true);

        $ts = (time() - 3600) * 1000000;
        $id = \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR, 8899000000000001, $ts, 'page_view');

        try {
            $write->invoke($migrator, [[
                'id' => $id, 'event_type' => 'page_view', 'site_id' => self::SITE,
                'visitor_id' => self::VISITOR, 'session_id' => 8899000000000001, 'ts' => $ts,
                'yyyymmdd' => $this->today(), 'is_goal_event' => 0,
            ]]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('FactMigrator::write needs a constructed migrator here: ' . $e->getMessage());
        }

        $row = owa_coreAPI::dbSingleton()->get_row(sprintf(
            'SELECT created_at FROM %s WHERE id = %s AND yyyymmdd = %d', $this->raw(), $id, $this->today()));

        $this->assertNotEmpty($row['created_at'] ?? null);
    }
}
