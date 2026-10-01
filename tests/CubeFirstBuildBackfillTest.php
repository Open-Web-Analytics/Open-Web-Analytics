<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Controller\CubeRebuildCli;

/**
 * A Property's first build reads back to its first day in raw.
 *
 * Only a scheduled build creates a cube, and a routine build covers yesterday
 * and today. So everything a Property collected before its cube existed --
 * typically before cron was installed -- would never reach the cube unless the
 * build that CREATES the cube reads from the first day raw holds.
 *
 * And a Property whose only rows are older than a build's window has to be
 * picked up at all, or it would get no cube until new traffic happened to land
 * inside one.
 */
final class CubeFirstBuildBackfillTest extends TestCase
{
    /** Collected 40 days ago and today; no cube until a build makes one. */
    const PROPERTY = 7780000000000001;
    const SITE     = 'cube-backfill-fixture';

    /** Has a cube already: a routine build, not a first one. */
    const CUBED_PROPERTY = 7780000000000002;
    const CUBED_SITE     = 'cube-backfill-cubed';

    /** Collected 40 days ago only: nothing inside a routine build's window. */
    const OLD_ONLY_PROPERTY = 7780000000000004;
    const OLD_ONLY_SITE     = 'cube-backfill-old-only';

    /** Has never collected anything. */
    const EMPTY_PROPERTY = 7780000000000003;
    const EMPTY_SITE     = 'cube-backfill-empty';

    const VISITOR = 8890000000000001;

    private static int $old_day;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::$old_day = (int) date('Ymd', strtotime('-40 days'));

        self::dropFixture();

        foreach ([
            self::PROPERTY       => self::SITE,
            self::CUBED_PROPERTY => self::CUBED_SITE,
            self::EMPTY_PROPERTY => self::EMPTY_SITE,
            self::OLD_ONLY_PROPERTY => self::OLD_ONLY_SITE,
        ] as $property_id => $site_id) {

            $property = \OWA\Core\CoreAPI::entityFactory('base.property');
            $property->setProperties([
                'id'            => $property_id,
                'name'          => 'Cube backfill fixture',
                'domain'        => 'example.test',
                'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
                'creation_date' => time(),
            ]);

            if (!$property->create()) {
                throw new \RuntimeException('seeding owa_property failed');
            }

            $site = \OWA\Core\CoreAPI::entityFactory('base.site');
            $site->setProperties([
                'id'          => $property_id * 10,
                'site_id'     => $site_id,
                'property_id' => $property_id,
                'name'        => 'Cube backfill fixture profile',
                'domain'      => 'example.test',
            ]);

            if (!$site->create()) {
                throw new \RuntimeException('seeding owa_site failed');
            }
        }

        self::seedRaw(self::SITE, self::$old_day, 1);
        self::seedRaw(self::SITE, (int) date('Ymd'), 2);
        self::seedRaw(self::CUBED_SITE, self::$old_day, 3);
        self::seedRaw(self::OLD_ONLY_SITE, self::$old_day, 5);

        if (!Cubes::create(self::CUBED_PROPERTY)) {
            throw new \RuntimeException('creating the cubed Property\'s cube failed');
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
    }

