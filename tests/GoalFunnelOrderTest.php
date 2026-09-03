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
 * AND WHAT REPLACED THE REPLACEMENT
 *
 * The fix for that took MIN(timestamp) per step and then checked the times came
 * out in order. It is the obvious implementation and it is still wrong, because
 * the first time somebody reached a step OVERALL is not the first time they
 * reached it AFTER the step before. A visitor who reads the docs, comes back to
 * the home page, goes to pricing and reads the docs again has been through
 * / -> /pricing -> /docs; MIN puts the docs visit at the front and drops them.
 *
 * Funnels start on home pages and pricing pages, which are exactly the pages
 * people also visit at other times, so this is the common case rather than an
 * odd one -- and the error is always an undercount at the later steps, which
 * reads as a conversion problem rather than as a bug.
 *
 * It walks each subject's events forward now, advancing one stage at a time:
 * GA's closed funnel with indirectly-followed steps, where a subject enters
 * once in the period and it is their first run through that counts. The
 * arithmetic itself is tested without a database in FunnelMathTest; this file
 * is about the query underneath it -- that the right rows come back, grouped by
 * the right subject, in the right order.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE
 *
 * Five visitors over two steps, /a then /b:
 *
 *   v1  /a then /b                   -- passed through, in order
 *   v2  /b then /a                   -- saw both, never /b after /a
 *   v3  /a only                      -- entered and stopped
 *   v4  /b, then later /a then /b    -- passed through, with a false start
 *   v5  /a in one visit, /b in the next
 *
 * v4 is the one that separates the two implementations: MIN says their first
 * /b precedes their first /a and drops them, and they plainly went / a -> /b.
 *
 * v5 is the one that separates the two SCOPES. As a person they went through;
 * as a visit neither half did, because the visit holding /b never saw /a. A
 * fixture where every visitor behaves scores the same under both and proves
 * nothing about the toggle.
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
        $v5 = '9200000000000000105';
        $s1 = '9200000000000000201'; $s2 = '9200000000000000202';
        $s3 = '9200000000000000203';
        $s4a = '9200000000000000204'; $s4b = '9200000000000000205';
        $s5a = '9200000000000000206'; $s5b = '9200000000000000207';

        $hits = array(
            array($v1, $s1, '/a', 0),
            array($v1, $s1, '/b', 10),   // in order
            array($v2, $s2, '/b', 0),
            array($v2, $s2, '/a', 10),   // reversed
            array($v3, $s3, '/a', 0),    // entered only

            /*
             * v4 is the visitor MIN-per-step gets wrong.
             *
             * A visit that only saw /b, then a later visit that did /a and then
             * /b properly. Their first /b comes before their first /a, so MIN
             * reads the funnel as out of order and drops them -- and they went
             * through it: /a at 10, /b at 20. Both scopes count them.
             *
             * It also separates the first /b from the last: taking MAX rather
             * than MIN would be a different wrong answer that happens to be
             * right about v4.
             */
            array($v4, $s4a, '/b', 0),
            array($v4, $s4b, '/a', 10),
            array($v4, $s4b, '/b', 20),

            /*
             * v5 separates the two SCOPES, which is what v4 used to do before
             * the ordering was fixed.
             *
             * /a in one visit and /b in the next. As a PERSON they went through
             * the funnel; as a VISIT neither half did -- the second visit never
             * saw /a, and a closed funnel is entered at step one. So visitor
             * scope must count them and session scope must not.
             */
            array($v5, $s5a, '/a', 0),
            array($v5, $s5b, '/b', 30),
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

        $controller = new \OWA\Module\Base\Controller\VisualizationFunnel(array(
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

        $this->assertSame(5, $counts[0], 'every visitor reached /a');

        /*
         * v1, v4 and v5. Not v2, who saw /b only before /a and never after;
         * not v3, who stopped.
         *
         * This said ONE while the counting took MIN per step, because v4's
         * earlier /b was read as their position in the funnel. They visited /a
         * and then visited /b, which is what the funnel asks.
         */
        $this->assertSame(3, $counts[1],
            'v1, v4 and v5 each reached /b after an /a; v2 only ever saw /b first');
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

        $this->assertSame(4, $counts[0], 'v1, v2, v4 and v5 reached /b');
        $this->assertSame(2, $counts[1], 'only v2 and v4 reached /a after a /b');
    }

    public function testSessionScopeCountsVisitsRatherThanVisitors(): void
    {
        $byVisitor = $this->funnel($this->steps(), 'visitor');
        $bySession = $this->funnel($this->steps(), 'session');

        /*
         * v5 went through as a PERSON -- /a in one visit, /b in the next -- and
         * as a VISIT neither half did: the visit holding /b never saw /a, and a
         * closed funnel is entered at step one.
         *
         * So the two scopes must disagree, and a toggle wired to nothing would
         * show them agreeing. This used to be v4's job, which it could only do
         * while the ordering was computed from MIN.
         */
        $this->assertSame(3, $byVisitor[1], 'as people: v1, v4 and v5');
        $this->assertSame(2, $bySession[1], "as visits: v1's visit and v4's second");

        $this->assertNotSame($byVisitor, $bySession,
            'the scope must change the answer, or it is not selecting anything');
    }

    /**
     * The same page twice is TWO steps, so it takes two visits.
     *
     * A step is something the subject did, so one event cannot satisfy two of
     * them -- otherwise a single page view would complete a three-stage funnel
     * written against one page.
     *
     * This asserted the opposite while the counting took MIN per step: both
     * steps resolved to the same timestamp, that timestamp is not before
     * itself, and everyone who saw /a once "completed" a two-step funnel.
     */
    public function testTheSameStepTwiceNeedsTwoVisits(): void
    {
        $counts = $this->funnel(array(array('path' => '/a'), array('path' => '/a')), 'visitor');

        $this->assertSame(5, $counts[0], 'everyone saw /a at least once');

        $this->assertSame(0, $counts[1],
            'nobody in the fixture visited /a twice, so nobody completed /a then /a');
    }

    /**
     * And a repeat visit DOES satisfy it, which is the other half of the claim.
     *
     * v4 sees /b twice -- once alone, once after /a -- so a funnel of /b then
     * /b is one they completed, and nobody else did.
     */
    public function testARepeatedPageSatisfiesARepeatedStep(): void
    {
        $counts = $this->funnel(array(array('path' => '/b'), array('path' => '/b')), 'visitor');

        $this->assertSame(4, $counts[0], 'v1, v2, v4 and v5 saw /b');
        $this->assertSame(1, $counts[1], 'only v4 saw /b twice');
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

            $controller = new \OWA\Module\Base\Controller\VisualizationFunnel(
                array('siteId' => self::SITE) + ($asked === '' ? array() : array('funnelScope' => $asked))
            );

            $m = new ReflectionMethod($controller, 'scope');
            $m->setAccessible(true);

            $this->assertSame($expected, $m->invoke($controller),
                sprintf('funnelScope=%s should read as %s', var_export($asked, true), $expected));
        }
    }
}
