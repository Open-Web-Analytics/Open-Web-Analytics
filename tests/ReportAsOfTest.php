<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Status;

/**
 * "Data as of" on a report.
 *
 * A report reads the cube, which moves only when a build runs, so its numbers
 * are as of the newest build among the rows it read: MAX(built_at), carried on
 * the aggregate query already being run. Not MIN -- settled partitions are not
 * rebuilt because they are complete, so their older build times say nothing.
 */
final class ReportAsOfTest extends TestCase
{
    const PROPERTY = 7784000000000001;
    const SITE     = 'report-as-of-fixture';

    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'As-of fixture',
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
            'name'        => 'As-of fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        \OWA\Core\CoreAPI::dbSingleton()->extendPartitionsBack(Cubes::tableFor(self::PROPERTY), self::day(10));

        foreach ([self::day(3), self::day(2)] as $day) {
            self::seedRaw($day);
        }

        self::build(self::day(3), self::day(2));

        // Two partitions, two build times: the older day built longer ago.
        $table = Cubes::tableFor(self::PROPERTY);
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %s SET built_at = %d WHERE yyyymmdd = %d',
            $table, 1790000000 * 1000000, self::day(3)));
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %s SET built_at = %d WHERE yyyymmdd = %d',
            $table, 1790100000 * 1000000, self::day(2)));

        Cubes::forgetExistence();
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
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

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }

        Cubes::forgetExistence();
    }

    private static function day(int $ago): int
    {
        return (int) date('Ymd', strtotime("-$ago days"));
    }

    private static function seedRaw(int $yyyymmdd): void
    {
        $n  = ++self::$seq;
        $ts = strtotime((string) $yyyymmdd . ' 12:00:00') * 1000000 + $n;

        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, 1, 8896000000000000 + $n, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => 1,
            'session_id'    => 8896000000000000 + $n,
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

    private static function build(int $from, int $to): void
    {
        $class = new ReflectionClass(\OWA\Module\Base\Controller\CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();
        $p     = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, ['property' => (string) self::PROPERTY, 'from' => (string) $from, 'to' => (string) $to]);
        $cli->action();
    }

    private function results(string $start, string $end)
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();
        $rsm->metrics = $rsm->metricsStringToArray('pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray('date'));
        $rsm->setSiteId(self::SITE);
        $rsm->setTimePeriod('date_range', $start, $end);

        return $rsm->getResults();
    }

    private function requireDatabase(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads a cube.');
        }
    }

    /** The newest build among the rows read -- MAX, not MIN -- in seconds. */
    public function testAsOfIsTheNewestBuildAmongTheRowsRead(): void
    {
        $this->requireDatabase();

        $rs = $this->results((string) self::day(5), (string) self::day(0));

        $this->assertSame(1790100000, $rs->asOf);
        $this->assertArrayNotHasKey('owa_as_of', (array) $rs->aggregates,
            'the stamp rides the aggregate query but is not a metric');
        $this->assertSame(2, (int) ($rs->aggregates['pageViews']['value'] ?? $rs->aggregates['pageViews'] ?? 0),
            'and the aggregate beside it is untouched');
    }

    /** Only the rows the period reads count. */
    public function testAsOfFollowsThePeriod(): void
    {
        $this->requireDatabase();

        $rs = $this->results((string) self::day(3), (string) self::day(3));

        $this->assertSame(1790000000, $rs->asOf);
    }

    /** A period with no rows has no build to be as of. */
    public function testAPeriodWithNoRowsHasNoAsOf(): void
    {
        $this->requireDatabase();

        $rs = $this->results((string) self::day(9), (string) self::day(8));

        $this->assertNull($rs->asOf);
    }

    /**
     * The warning is the cube's status, not the stamp: a catch-up that stopped
     * leaves the partitions before the failure freshly built, so a recent
     * stamp can sit over a missing day.
     */
    public function testTheWarningComesFromTheCubesStatus(): void
    {
        $table = 'owa_event_' . self::PROPERTY;

        $this->assertSame('the scheduled build is not running',
            Status::asOfWarningFor(true, 'ok', '', $table));
        $this->assertSame("the last build stopped on this Property's cube",
            Status::asOfWarningFor(false, 'failed', "$table stopped at p20260923: EXCHANGE PARTITION failed.", $table));
        $this->assertSame('', Status::asOfWarningFor(false, 'failed', 'owa_event_1 stopped at p20260923: x', $table),
            'another cube stopping is not this one\'s problem');
        $this->assertSame('', Status::asOfWarningFor(false, 'ok', '', $table));
    }

    /** Shown for a period reaching yesterday or today, on a ready report that reads the cube. */
    public function testTheLineIsShownOnlyWhileAPeriodIsStillBeingBuilt(): void
    {
        $today     = (int) date('Ymd');
        $yesterday = self::day(1);

        $this->assertTrue(\OWA\Core\ReportController::showsAsOf(true, true, $today));
        $this->assertTrue(\OWA\Core\ReportController::showsAsOf(true, true, $yesterday));
        $this->assertFalse(\OWA\Core\ReportController::showsAsOf(true, true, self::day(2)),
            'a closed period reads settled partitions');
        $this->assertFalse(\OWA\Core\ReportController::showsAsOf(false, true, $today),
            'a screen that reads no cube data');
        $this->assertFalse(\OWA\Core\ReportController::showsAsOf(true, false, $today),
            'a report that is not ready shows the notice instead');
    }
}
