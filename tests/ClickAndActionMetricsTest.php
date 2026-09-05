<?php

use PHPUnit\Framework\TestCase;

/**
 * The click and action metrics and dimensions, end to end.
 *
 * WHY THIS DID NOT EXIST, WHICH IS THE POINT OF IT
 *
 * Nothing anywhere produced a click. The heatmap's plotting is tested against
 * rows typed into a JS test; the overlay e2e proves a canvas gets created; the
 * cross-origin e2e proves the fetch is not blocked; the token tests prove the
 * signature. Every layer is checked against a MOCK of its neighbour, so a break
 * BETWEEN them is invisible -- `domClicks` could point at the wrong entity, the
 * dom-element dimensions could stop resolving, ClickHandlers could quietly fail
 * to write, and all of it stays green.
 *
 * So this one produces clicks and actions the way the tracker does -- real
 * events through logEvent, so the handlers run -- and then asks the ordinary
 * reporting stack for them by name, the way a report does.
 *
 * THE FIXTURE IS ASYMMETRIC ON PURPOSE
 *
 * Every number is different from every other. Six clicks: five on one element,
 * one on another; four on one page, two on another; three sharing a single
 * coordinate. Four actions whose three metrics must answer 4, 2 and 22 --
 * how many happened, how many distinct NAMES, and what they were worth. A
 * fixture whose totals agree cannot tell you which grouping was applied, and a
 * report answering the wrong question would look correct.
 */
final class ClickAndActionMetricsTest extends TestCase
{
    /**
     * A REAL site, created the way the admin UI creates one.
     *
     * Not an invented string: logEvent refuses an event whose site is not
     * registered -- which is the whole subject of UnknownSiteRejectionTest --
     * so a fixture that made up a site id would fire events that are dropped on
     * the floor, and every assertion here would read zero for a reason that has
     * nothing to do with clicks.
     *
     * @var string
     */
    private static $siteId = '';

    /** @var string the site row's own id, and the Property above it */
    private static $siteRowId = '';
    private static $propertyId = '';

    private const DOMAIN = 'https://click-metrics-test.example.com';

    /** page, element id, tag, x, y, how many */
    private const CLICKS = array(
        array( '/',        'buy-btn',  'a',      100, 200, 3 ),
        array( '/',        'nav-home', 'a',      40,  50,  1 ),
        array( '/pricing', 'buy-btn',  'button', 300, 400, 2 ),
    );

    /** group, name, label, value, how many */
    private const ACTIONS = array(
        array( 'Signup',   'submit', 'form-a', 5,  2 ),
        array( 'Signup',   'cancel', 'form-a', 2,  1 ),
        array( 'Commerce', 'submit', 'cart',   10, 1 ),
    );

    private static $seeded = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if (!owa_test_db_available()) {
            return;
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite(self::DOMAIN, 'Click metrics test');

        self::$siteId     = (string) $site->get('site_id');
        self::$siteRowId  = (string) $site->get('id');
        self::$propertyId = (string) $site->get('property_id');

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('these metrics are read out of the database');
        }

        $this->seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();

        foreach (array('owa_click', 'owa_action_fact', 'owa_request', 'owa_session') as $table) {
            $db->query("DELETE FROM $table WHERE site_id = ?", array(self::$siteId));
        }

        /*
         * The site AND the Property above it. Creating a site mints one, and a
         * teardown that removes only the site leaves a parentless Property in
         * the picker -- the defect five other suites had.
         */
        if (self::$siteRowId) {
            $db->query('DELETE FROM owa_site WHERE id = ?', array(self::$siteRowId));
        }

