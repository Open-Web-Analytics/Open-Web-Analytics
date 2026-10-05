<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;
use OWA\Module\Base\Classes\Cube\Status;

/**
 * "Is reporting ready?" is asked by the controllers, once per request, and
 * nothing queries a cube that does not exist.
 *
 * The other question -- "is the tag set up?" -- is the Tracking Tag screen's,
 * and it is the only one that reads raw.
 */
final class ReportingReadinessTest extends TestCase
{
    /** Has a cube. */
    const READY_PROPERTY = 7783000000000001;
    const READY_SITE     = 'readiness-ready';

    /** Has none, and one raw row. */
    const WAITING_PROPERTY = 7783000000000002;
    const WAITING_SITE     = 'readiness-waiting';

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        foreach ([self::READY_PROPERTY => self::READY_SITE, self::WAITING_PROPERTY => self::WAITING_SITE]
                 as $property_id => $site_id) {

            $property = \OWA\Core\CoreAPI::entityFactory('base.property');
            $property->setProperties([
                'id'            => $property_id,
                'name'          => 'Readiness fixture',
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
                'name'        => 'Readiness fixture profile',
                'domain'      => 'example.test',
            ]);

            if (!$site->create()) {
                throw new \RuntimeException('seeding owa_site failed');
            }
        }

        if (!Cubes::create(self::READY_PROPERTY)) {
            throw new \RuntimeException('creating the ready Property\'s cube failed');
        }

        $ts = strtotime('-2 days 12:00:00') * 1000000;

        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $raw->setProperties([
            'id'            => \OWA\Module\Base\Classes\V2Event::id(self::WAITING_SITE, 1, 1, $ts, 'page_view'),
            'event_type'    => 'page_view',
            'site_id'       => self::WAITING_SITE,
            'visitor_id'    => 1,
            'session_id'    => 1,
            'ts'            => $ts,
            'yyyymmdd'      => (int) date('Ymd', intdiv($ts, 1000000)),
            'page_location' => 'https://example.test/',
            'page_path'     => '/',
            'is_goal_event' => 0,
        ]);

        if (!$raw->create()) {
            throw new \RuntimeException('seeding owa_event_raw failed');
        }
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