    private static function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::SITE, self::CUBED_SITE, self::EMPTY_SITE, self::OLD_ONLY_SITE] as $site) {
            foreach (['base.event_raw', 'base.visitor_acquisition', 'base.site'] as $entity) {
                $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                    \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), $db->prepare($site)));
            }
        }

        foreach ([self::PROPERTY, self::CUBED_PROPERTY, self::EMPTY_PROPERTY,
                  self::OLD_ONLY_PROPERTY] as $property_id) {
            $db->query(sprintf('DELETE FROM %s WHERE id = %d',
                \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), $property_id));

            $cube = Cubes::tableFor($property_id);

            foreach (['', '_rebuild', '_computed'] as $suffix) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $cube, $suffix));
            }
        }
    }

    /** One page view on a day, in its own session. */
    private static function seedRaw(string $site, int $yyyymmdd, int $n): void
    {
        $ts      = strtotime((string) $yyyymmdd . ' 12:00:00') * 1000000 + $n;
        $session = 8891000000000000 + $n;

        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(
                                   $site, self::VISITOR, $session, $ts, 'page_view'),
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

    private function cli(array $params): CubeRebuildCli
    {
        $class = new ReflectionClass(CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $p = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, $params);

        return $cli;
    }

    private function cubeRowsOn(int $property_id, int $yyyymmdd): int
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf(
            'SELECT COUNT(*) AS n FROM %s WHERE yyyymmdd = %d',
            Cubes::tableFor($property_id), $yyyymmdd));

        return (int) ($row['n'] ?? 0);
    }

    public function testTheEarliestDayIsTheFirstRawRowOfThePropertysProfiles(): void
    {
        $this->assertSame(self::$old_day, Cubes::earliestDay(self::PROPERTY));
        $this->assertNull(Cubes::earliestDay(self::EMPTY_PROPERTY), 'no rows, no day');
        $this->assertNull(Cubes::earliestDay('not an id'));
    }

    /**
     * A Property with rows only outside a build's window, and no cube, is still
     * picked up; one with a cube, or with nothing collected, is not.
     */
    public function testAPropertyThatCollectedButHasNoCubeAwaitsItsFirstBuild(): void
    {
        $awaiting = Cubes::awaitingFirstBuild();

        $this->assertContains((string) self::PROPERTY, $awaiting);
        $this->assertContains((string) self::OLD_ONLY_PROPERTY, $awaiting);
        $this->assertNotContains((string) self::CUBED_PROPERTY, $awaiting, 'it has a cube');
        $this->assertNotContains((string) self::EMPTY_PROPERTY, $awaiting, 'it has collected nothing');
    }

    /**
     * The scheduled run -- no property= -- includes a Property whose only rows
     * are older than its window, which the in-range check alone would miss.
     */
    public function testAScheduledRunIncludesAPropertyWhoseRowsAreAllOlder(): void
    {
        $cli = $this->cli([]);

        $method = new ReflectionMethod(CubeRebuildCli::class, 'resolveProperties');
        $method->setAccessible(true);

        $range = [
            'from' => (int) date('Ymd', strtotime('-1 day')),
            'to'   => (int) date('Ymd'),
        ];

        $this->assertNotContains((string) self::OLD_ONLY_PROPERTY,
            Cubes::collecting($range['from'], $range['to'])['properties'],
            'the fixture is chosen because nothing of it is in the window');

        $properties = $method->invoke($cli, $range);

        $this->assertContains((string) self::OLD_ONLY_PROPERTY, $properties);
        $this->assertNotContains((string) self::EMPTY_PROPERTY, $properties);
    }

    /**
     * THE BUILD THAT CREATES A CUBE READS BACK TO THE FIRST DAY, and gives the
     * cube dated partitions reaching there. A routine build afterwards does
     * not: it covers yesterday and today, as before.
     */
    public function testTheFirstBuildBackfillsAndARoutineBuildDoesNot(): void
    {
        $table = Cubes::tableFor(self::PROPERTY);
        $db    = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertFalse($db->tableExists($table), 'the fixture starts with no cube');

        $cli = $this->cli(['property' => (string) self::PROPERTY]);
        $cli->action();

        $this->assertTrue($db->tableExists($table), 'the build created the cube');
        $this->assertSame(1, $this->cubeRowsOn(self::PROPERTY, self::$old_day),
            'the day collected before the cube existed is in it');
        $this->assertSame(1, $this->cubeRowsOn(self::PROPERTY, (int) date('Ymd')));

        $first = $db->getPartitionSpans($table)[0];
        $this->assertLessThanOrEqual(self::$old_day, (int) $first['start'],
            'the cube has a dated partition covering the first day');

        // A row arriving late on the old day: only a build that reads back
        // would pick it up, and a routine one must not.
        self::seedRaw(self::SITE, self::$old_day, 4);

        $cli = $this->cli(['property' => (string) self::PROPERTY]);
        $cli->action();

        $this->assertSame(1, $this->cubeRowsOn(self::PROPERTY, self::$old_day),
            'a routine build covers yesterday and today only');
    }
}