        if (self::$propertyId) {
            $db->query('DELETE FROM owa_property WHERE id = ?', array(self::$propertyId));
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    /**
     * Clicks and actions, fired as real events.
     *
     * Through logEvent rather than by inserting rows: ClickHandlers is what
     * mints document_id from the page url and `position` from the coordinates,
     * and ActionHandler is what lowercases the name, group and label. A fixture
     * that wrote the tables directly would seed data no tracker could produce,
     * and would keep passing if the handlers stopped running.
     */
    private function seed(): void
    {
        if (self::$seeded) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();

        foreach (array('owa_click', 'owa_action_fact') as $table) {
            $db->query("DELETE FROM $table WHERE site_id = ?", array(self::$siteId));
        }

        $rc  = owa_coreAPI::requestContainerSingleton();
        $day = time() - (3 * 86400);
        $day = $day - ($day % 86400) + 43200;   // midday, clear of any boundary

        $visitor = $this->guid();
        $session = $this->guid();
        $offset  = 0;

        foreach (self::CLICKS as list($page, $id, $tag, $x, $y, $n)) {

            for ($i = 0; $i < $n; $i++) {

                $rc->timestamp = $day + ($offset++ * 60);

                $this->fire('dom.click', array(
                    'site_id'           => self::$siteId,
                    'session_id'        => $session,
                    'visitor_id'        => $visitor,
                    'guid'              => $this->guid(),
                    'page_url'          => self::DOMAIN . $page,
                    'page_title'        => 'Clicks ' . $page,
                    'target_url'        => self::DOMAIN . $page . '#' . $id,
                    'click_x'           => $x,
                    'click_y'           => $y,
                    'page_width'        => 1280,
                    'page_height'       => 2000,
                    'dom_element_id'    => $id,
                    'dom_element_tag'   => $tag,
                    'dom_element_name'  => $id . '-name',
                    'dom_element_class' => 'clickable',
                ));
            }
        }

        foreach (self::ACTIONS as list($group, $name, $label, $value, $n)) {

            for ($i = 0; $i < $n; $i++) {

                $rc->timestamp = $day + ($offset++ * 60);

                $this->fire('track.action', array(
                    'site_id'       => self::$siteId,
                    'session_id'    => $session,
                    'visitor_id'    => $visitor,
                    'guid'          => $this->guid(),
                    'page_url'      => self::DOMAIN . '/',
                    'page_title'    => 'Clicks /',
                    'action_group'  => $group,
                    'action_name'   => $name,
                    'action_label'  => $label,
                    'numeric_value' => $value,
                ));
            }
        }

        $rc->timestamp = time();

        self::$seeded = true;
    }

    private function fire(string $type, array $props): void
    {
        $props += array(
            'HTTP_USER_AGENT' => 'owa-click-metrics-test',
            'ip_address'      => '203.0.113.77',
        );

        $event = owa_coreAPI::supportClassFactory('base', 'event');
        $event->setEventType($type);
        $event->setProperties($props);

        owa_coreAPI::logEvent($type, $event);
    }

    /** Numeric, because visitor_id and session_id are BIGINT. */
    private function guid(): string
    {
        return ((string) time())
            . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Ask the ordinary reporting stack, by name, the way a report does.
     *
     * @return array rows, each dimension/metric keyed by its registered name
     */
    private function query(string $metrics, string $dimensions = ''): array
    {
        return $this->resultSet($metrics, $dimensions)['rows'];
    }

    /**
     * Both halves of a result set: the grouped rows, and the aggregates.
     *
     * A query with NO dimension returns nothing in resultsRows -- there is no
     * grouping, so there are no rows to group into. The number lives in
     * `aggregates` instead, which is the shape a metric-boxes widget reads.
     * Worth knowing before writing a test that asks for a bare total and
     * concludes the metric is broken.
     *
     * Named resultSet() rather than run(): TestCase::run() is final, and a
     * private helper that shadows it is a fatal error rather than an override.
     *
     * @return array{rows: array, aggregates: array}
     */
    private function resultSet(string $metrics, string $dimensions = ''): array
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray($metrics);

        if ($dimensions !== '') {
            $rsm->setDimensions($rsm->dimensionsStringToArray($dimensions));
        }

        $rsm->setSiteId(self::$siteId);
        $rsm->setTimePeriod('last_thirty_days');
        $rsm->setLimit(100);

        $results = $rsm->getResults();

        $this->assertEmpty($results->request_errors ?? array(),
            "the stack refused $metrics by $dimensions: "
            . implode(' ', (array) ($results->request_errors ?? array())));

        $rows = array();

        foreach ((array) ($results->resultsRows ?? array()) as $row) {

            $flat = array();

            foreach ($row as $name => $cell) {
                $flat[$name] = is_array($cell) ? ($cell['value'] ?? null) : $cell;
            }

            $rows[] = $flat;
        }

        $aggregates = array();

        foreach ((array) ($results->aggregates ?? array()) as $name => $cell) {
            $aggregates[$name] = is_array($cell) ? ($cell['value'] ?? null) : $cell;
        }

        return array('rows' => $rows, 'aggregates' => $aggregates);
    }

    /**
     * Group a result set into name => number, sorted by name.
     *
     * Sorted because assertSame compares key ORDER on associative arrays, and
     * the order rows come back in is the query's business rather than the
     * assertion's -- a test that fails because the database returned 'cancel'
     * before 'submit' is reporting on nothing.
     *
     * @return array
     */
    private function grouped(string $metric, string $dimension): array
    {
        $got = array();

        foreach ($this->query($metric, $dimension) as $row) {
            $got[(string) $row[$dimension]] = (int) $row[$metric];
        }

        ksort($got);

        return $got;
    }

    /** Sort an expectation the same way, so the comparison is about values. */
    private function sorted(array $expected): array
    {
        ksort($expected);

        return $expected;
    }

    /** One number: the metric with no grouping at all. */
    private function total(string $metric)
    {
        $aggregates = $this->resultSet($metric)['aggregates'];

        $this->assertArrayHasKey($metric, $aggregates,
            "$metric produced no aggregate at all, so the stack did not resolve it");

        return $aggregates[$metric];
    }

    // ---------------------------------------------------------------- clicks

    /** Six clicks were recorded, and domClicks counts all six. */
    public function testDomClicksCountsEveryClick(): void
    {
        $this->assertSame(6, (int) $this->total('domClicks'),
            'domClicks did not count the clicks that were recorded -- either the metric is '
            . 'pointed at the wrong entity or ClickHandlers did not write them');
    }

    /**
     * Grouped by the element, which is what the Clicks report draws.
     *
     * 5 and 1, not 3 and 3 or 6 and 0: the element with the most clicks got
     * them from two different pages, so a query that grouped by the wrong
     * column would still produce two rows and the wrong split.
     */
    public function testClicksGroupByDomElementId(): void
    {
        $this->assertSame(
            $this->sorted(array('buy-btn' => 5, 'nav-home' => 1)),
            $this->grouped('domClicks', 'domElementId'));
    }

    /** And by page, which splits the same six clicks a different way. */
    public function testClicksGroupByPage(): void
    {
        $rows = $this->query('domClicks', 'pagePath');

        $got = array();

        foreach ($rows as $row) {
            $got[(string) $row['pagePath']] = (int) $row['domClicks'];
        }

        $this->assertSame(4, $got['/'] ?? null, 'clicks on / were miscounted');
        $this->assertSame(2, $got['/pricing'] ?? null, 'clicks on /pricing were miscounted');
    }

    /** The other dom-element dimensions resolve and carry what was sent. */
    public function testTheOtherDomElementDimensionsResolve(): void
    {
        foreach (array(
            'domElementTag'   => array('a' => 4, 'button' => 2),
            'domElementClass' => array('clickable' => 6),
        ) as $dimension => $expected) {

            $this->assertSame($this->sorted($expected), $this->grouped('domClicks', $dimension),
                "$dimension grouped the clicks wrongly");
        }
    }

    /**
     * THE HEATMAP'S OWN QUERY.
     *
     * domClicks by clickX,clickY is exactly what the overlay asks for, and it
     * is the one query nothing exercised: the plotting test feeds it rows by
     * hand. Three clicks share (100,200), so the heatmap gets a point of weight
     * 3 beside points of weight 1 and 2 -- which is the only shape that can
     * tell a weighted plot from a plot of distinct positions.
     */
    public function testTheHeatmapQueryReturnsWeightedPoints(): void
    {
        $rows = $this->query('domClicks', 'clickX,clickY');

        $points = array();

        foreach ($rows as $row) {
            $points[$row['clickX'] . ',' . $row['clickY']] = (int) $row['domClicks'];
        }

        $this->assertSame(3, $points['100,200'] ?? null,
            'the hottest point did not come back with the weight of its three clicks');
        $this->assertSame(1, $points['40,50'] ?? null);
        $this->assertSame(2, $points['300,400'] ?? null);

        $this->assertSame(6, array_sum($points),
            'the weights do not add up to the clicks that were recorded');
    }

    // --------------------------------------------------------------- actions

    /**
     * Three metrics, three different questions, three different answers.
     *
     * Asserted together rather than one per test, because the whole point is
     * that they DISAGREE: 4 rows, 2 distinct names, 22 of value. Any two of
     * them being equal would mean one is answering the other's question.
     */
    public function testTheThreeActionMetricsAnswerDifferentQuestions(): void
    {
        $actions = (int) $this->total('actions');
        $unique  = (int) $this->total('uniqueActions');
        $value   = (int) $this->total('actionsValue');

        $this->assertSame(4,  $actions, 'actions should count every action row');
        $this->assertSame(2,  $unique,  'uniqueActions should count distinct action NAMES');
        $this->assertSame(22, $value,   'actionsValue should sum numeric_value');

        $this->assertNotSame($actions, $unique,
            'actions and uniqueActions agree, so one of them is answering the wrong question');
    }

    /** Grouped by name and by group, which is what the Actions report draws. */
    public function testActionsGroupByNameAndGroup(): void
    {
        foreach (array(
            'actionName'  => array('submit' => 3, 'cancel' => 1),
            'actionGroup' => array('signup' => 3, 'commerce' => 1),
        ) as $dimension => $expected) {

            $this->assertSame($this->sorted($expected), $this->grouped('actions', $dimension),
                "$dimension grouped the actions wrongly");
        }
    }

    /**
     * The handler LOWERCASES what it stores, and the reports show what it
     * stored.
     *
     * Seeded as 'Signup' and 'Commerce'; a report that showed them capitalised
     * would mean something re-cased them on the way out, and two spellings of
     * one group would then be two rows.
     */
    public function testNamesAndGroupsAreStoredLowercased(): void
    {
        $rows = $this->query('actions', 'actionGroup');

        foreach ($rows as $row) {

            $group = (string) $row['actionGroup'];

            $this->assertSame(strtolower($group), $group,
                "the action group '$group' came back with capitals, so it was not normalised "
                . 'on the way in and two spellings would report as two groups');
        }
    }

    /** actionLabel resolves too -- it is the whole of the Action Detail report. */
    public function testActionLabelResolves(): void
    {
        $rows = $this->query('actions,actionsValue', 'actionLabel');

        $got = array();

        foreach ($rows as $row) {
            $got[(string) $row['actionLabel']] = (int) $row['actionsValue'];
        }

        // form-a carries 5 + 5 + 2; cart carries 10.
        $this->assertSame(12, $got['form-a'] ?? null);
        $this->assertSame(10, $got['cart'] ?? null);
    }
}