        foreach ([self::READY_SITE, self::WAITING_SITE] as $site) {
            foreach (['base.event_raw', 'base.site'] as $entity) {
                $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
                    \OWA\Core\CoreAPI::entityFactory($entity)->getTableName(), $site));
            }
        }

        foreach ([self::READY_PROPERTY, self::WAITING_PROPERTY] as $property_id) {
            $db->query(sprintf('DELETE FROM %s WHERE id = %d',
                \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName(), $property_id));

            foreach (['', '_rebuild', '_computed'] as $suffix) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor($property_id), $suffix));
            }
        }

        Cubes::forgetExistence();
    }

    private function requireDatabase(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }
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

    public function testEachWayOfNotBeingReadySaysWhatToDo(): void
    {
        $none = Status::readinessFor('', false, null);
        $this->assertSame('no_property', $none['state']);

        $down = Status::readinessFor('7', true, null);
        $this->assertSame('scheduler', $down['state']);
        $this->assertSame('Reports need the job scheduler.', $down['headline']);
        $this->assertSame(\OWA\Module\Base\Classes\SchedulerHealth::cronLine(), $down['cron'],
            'the cron entry to add, as the header banner gives it');

        $waiting = Status::readinessFor('7', false, strtotime('2026-09-29 18:46'));
        $this->assertSame('waiting', $waiting['state']);
        $this->assertStringContainsString('next build is due', $waiting['message']);
        $this->assertStringContainsString('Tracking Tag', $waiting['message'],
            'and where to ask whether data is arriving');
    }

    public function testAPropertyWithACubeIsReadyAndOneWithoutIsNot(): void
    {
        $this->requireDatabase();
        Cubes::forgetExistence();

        $this->assertNull(Status::readiness(self::READY_SITE));

        $waiting = Status::readiness(self::WAITING_SITE);

        $this->assertNotNull($waiting);
        $this->assertContains($waiting['state'], ['waiting', 'scheduler']);
        $this->assertSame((string) self::WAITING_PROPERTY, $waiting['property_id']);
    }

    /** Asked once per request: every caller in one request gets the same answer. */
    public function testWhetherACubeExistsIsAskedOncePerRequest(): void
    {
        $this->requireDatabase();
        Cubes::forgetExistence();

        $this->assertFalse(Cubes::exists(self::WAITING_PROPERTY));

        $this->assertTrue(Cubes::create(self::WAITING_PROPERTY));

        try {
            $this->assertFalse(Cubes::exists(self::WAITING_PROPERTY), 'the answer holds for the request');

            Cubes::forgetExistence();

            $this->assertTrue(Cubes::exists(self::WAITING_PROPERTY), 'and the next request sees the cube');
        } finally {
            foreach (['', '_rebuild', '_computed'] as $suffix) {
                \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s%s',
                    Cubes::tableFor(self::WAITING_PROPERTY), $suffix));
            }

            Cubes::forgetExistence();
        }
    }

    /** Only screens that draw cube data are held back; the builders are not. */
    public function testOnlyScreensThatDrawCubeDataAreHeldBack(): void
    {
        $reads = function (string $class): bool {
            $defaults = (new ReflectionClass($class))->getDefaultProperties();

            return (bool) ($defaults['reads_reporting_data'] ?? false);
        };

        $this->assertTrue($reads(\OWA\Core\ConfiguredReport::class));
        $this->assertTrue($reads(\OWA\Module\Base\Controller\VisualizationFunnel::class));

        foreach ([\OWA\Module\Base\Controller\CustomReportEdit::class,
                  \OWA\Module\Base\Controller\VisualizationEdit::class,
                  \OWA\Module\Base\Controller\CustomReports::class,
                  \OWA\Module\Base\Controller\ReportsRest::class] as $class) {
            $this->assertFalse($reads($class), "$class works without a cube");
        }
    }

    /** Not ready: the report's subview is replaced, so no widget is drawn. */
    public function testAReportThatIsNotReadyDrawsTheNoticeInsteadOfItsWidgets(): void
    {
        $class      = new ReflectionClass(\OWA\Core\ConfiguredReport::class);
        $controller = $class->newInstanceWithoutConstructor();

        $controller->data = ['subview' => \OWA\Core\ConfiguredReport::SUBVIEW,
                             'reporting_readiness' => Status::readinessFor('7', false, null)];
        $controller->post();

        $this->assertSame('base.reportNotReady', $controller->data['subview']);

        $controller->data = ['subview' => \OWA\Core\ConfiguredReport::SUBVIEW, 'reporting_readiness' => null];
        $controller->post();

        $this->assertSame(\OWA\Core\ConfiguredReport::SUBVIEW, $controller->data['subview'],
            'a ready report is left alone');
    }

    /**
     * NOTHING QUERIES A CUBE THAT DOES NOT EXIST: neither the REST route nor
     * ResultSetManager sends a statement at the missing table, and both say why.
     */
    public function testNeitherTheRestRouteNorTheQueryLayerQueriesAMissingCube(): void
    {
        $this->requireDatabase();
        Cubes::forgetExistence();

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $before = $db->lastQueryError();

        // Records whether the route built a result set at all: answering "not
        // ready" must come BEFORE one is built, not from the query layer's
        // backstop one step later.
        $spy = get_class(new class([]) extends \OWA\Module\Base\Controller\ReportsRest {
            public static $built = 0;

            public function __construct($params) {}

            function getResultSet() {
                self::$built++;

                return parent::getResultSet();
            }
        });

        $class = new ReflectionClass($spy);
        $rest  = $class->newInstanceWithoutConstructor();
        $p     = new ReflectionProperty(\OWA\Core\Controller::class, 'params');
        $p->setAccessible(true);
        $p->setValue($rest, ['siteId' => self::WAITING_SITE, 'metrics' => 'sessions', 'dimensions' => 'date']);

        $rest->action();

        $this->assertSame(0, $spy::$built, 'the REST route answered before building a result set');
        $this->assertNotEmpty($rest->data['response']->notReady, 'the REST route answers "not ready"');
        $this->assertSame([], (array) $rest->data['response']->resultsRows);

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();
        $rsm->metrics = $rsm->metricsStringToArray('sessions,pageViews');
        $rsm->setDimensions($rsm->dimensionsStringToArray('date'));
        $rsm->setSiteId(self::WAITING_SITE);
        $rsm->setTimePeriod('last_seven_days');

        $rs = $rsm->getResults();

        $this->assertNotEmpty($rs->notReady, 'and so does the query layer, for a caller that did not ask');
        $this->assertSame($before, $db->lastQueryError(),
            'no statement reached the missing table; the last error was: ' . $db->lastQueryError());
    }

    /** The tag question reads raw, on the Tracking Tag screen only. */
    public function testTheLastEventIsReadFromRaw(): void
    {
        $this->requireDatabase();

        $last = \OWA\Module\Base\Controller\SitesInvocation::lastEventReceived(self::WAITING_SITE);

        $this->assertSame(strtotime('-2 days 12:00:00'), $last);
        $this->assertNull(\OWA\Module\Base\Controller\SitesInvocation::lastEventReceived(self::READY_SITE),
            'a Profile that has received nothing');
        $this->assertNull(\OWA\Module\Base\Controller\SitesInvocation::lastEventReceived(''));
    }

    public function testTheNoticeOffersTheNextStep(): void
    {
        $down = $this->render('report_not_ready.php', [
            'readiness'            => Status::readinessFor('7', true, null),
            'siteId'               => 'alice-site',
            'can_view_cube_status' => false,
        ]);

        $this->assertStringContainsString('Reports need the job scheduler.', $down);
        $this->assertStringContainsString('cmd=schedule-run', $down, 'the cron entry is shown');
        $this->assertStringContainsString('base.sitesInvocation', $down);
        $this->assertStringNotContainsString('base.cubeStatusDetail', $down,
            'the status page is offered only to someone who can open it');

        $admin = $this->render('report_not_ready.php', [
            'readiness'            => Status::readinessFor('7', false, null),
            'siteId'               => 'alice-site',
            'can_view_cube_status' => true,
        ]);

        $this->assertStringContainsString('base.cubeStatusDetail', $admin);
        $this->assertStringNotContainsString('cmd=schedule-run', $admin);

        $orphan = $this->render('report_not_ready.php', [
            'readiness'            => Status::readinessFor('', false, null),
            'siteId'               => 'alice-site',
            'can_view_cube_status' => true,
        ]);

        $this->assertStringNotContainsString('base.sitesInvocation', $orphan,
            'with no Property there is no tag question to send anyone to');
    }
}
