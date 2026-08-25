<?php

use PHPUnit\Framework\TestCase;

/**
 * A funnel counts people who moved THROUGH it, in order.
 *
 * WHAT THIS REPLACES
 *
 * Every step used to be its own query -- `visitors where pagePath == <step>` --
 * with no ordering and no dependence on the step before it. So it was not a
 * funnel: somebody who landed straight on the last page and never saw the first
 * counted in the last step, and a later step could out-count an earlier one
 * (the old code carried a "backfill check" for exactly that, which is the shape
 * of the bug rather than a fix for it).
 *
 * It is now one query. Per subject it takes the FIRST time they reached each
 * step, then counts those whose times run in order -- GA's "indirectly followed
 * by": intervening pages are allowed, going backwards is not.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE
 *
 * Three visitors over two steps, /a then /b:
 *
 *   v1  /a then /b     -- passed through, in order
 *   v2  /b then /a     -- saw both, in the WRONG order
 *   v3  /a only        -- entered and stopped
 *
 * So step 1 is three and step 2 is ONE. Counting per step independently gives
 * two for step 2, which is the old answer; requiring order gives one. A fixture
 * where everyone behaves would score the same either way and prove nothing.
 */
final class GoalFunnelOrderTest extends TestCase
{
    private const SITE = 'funnel-order-test-site';

    /** @var array<int,string> ids to clean up */
    private static $documents = array();
    private static $requests   = array();

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the funnel query needs a database');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();
        $db->query('DELETE FROM owa_request WHERE site_id = ?', array(self::SITE));

