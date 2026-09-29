<?php

use PHPUnit\Framework\TestCase;

/**
 * Four ways the funnel counted what nobody did, each against the real cube.
 *
 *   - a session is (visitor_id, session_id): two visitors can share a
 *     session_id, and their events are not one visit -- and sessions with no
 *     visitor id are not one visit either;
 *   - device order (event_seq) decides, not arrival: a late beacon does not
 *     reorder what happened;
 *   - a page step is a PAGE VIEW: not a click on that page, and not the
 *     session_start / first_visit markers saved with the landing view;
 *   - a goal step is met as ingest marks it: gated on the goal event's
 *     trigger event type.
 */
final class FunnelCubeCorrectnessTest extends TestCase
{
    private const SITE = 'funnel-correctness-site';

    /** High and fixed, apart from GoalFunnelOrderTest's. */
    private const PROPERTY = 92000002;

    private const V1 = '9200000000000001101';
    private const V2 = '9200000000000001102';
    private const S  = '9200000000000001201';

    private static $ready = false;

    private int $seq = 0;

    private array $goals = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the funnel query needs a database');
        }

        if (!self::$ready) {
            self::tearDownAfterClass();

            $property = owa_coreAPI::entityFactory('base.property');
            $property->setProperties(['id' => self::PROPERTY, 'name' => 'Funnel correctness fixture',
                'domain' => 'funnel-correctness.test',
                'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB, 'creation_date' => time()]);
            $property->create();

            $site = owa_coreAPI::entityFactory('base.site');
            $site->setProperties(['id' => self::PROPERTY * 10, 'site_id' => self::SITE,
                'property_id' => self::PROPERTY, 'name' => 'Funnel correctness fixture profile',
                'domain' => 'funnel-correctness.test']);
            $site->create();

            if (!\OWA\Module\Base\Classes\Cube\Cubes::create(self::PROPERTY)) {
                throw new \RuntimeException("creating the fixture Property's cube failed");
            }

            self::$ready = true;
        }

        owa_coreAPI::dbSingleton()->query(sprintf('DELETE FROM %s WHERE site_id = ?',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY)), [self::SITE]);
    }

    protected function tearDown(): void
    {
        foreach ($this->goals as $goal) {
            owa_coreAPI::dbSingleton()->query('DELETE FROM owa_goal_event_condition WHERE goal_event_id = ?', [$goal]);
            owa_coreAPI::dbSingleton()->query('DELETE FROM owa_goal_event WHERE id = ?', [$goal]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();
        $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY);

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $cube, $suffix));
        }

        $db->query('DELETE FROM owa_site WHERE site_id = ?', [self::SITE]);
        $db->query(sprintf('DELETE FROM owa_property WHERE id = %d', self::PROPERTY));

        self::$ready = false;
    }

    /** One cube row. */
    private function event(string $visitor, string $session, string $type, string $path, int $offset, ?int $seq = null): void
    {
        $row = \OWA\Module\Base\Classes\Cube\Cubes::entityFor(self::PROPERTY);

        $row->setProperties([
            'id'         => (string) (9200000000000002000 + ++$this->seq),
            'site_id'    => self::SITE,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'event_type' => $type,
            'page_path'  => $path,
            'ts'         => (strtotime('today 12:00') + $offset) * 1000000,
            'yyyymmdd'   => (int) date('Ymd'),
            'event_seq'  => $seq,
            'acq_source' => 'direct',
            'acq_medium' => 'direct',
            'built_at'   => (int) round(microtime(true) * 1000000),
        ]);

        if (!$row->create()) {
            throw new \RuntimeException('seeding the fixture cube failed');
        }
    }

    private function funnel(array $steps, string $scope = 'visitor'): array
    {
        $controller = new \OWA\Module\Base\Controller\VisualizationFunnel(['siteId' => self::SITE, 'period' => 'today']);

        $m = new ReflectionMethod($controller, 'countFunnel');
        $m->setAccessible(true);

        return $m->invoke($controller, $steps, $scope);
    }

    /** A goal event on $trigger whose page_path is $path. */
    private function goal(string $trigger, string $path): string
    {
        $goal = owa_coreAPI::entityFactory('base.goal_event');
        $id = (string) (9200000000000003000 + count($this->goals));
        $goal->setProperties(['id' => $id, 'property_id' => self::PROPERTY, 'name' => 'Correctness goal',
            'trigger_event_type' => $trigger, 'condition_match' => \OWA\Module\Base\Entity\GoalEvent::MATCH_ALL,
            'is_active' => 1]);
        $goal->create();

        $condition = owa_coreAPI::entityFactory('base.goal_event_condition');
        $condition->setProperties(['id' => (string) ((int) $id + 500), 'goal_event_id' => $id,
            'condition_property' => 'page_path', 'condition_operator' => \OWA\Module\Base\Entity\GoalEvent::MATCH_EXACT,
            'condition_value' => $path]);
        $condition->create();

        $this->goals[] = $id;

        return $id;
    }

    public function testTwoVisitorsSharingASessionIdAreTwoSessions(): void
    {
        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V2, self::S, 'page_view', '/b', 10);

        $counts = $this->funnel([['path' => '/a'], ['path' => '/b']], 'session');

        $this->assertSame([1, 0], $counts, 'one visitor saw /a, another /b: nobody walked the funnel');
    }

    /** Sessions without a visitor id stay apart: CONCAT() of a NULL is NULL. */
    public function testSessionsWithoutAVisitorIdAreNotOneSession(): void
    {
        $this->event(self::V1, '9200000000000001202', 'page_view', '/a', 0);
        $this->event(self::V1, '9200000000000001203', 'page_view', '/b', 10);

        owa_coreAPI::dbSingleton()->query(sprintf('UPDATE %s SET visitor_id = NULL WHERE site_id = ?',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY)), [self::SITE]);

        $this->assertSame([1, 0], $this->funnel([['path' => '/a'], ['path' => '/b']], 'session'));
    }

    public function testDeviceOrderDecidesNotArrival(): void
    {
        // /a happened first (seq 1) but its beacon arrived after /b's.
        $this->event(self::V1, self::S, 'page_view', '/a', 10, 1);
        $this->event(self::V1, self::S, 'page_view', '/b', 5, 2);

        $this->assertSame([1, 1], $this->funnel([['path' => '/a'], ['path' => '/b']]));
    }

    public function testTheMarkersSavedWithALandingViewAreNotAStep(): void
    {
        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V1, self::S, 'session_start', '/a', 0);
        $this->event(self::V1, self::S, 'first_visit', '/a', 0);

        $this->assertSame([1, 0], $this->funnel([['path' => '/a'], ['path' => '/a']]),
            'one page view is one step');
    }

    public function testAClickOnAPageIsNotAViewOfIt(): void
    {
        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V1, self::S, 'click', '/b', 10);

        $this->assertSame([1, 0], $this->funnel([['path' => '/a'], ['path' => '/b']]));

        $this->event(self::V1, self::S, 'page_view', '/b', 20);

        $this->assertSame([1, 1], $this->funnel([['path' => '/a'], ['path' => '/b']]));
    }

    public function testAGoalStepIsMetOnlyByItsTriggerEventType(): void
    {
        $thanks = $this->goal('page_view', '/thanks');

        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V1, self::S, 'click', '/thanks', 10);

        $steps = [['path' => '/a'], ['goal_event_id' => $thanks]];

        $this->assertSame([1, 0], $this->funnel($steps), 'a click on /thanks is not a page_view goal');

        $this->event(self::V1, self::S, 'page_view', '/thanks', 20);

        $this->assertSame([1, 1], $this->funnel($steps));
    }
}
