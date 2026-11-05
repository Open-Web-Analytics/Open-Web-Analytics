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
 *   - an event step is gated on its event: a condition on the page does not
 *     make a click a page view.
 *
 * And that Update079 kept what each saved step counted: a path step and a goal
 * event step, made event steps, count what they counted before -- a trailing
 * slash, a directory index page and a typed ? included.
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

    /** directory_index_pages as it was, put back after a test that sets it. */
    private $indexPages = [];

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

            $property = \OWA\Core\CoreAPI::entityFactory('base.property');
            $property->setProperties(['id' => self::PROPERTY, 'name' => 'Funnel correctness fixture',
                'domain' => 'funnel-correctness.test',
                'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB, 'creation_date' => time()]);
            $property->create();

            $site = \OWA\Core\CoreAPI::entityFactory('base.site');
            $site->setProperties(['id' => self::PROPERTY * 10, 'site_id' => self::SITE,
                'property_id' => self::PROPERTY, 'name' => 'Funnel correctness fixture profile',
                'domain' => 'funnel-correctness.test']);
            $site->create();

            if (!\OWA\Module\Base\Classes\Cube\Cubes::create(self::PROPERTY)) {
                throw new \RuntimeException("creating the fixture Property's cube failed");
            }

            self::$ready = true;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DELETE FROM %s WHERE site_id = ?',
            \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY)), [self::SITE]);

        $this->indexPages = \OWA\Core\CoreAPI::getSetting('base', 'directory_index_pages');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'directory_index_pages', $this->indexPages);

        foreach ($this->goals as $goal) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_goal_event_definition_condition WHERE goal_event_definition_id = ?', [$goal]);
            \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_goal_event_definition WHERE id = ?', [$goal]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY);

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $cube, $suffix));
        }

        $db->query('DELETE FROM owa_site WHERE site_id = ?', [self::SITE]);
        $db->query(sprintf('DELETE FROM owa_property WHERE id = %d', self::PROPERTY));
        $db->query(sprintf('DELETE FROM %s WHERE property_id = %d',
            \OWA\Core\CoreAPI::entityFactory('base.custom_dimension')->getTableName(), self::PROPERTY));
        // Deleted under it, so its memo of the Property's dimensions goes too.
        \OWA\Module\Base\Classes\DimensionRegistry::forget(self::PROPERTY);

        self::$ready = false;
    }

    /** One cube row. */
    private function event(string $visitor, string $session, string $type, string $path, int $offset, ?int $seq = null,
                           ?string $query = null): void
    {
        $row = \OWA\Module\Base\Classes\Cube\Cubes::entityFor(self::PROPERTY);

        $row->setProperties([
            'id'         => (string) (9200000000000002000 + ++$this->seq),
            'site_id'    => self::SITE,
            'visitor_id' => $visitor,
            'session_id' => $session,
            'event_type' => $type,
            'page_path'  => $path,
            'page_query' => $query,
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

    /**
     * Steps written as pages ({path}) are page steps: a page_view on that path,
     * as Update079 makes a saved one. Any other step is passed as it is.
     */
    private function funnel(array $steps, string $scope = 'visitor'): array
    {
        $steps = array_map(static function ($step) {
            return isset($step['path']) && !isset($step['kind'])
                ? \OWA\Module\Base\Update\Update079::convert(['steps' => [$step]], [])['definition']['steps'][0]
                : $step;
        }, $steps);

        $controller = new \OWA\Module\Base\Controller\VisualizationFunnel(['propertyId' => (string) self::PROPERTY, 'period' => 'today']);

        $m = new ReflectionMethod($controller, 'countFunnel');
        $m->setAccessible(true);

        return $m->invoke($controller, $steps, $scope);
    }

    /** A goal event definition on $trigger whose page_path is $path; none when $path is null. */
    private function goal(string $trigger, ?string $path): string
    {
        $goal = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition');
        $id = (string) (9200000000000003000 + count($this->goals));
        $goal->setProperties(['id' => $id, 'property_id' => self::PROPERTY, 'name' => 'Correctness_goal',
            'trigger_event_type' => $trigger, 'condition_match' => \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ALL,
            'is_active' => 1]);
        $goal->create();

        $this->goals[] = $id;

        if ($path === null) {
            return $id;
        }

        $condition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition_condition');
        $condition->setProperties(['id' => (string) ((int) $id + 500), 'goal_event_definition_id' => $id,
            'condition_property' => 'page_path', 'condition_operator' => \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_EXACT,
            'condition_value' => $path]);
        $condition->create();

        return $id;
    }

    /**
     * A goal event step as Update079 converts a saved one: the definition read
     * back from its tables, the step made an event step carrying a copy.
     */
    private function convertedGoalStep(string $id): array
    {
        $update = new \OWA\Module\Base\Update\Update079();

        $m = new ReflectionMethod($update, 'goalDefinitions');
        $m->setAccessible(true);

        $definition = ['steps' => [['name' => 'Goal', 'goal_event_definition_id' => $id, 'step_number' => 1]]];

        $converted = \OWA\Module\Base\Update\Update079::convert($definition, $m->invoke($update, $definition));

        $this->assertArrayHasKey(0, $converted['steps'], 'the goal step was not converted');

        return $converted['definition']['steps'][0];
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

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('UPDATE %s SET visitor_id = NULL WHERE site_id = ?',
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

    /**
     * A goal event step, converted, counts what it counted: its definition's
     * trigger gates, so a click on /thanks is not a page_view on it. The
     * converted step compiles to the same predicate the definition did.
     */
    public function testAConvertedGoalStepCountsWhatTheDefinitionCounted(): void
    {
        $thanks = $this->goal('page_view', '/thanks');
        $step   = $this->convertedGoalStep($thanks);

        $definition = \OWA\Core\CoreAPI::entityFactory('base.goal_event_definition');
        $definition->load($thanks);

        $this->assertSame(
            (new \OWA\Module\Base\Classes\GoalEventDefinitionPredicate())->compile($definition, 'e'),
            (new \OWA\Module\Base\Classes\EventPredicate())->compile($step['event'], $step['match'],
                $step['conditions'], 'e'),
            'the event step does not compile to what its definition did');

        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V1, self::S, 'click', '/thanks', 10);

        $steps = [['path' => '/a'], $step];

        $this->assertSame([1, 0], $this->funnel($steps), 'a click on /thanks is not a page_view step');

        $this->event(self::V1, self::S, 'page_view', '/thanks', 20);

        $this->assertSame([1, 1], $this->funnel($steps));
    }

    /** A goal on a site's own event with no conditions becomes a step on every one of that event. */
    public function testAConvertedCustomEventGoalCountsEveryOne(): void
    {
        $step = $this->convertedGoalStep($this->goal('newsletter_signup', null));

        $this->assertSame('newsletter_signup', $step['event']);
        $this->assertSame([], $step['conditions']);

        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V1, self::S, 'newsletter_signup', '/a', 10);

        $this->assertSame([1, 1], $this->funnel([['path' => '/a'], $step]));
    }

    /**
     * A path step, converted, counts what the path step did: the typed path
     * canonicalised as the row's was -- a trailing slash, and a directory
     * index page -- and a typed ? split into page_path and page_query.
     */
    public function testAConvertedPathStepCountsWhatThePathStepCounted(): void
    {
        $this->event(self::V1, self::S, 'page_view', '/store', 0);
        $this->event(self::V1, self::S, 'page_view', '/checkout', 10, null, 'step=3');
        $this->event(self::V2, '9200000000000001204', 'page_view', '/store', 0);
        $this->event(self::V2, '9200000000000001204', 'page_view', '/checkout', 10, null, 'step=2');

        $this->assertSame([2, 2], $this->funnel([['path' => '/store/'], ['path' => '/checkout']]),
            'a trailing slash is collapsed, and a path with no ? matches whatever the query');

        $this->assertSame([2, 1], $this->funnel([['path' => '/store'], ['path' => '/checkout?step=2']]),
            'a typed ? matches the query as well as the path');

        $this->assertSame([2, 2], $this->funnel([['path' => '/store'], ['path' => '/checkout?']]),
            'a ? with nothing after it is no condition on the query');

        // The default list, set for this request: a list the install stored does not decide it.
        \OWA\Core\CoreAPI::setSetting('base', 'directory_index_pages',
            \OWA\Module\Base\Classes\V2Event::DIRECTORY_INDEX_PAGES);

        $this->assertSame([2, 2], $this->funnel([['path' => '/store/index.php'], ['path' => '/checkout']]),
            'a directory index page is collapsed');

        $this->assertSame([2, 2], $this->funnel([['path' => '/store/index.html'], ['path' => '/checkout']]),
            'whichever of the list it is');
    }

    /**
     * A step can test a custom dimension applied to the funnel's Property, by
     * its column on the cube.
     */
    public function testAStepCanTestACustomDimensionOfTheProperty(): void
    {
        $dimensions = \OWA\Module\Base\Classes\Cube\Dimensions::class;

        if (!array_key_exists('cd_tier', \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(self::PROPERTY))) {

            $registered = $dimensions::register(self::PROPERTY, [['key' => 'tier', 'scope' => 'event']]);
            $this->assertTrue($registered['ok'], (string) $registered['error']);

            $applied = $dimensions::reconcile(self::PROPERTY);
            $this->assertTrue($applied['ok'], (string) $applied['error']);
        }

        $this->assertArrayHasKey('cd_tier', \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(self::PROPERTY));

        $this->event(self::V1, self::S, 'page_view', '/a', 0);
        $this->event(self::V1, self::S, 'click', '/a', 10);
        $this->event(self::V2, '9200000000000001205', 'page_view', '/a', 0);
        $this->event(self::V2, '9200000000000001205', 'click', '/a', 10);

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("UPDATE %s SET cd_tier = 'gold' WHERE site_id = ? AND visitor_id = ?"
            . " AND event_type = 'click'", \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY)), [self::SITE, self::V1]);

        $gold = ['kind' => 'event', 'event' => 'click', 'match' => 'all', 'conditions' => [
            ['property' => 'cd_tier', 'operator' => 'exact', 'value' => 'gold'],
        ]];

        $this->assertSame([2, 1], $this->funnel([['path' => '/a'], $gold]));
    }
}