        foreach (self::$documents as $id) {
            $db->query('DELETE FROM owa_document WHERE id = ?', array($id));
        }
    }

    private function seed(): void
    {
        if (self::$requests) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();
        $db->query('DELETE FROM owa_request WHERE site_id = ?', array(self::SITE));

        $paths = array('/a' => null, '/b' => null);

        foreach (array_keys($paths) as $path) {

            $d = owa_coreAPI::entityFactory('base.document');
            $id = $d->generateId(self::SITE . $path);

            $db->query('DELETE FROM owa_document WHERE id = ?', array($id));

            $d->set('id', $id);
            $d->set('uri', $path);
            $d->set('url', 'https://funnel.test' . $path);
            $d->set('page_type', 'page');
            $d->create();

            $paths[$path]     = $id;
            self::$documents[] = $id;
        }

        $day = (int) date('Ymd');
        $now = time();

        /*
         * visitor, session, path, seconds offset -- the offset is what orders them.
         *
         * The ids are NUMERIC because visitor_id and session_id are BIGINT: a
         * tracker GUID is a number, and a string id is refused outright under
         * strict mode ("Incorrect integer value: 'v1'"). A fixture using 'v1'
         * inserts nothing and every count reads zero.
         */
        $v1 = '9200000000000000101'; $v2 = '9200000000000000102';
        $v3 = '9200000000000000103'; $v4 = '9200000000000000104';
        $s1 = '9200000000000000201'; $s2 = '9200000000000000202';
        $s3 = '9200000000000000203';
        $s4a = '9200000000000000204'; $s4b = '9200000000000000205';

        $hits = array(
            array($v1, $s1, '/a', 0),
            array($v1, $s1, '/b', 10),   // in order
            array($v2, $s2, '/b', 0),
            array($v2, $s2, '/a', 10),   // reversed
            array($v3, $s3, '/a', 0),    // entered only

            /*
             * v4 exists to separate things a simpler fixture cannot.
             *
             * TWO visits: one that only saw /b, and a later one that did /a then
             * /b properly. So across the VISITOR's whole history the first /b
             * (visit one) comes before the first /a (visit two) and v4 has not
             * been through the funnel -- but visit two, on its own, has. Visitor
             * scope and session scope therefore give different answers, which is
             * the only way a test can tell the toggle is wired to anything.
             *
             * It also separates MIN from MAX: v4 hits /b twice, before and after
             * /a. Taking the LAST /b would make v4 look like it converted.
             */
            array($v4, $s4a, '/b', 0),
            array($v4, $s4b, '/a', 10),
            array($v4, $s4b, '/b', 20),
        );

        foreach ($hits as $i => $hit) {

            list($visitor, $session, $path, $offset) = $hit;

            $r = owa_coreAPI::entityFactory('base.request');
            $id = (string) (9200000000000000000 + $i);

            $r->set('id', $id);
            $r->set('site_id', self::SITE);
            $r->set('visitor_id', $visitor);
            $r->set('session_id', $session);
            $r->set('document_id', $paths[$path]);
            $r->set('timestamp', $now + $offset);
            $r->set('yyyymmdd', $day);
            $r->create();

            self::$requests[] = $id;
        }
    }

    /** The counting method, which is where the semantics live. */
    private function funnel(array $steps, string $scope): array
    {
        $this->seed();

        $controller = new \OWA\Module\Base\Controller\ReportGoalFunnel(array(
            'siteId' => self::SITE,
            'period' => 'today',
        ));

        $m = new ReflectionMethod($controller, 'countFunnel');
        $m->setAccessible(true);

        return $m->invoke($controller, $steps, $scope);
    }

    private function steps(): array
    {
        return array(array('path' => '/a'), array('path' => '/b'));
    }

    public function testAStepIsOnlyReachedAfterTheOneBeforeIt(): void
    {
        $counts = $this->funnel($this->steps(), 'visitor');

        $this->assertSame(4, $counts[0], 'every visitor reached /a');

        $this->assertSame(1, $counts[1],
            'only v1 reached /b after /a; v2 and v4 saw both in the wrong order and v3 stopped');
    }

    /**
     * The property the old "backfill check" was papering over.
     */
    public function testAStepCannotOutCountTheOneBeforeIt(): void
    {
        $counts = $this->funnel($this->steps(), 'visitor');

        for ($i = 1; $i < count($counts); $i++) {
            $this->assertLessThanOrEqual($counts[$i - 1], $counts[$i],
                'a funnel step must not exceed the step before it');
        }
    }

    /** Reversing the steps reverses who passed through, which pins the ordering. */
    public function testTheOrderOfTheStepsIsWhatDecides(): void
    {
        $reversed = array(array('path' => '/b'), array('path' => '/a'));

        $counts = $this->funnel($reversed, 'visitor');

        $this->assertSame(3, $counts[0], 'v1, v2 and v4 reached /b');
        $this->assertSame(2, $counts[1], 'v2 and v4 reached /a after their first /b');
    }

    public function testSessionScopeCountsVisitsRatherThanVisitors(): void
    {
        $byVisitor = $this->funnel($this->steps(), 'visitor');
        $bySession = $this->funnel($this->steps(), 'session');

        // v4 did not go through as a PERSON -- their first /b was in an earlier
        // visit than their first /a -- but their second VISIT went through
        // cleanly. So the two scopes must disagree, and a toggle wired to
        // nothing would show them agreeing.
        $this->assertSame(1, $byVisitor[1], 'as people, only v1 went through');
        $this->assertSame(2, $bySession[1], "as visits, v1's and v4's second visit went through");

        $this->assertNotSame($byVisitor, $bySession,
            'the scope must change the answer, or it is not selecting anything');
    }

    /** A page hit twice must not read as two different positions in the order. */
    public function testTheFirstVisitToAStepIsTheOneThatCounts(): void
    {
        $counts = $this->funnel(array(array('path' => '/a'), array('path' => '/a')), 'visitor');

        $this->assertSame(4, $counts[0]);
        $this->assertSame(4, $counts[1],
            'the same step twice is reached at the same moment, not before itself');
    }

    public function testAnEmptyFunnelCountsNothingRatherThanThrowing(): void
    {
        $this->assertSame(array(), $this->funnel(array(), 'visitor'));
    }

    /** The scope comes off the URL, and an unknown one is not an error. */
    public function testScopeIsReadFromTheUrlAndDefaultsToVisitor(): void
    {
        foreach (array('' => 'visitor', 'visitor' => 'visitor',
                       'session' => 'session', 'nonsense' => 'visitor') as $asked => $expected) {

            $controller = new \OWA\Module\Base\Controller\ReportGoalFunnel(
                array('siteId' => self::SITE) + ($asked === '' ? array() : array('funnelScope' => $asked))
            );

            $m = new ReflectionMethod($controller, 'scope');
            $m->setAccessible(true);

            $this->assertSame($expected, $m->invoke($controller),
                sprintf('funnelScope=%s should read as %s', var_export($asked, true), $expected));
        }
    }
}
