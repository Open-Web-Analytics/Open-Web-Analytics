<?php

require_once(__DIR__ . '/CliControllerTestCase.php');
require_once __DIR__ . '/PropertyFixtures.php';

/**
 * The frequent jobs' leases are near twice their budget, not the six-hour
 * default, so a run that died is taken over within minutes. cube-rebuild
 * renews its scheduler lock and its queue claim wherever it renews its cube
 * lock.
 */
final class JobLeaseSizesTest extends CliControllerTestCase
{
    public function testTheDrainsLeaseIsTwiceItsBudget(): void
    {
        $D = \OWA\Module\Base\Controller\DrainTrackerIngestCli::class;

        $this->assertSame(2 * $D::BUDGET, (new $D([]))->getJobLease());
        $this->assertSame(20, (new $D(['seconds' => '10']))->getJobLease());
    }

    public function testTheCubeRebuildsLeaseCoversTwoOfItsTicks(): void
    {
        $C = \OWA\Module\Base\Controller\CubeRebuildCli::class;

        $this->assertSame(600, (new $C([]))->getJobLease());
        $this->assertSame(600, \OWA\Module\Base\Classes\JobQueue::leaseFor('cube-rebuild'));
        $this->assertLessThan(\OWA\Core\Controller\Cli::DEFAULT_JOB_LEASE, $C::JOB_LEASE);
    }

    const PROPERTY = 7789000000000001;
    const SITE     = 'cube-lease-fixture';

    private function dropFixture(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['base.event_raw', 'base.site'] as $entity) {
            $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), self::SITE));
        }

        owa_test_remove_property(self::PROPERTY);

        \OWA\Core\CoreAPI::clearScopedSetting('property', (string) self::PROPERTY, 'base',
            \OWA\Module\Base\Classes\Cube\Builder::LAST_BUILD_SETTING);

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    /** A Property with a cube and one page view today. */
    private function seedFixture(): void
    {
        $this->dropFixture();

        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties(['id' => self::PROPERTY, 'name' => 'Cube lease fixture', 'domain' => owa_test_property_domain(self::PROPERTY),
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB, 'creation_date' => time()]);
        $this->assertTrue((bool) $property->create(), 'seeding owa_property');

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties(['id' => self::PROPERTY * 10, 'site_id' => self::SITE, 'property_id' => self::PROPERTY,
            'name' => 'Cube lease fixture profile', 'domain' => 'example.test']);
        $this->assertTrue((bool) $site->create(), 'seeding owa_site');

        $this->assertTrue(\OWA\Module\Base\Classes\Cube\Cubes::create(self::PROPERTY), 'creating the fixture cube');

        $ts      = (time() - 7200) * 1000000;
        $visitor = 8889000000000001;
        $session = 8889100000000001;

        $event = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $event->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::SITE, $visitor, $session, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::SITE,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'ts'            => $ts,
            'yyyymmdd'      => (int) date('Ymd'),
            'page_location' => 'https://example.test/',
            'page_path'     => '/',
            'is_goal_event' => 0,
        ]);
        $this->assertTrue((bool) $event->create(), 'seeding owa_event_raw');
    }

    /**
     * After each partition it builds: the cube lock, then the job's own
     * lease. Needs a scratch-DB run.
     */
    public function testTheBuildHeartbeatsWhereItRefreshesTheCubeLock(): void
    {
        $this->seedFixture();

        $refreshed = new ArrayObject();

        $lock = new class($refreshed) {
            private $refreshed;
            public function __construct($refreshed) { $this->refreshed = $refreshed; }
            public function refresh($seconds) { $this->refreshed[] = 'cube lock ' . (int) $seconds; return true; }
        };

        $lease = new class('1', 300, $refreshed) extends \OWA\Module\Base\Classes\JobQueueLease {
            private $refreshed;
            public function __construct($id, $seconds, $refreshed) { parent::__construct($id, $seconds); $this->refreshed = $refreshed; }
            public function refresh($seconds) { $this->refreshed[] = 'job lease ' . (int) $seconds; return true; }
        };

        $C   = \OWA\Module\Base\Controller\CubeRebuildCli::class;
        $cli = new $C([]);
        $cli->setJobLease($lease);

        $today = (int) date('Ymd');

        try {
            $outcome = (new ReflectionMethod($cli, 'buildUnderLock'))->invoke($cli, (string) self::PROPERTY,
                \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY), ['from' => $today, 'to' => $today], false, $lock);
        } finally {
            $this->dropFixture();
        }

        $this->assertSame(1, $outcome['built'], (string) $outcome['stopped']);
        $this->assertSame(['cube lock ' . $C::BUILD_LEASE, 'job lease ' . $C::JOB_LEASE], $refreshed->getArrayCopy());
    }

    /** heartbeat() extends a queued job's claim by the command's lease. */
    public function testAHeartbeatExtendsAQueuedClaimToTheCommandsLease(): void
    {
        $lease = new class('1', 300) extends \OWA\Module\Base\Classes\JobQueueLease {
            public $asked = 0;
            public function refresh($seconds) { $this->asked = (int) $seconds; return true; }
        };

        $cli = new \OWA\Module\Base\Controller\CubeRebuildCli([]);
        $cli->setJobLease($lease);

        (new ReflectionMethod($cli, 'heartbeat'))->invoke($cli);

        $this->assertSame(600, $lease->asked);
    }
}
