<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Retention;
use OWA\Module\Base\Controller\CubeRebuildCli;

/**
 * A cube's retention window, against a real cube.
 *
 * A first build stops at the window; lengthening the window owes the cube the
 * months between, which a queued rebuild fills from raw; and a rebuild never
 * empties a cube month that raw no longer holds.
 */
final class RetentionCubeTest extends TestCase
{
    const PROPERTY = 7781000000000001;
    const SITE     = 'retention-cube-fixture';
    const VISITOR  = 8892000000000001;

    private static int $old;
    private static int $recent;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        // Far enough apart that a two-month window holds one and not the other.
        self::$old    = (int) date('Ymd', strtotime('-400 days'));
        self::$recent = (int) date('Ymd', strtotime('-20 days'));

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id' => self::PROPERTY, 'name' => 'Retention fixture', 'domain' => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB, 'creation_date' => time(),
        ]);
        $property->create();

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties([
            'id' => self::PROPERTY * 10, 'site_id' => self::SITE, 'property_id' => self::PROPERTY,
            'name' => 'Retention fixture profile', 'domain' => 'example.test',
        ]);
        $site->create();

        self::seedRaw(self::$old, 1);
        self::seedRaw(self::$recent, 2);
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
            $this->markTestSkipped('builds a cube');
        }

        self::dropCube();
        \OWA\Core\CoreAPI::clearScopedSetting('property', (string) self::PROPERTY, 'base', Retention::CUBE);
        self::forgetJobs();
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

        \OWA\Core\CoreAPI::clearScopedSetting('property', (string) self::PROPERTY, 'base', Retention::CUBE);
        self::dropCube();
        self::forgetJobs();
    }

    private static function dropCube(): void
    {
        foreach (['', '_rebuild', '_computed'] as $suffix) {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }

        Cubes::forgetExistence();
    }

    private static function forgetJobs(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE dedupe_key = 'cube-backfill:%s'",
            \OWA\Core\CoreAPI::entityFactory('base.job_queue')->getTableName(), self::PROPERTY));
    }

    private static function seedRaw(int $yyyymmdd, int $n): void
    {
        $ts      = strtotime((string) $yyyymmdd . ' 12:00:00') * 1000000 + $n;
        $session = 8893000000000000 + $n;

        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id' => \OWA\Module\Base\Classes\V2Event::id(self::SITE, self::VISITOR, $session, $ts, 'page_view'),
            'event_type' => 'page_view', 'site_id' => self::SITE, 'visitor_id' => self::VISITOR,
            'session_id' => $session, 'ts' => $ts, 'yyyymmdd' => $yyyymmdd,
            'page_location' => 'https://example.test/', 'page_path' => '/', 'is_goal_event' => 0,
        ]);

        if (!$entity->create()) {
            throw new \RuntimeException('seeding owa_event_raw failed');
        }
    }

    private function cli(array $params, string $class = CubeRebuildCli::class): CubeRebuildCli
    {
        $ref = new ReflectionClass($class);
        $cli = $ref->newInstanceWithoutConstructor();

        $p = new ReflectionProperty(CubeRebuildCli::class, 'params');
        $p->setAccessible(true);
        $p->setValue($cli, $params);

        return $cli;
    }

    private function rowsOn(int $yyyymmdd): int
    {
        return (int) (\OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf('SELECT COUNT(*) AS n FROM %s WHERE yyyymmdd = %d',
            Cubes::tableFor(self::PROPERTY), $yyyymmdd))['n'] ?? 0);
    }

    private function windowOf(int $months): void
    {
        \OWA\Core\CoreAPI::setScopedSetting('property', (string) self::PROPERTY, 'base', Retention::CUBE, $months);
    }

    /** A new cube is built back to its window, not to raw's first day. */
    public function testAFirstBuildStopsAtTheCubesWindow(): void
    {
        $this->windowOf(2);

        $this->cli(['property' => (string) self::PROPERTY])->action();

        $this->assertSame(1, $this->rowsOn(self::$recent), 'inside the window');
        $this->assertSame(0, $this->rowsOn(self::$old), 'outside it: the next rotate would only drop it');
    }

    /**
     * Lengthening the window owes the cube the months between; the rebuild it
     * queues fills them from raw, and then nothing more is owed.
     */
    public function testALongerWindowQueuesTheRebuildThatFillsIt(): void
    {
        $this->windowOf(2);
        $this->cli(['property' => (string) self::PROPERTY])->action();

        $this->assertNull(Retention::backfillFor((string) self::PROPERTY, Cubes::tableFor(self::PROPERTY), 2),
            'a cube that covers its window is owed nothing');

        $owed = Retention::backfillFor((string) self::PROPERTY, Cubes::tableFor(self::PROPERTY), 0);

        $this->assertNotNull($owed);
        $this->assertSame(self::$old, $owed['from'], 'back to the first day raw holds');
        $this->assertLessThan(self::$recent, $owed['to']);
        $this->assertSame(1, $owed['events']);

        $this->assertSame(1, Retention::enqueueBackfills([$owed]));
        $this->assertTrue(\OWA\Module\Base\Classes\JobQueue::isQueued('cube-rebuild', 'cube-backfill:' . self::PROPERTY));

        // What the queued job runs.
        $this->cli(['property' => (string) self::PROPERTY, 'from' => (string) $owed['from'], 'to' => (string) $owed['to']])->action();

        $this->assertSame(1, $this->rowsOn(self::$old), 'the older month is rebuilt from raw');
        $this->assertNull(Retention::backfillFor((string) self::PROPERTY, Cubes::tableFor(self::PROPERTY), 0));
    }

    /** The Property screen's confirmation names the rebuild and its estimate. */
    public function testLengtheningAsksWithTheRebuildEstimate(): void
    {
        $this->windowOf(2);
        $this->cli(['property' => (string) self::PROPERTY])->action();

        $ask = Retention::preview(['property_id' => (string) self::PROPERTY, 'cube' => 0]);

        $this->assertTrue($ask['needed']);
        $this->assertSame('notice', $ask['tone'], 'a rebuild is not a warning');
        $this->assertSame('Save and rebuild', $ask['proceed']);
        $this->assertStringContainsString('about 1 events', implode(' ', $ask['paragraphs']));
        $this->assertStringContainsString('Estimated rebuild time', implode(' ', $ask['paragraphs']));

        $this->assertFalse(Retention::preview(['property_id' => (string) self::PROPERTY, 'cube' => 2])['needed'],
            'no change, nothing to ask');
    }

    /** Shortening a cube asks too, as a notice: raw keeps what it drops. */
    public function testShorteningACubeAsksAsANotice(): void
    {
        $this->cli(['property' => (string) self::PROPERTY])->action();

        $ask = Retention::preview(['property_id' => (string) self::PROPERTY, 'cube' => 2]);

        $this->assertTrue($ask['needed']);
        $this->assertSame('notice', $ask['tone']);
        $this->assertStringContainsString('event data is kept', implode(' ', $ask['paragraphs']));
    }

    /**
     * A cube month raw no longer holds is kept as built: rebuilding it would
     * replace it with nothing. Raw's coverage is moved past the old day by a
     * subclass, so no partition of the shared test database is dropped.
     */
    public function testARebuildLeavesAMonthRawNoLongerHolds(): void
    {
        $this->cli(['property' => (string) self::PROPERTY])->action();

        $this->assertSame(1, $this->rowsOn(self::$old), 'the fixture: the cube holds the old day');

        // Raw loses the old day, as partition-drop only=raw would leave it. The
        // fixture's own row only: the shared table is not touched beyond it.
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName(), self::SITE, self::$old));

        $class = get_class(new class extends CubeRebuildCli {
            public function __construct() {}
            protected function rawCoversFrom() { return (int) date('Ymd', strtotime('-60 days')); }
        });

        $this->cli(['property' => (string) self::PROPERTY, 'from' => (string) self::$old], $class)->action();

        $this->assertSame(1, $this->rowsOn(self::$old), 'kept as built');
        $this->assertSame(1, $this->rowsOn(self::$recent), 'raw still holds this, so it is rebuilt as usual');

        // Without the guard the same rebuild empties it: the test is the guard.
        $this->cli(['property' => (string) self::PROPERTY, 'from' => (string) self::$old])->action();
        $this->assertSame(0, $this->rowsOn(self::$old), 'unguarded, raw\'s nothing replaces the month');

        self::seedRaw(self::$old, 1);
    }
}
