<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * Reporting reads a Property's cube.
 *
 * THE PROBLEM THIS SOLVES. There is no `owa_event`: one cube per Property, so
 * `Entity\Event` is a shape whose getTableName() throws until something says
 * which Property. The reporting layer resolves a table by asking an entity for
 * its name, and it builds entities in seven places -- so the binding has to
 * happen somewhere every one of them goes through, from something the request
 * already carries. That is `ResultSetManager`, and a siteId.
 *
 * WHAT IS ASSERTED. That the right cube is read, that an unresolvable Property
 * fails LOUDLY rather than reading the wrong one, that a non-cube entity is
 * untouched, and that a query returns the numbers the table itself holds. The
 * fixture is built rather than looked for: a cube only exists once a Property
 * has collected something, and a test that skipped without one would be silent
 * on the install that has none.
 */
final class CubeReportingTest extends TestCase
{
    const SITE     = 'owa-cube-reporting-site';
    const PROPERTY = 7775000000000077;

    const VISITOR = 7775100000000077;
    const SESSION = 8885100000000077;

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();

        $property = owa_coreAPI::entityFactory('base.property');
        $property->setProperties([
            'id'            => self::PROPERTY,
            'name'          => 'Cube reporting fixture',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ]);

        if (!$property->create()) {
            throw new \RuntimeException('seeding owa_property failed');
        }

        $site = owa_coreAPI::entityFactory('base.site');
        $site->setProperties([
            'id'          => self::PROPERTY * 10,
            'site_id'     => self::SITE,
            'property_id' => self::PROPERTY,
            'name'        => 'Cube reporting fixture profile',
            'domain'      => 'example.test',
        ]);

        if (!$site->create()) {
            throw new \RuntimeException('seeding owa_site failed');
        }

        if (!Cubes::create(self::PROPERTY)) {
            throw new \RuntimeException('creating the fixture cube failed');
        }

        self::seedRows();
    }

    public static function tearDownAfterClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropFixture();
    }

    /**
     * Four events on one day: three page views and a session_start.
     *
     * Written straight into the cube rather than collected and built. What is
     * under test is the READ path, and a build would make the fixture depend on
     * ingest, the pass and the partition swap to assert a GROUP BY.
     */
    private static function seedRows(): void
    {
        $day = (int) date('Ymd');
        $ts  = time() * 1000000;

        /*
         * Two visitors, one of them returning, and engagement on every row --
         * so the metrics have something to distinguish. An all-identical
         * fixture makes visits, uniqueVisitors and newVisitors agree by
         * accident and proves none of them.
         */
        $rows = [
            // event_type,      path,   visitor,           session,           prior, msec, revenue
            ['page_view',     '/one', self::VISITOR,     self::SESSION,     0, 100, null],
            ['page_view',     '/one', self::VISITOR,     self::SESSION,     0, 250, null],
            ['page_view',     '/two', self::VISITOR,     self::SESSION,     0, 400, null],
            ['session_start', '/one', self::VISITOR,     self::SESSION,     0,   0, null],
            ['page_view',     '/two', self::VISITOR + 1, self::SESSION + 1, 3, 750, null],
            ['click',         '/two', self::VISITOR + 1, self::SESSION + 1, 3,   0, null],

            /*
             * Two purchases, BOTH in one of the two sessions. That asymmetry is
             * the point: transactions (2) differs from the sessions that
             * converted (1), so a conversion rate computed against the wrong
             * denominator gives 200% instead of 50% and the fixture says which.
             * Revenue differs between them so a sum is not a doubled single.
             */
            ['purchase',      '/buy', self::VISITOR + 1, self::SESSION + 1, 3,   0, 2500],
            ['purchase',      '/buy', self::VISITOR + 1, self::SESSION + 1, 3,   0, 1500],
        ];

        foreach ($rows as $i => $row) {

            /*
             * Through the ENTITY, not a hand-written INSERT. The cube declares
             * NOT NULL columns with no default -- `source` is the first of them
             * -- and under STRICT_ALL_TABLES a statement omitting one is
             * refused outright. applyStorageDefault() is what fills them, and
             * it runs on the entity's write path.
             */
            $event = Cubes::entityFor(self::PROPERTY);

            $event->setProperties([
                'id'              => 900000 + $i,
                'event_type'      => $row[0],
                'site_id'         => self::SITE,
                'visitor_id'      => $row[2],
                'session_id'      => $row[3],
                'prior_sessions'  => $row[4],
                'engagement_msec' => $row[5],
                'ts'              => $ts + $i,
                'yyyymmdd'        => $day,
                'page_path'       => $row[1],
                'revenue'         => $row[6],
            ]);

            if (!$event->create()) {
                throw new \RuntimeException(sprintf(
                    'seeding the fixture cube failed (%s): %s', $row[0],
                    owa_coreAPI::dbSingleton()->lastQueryError()));
            }
        }
    }

    private static function dropFixture(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE site_id = '%s'",
            owa_coreAPI::entityFactory('base.site')->getTableName(), $db->prepare(self::SITE)));

        $db->query(sprintf('DELETE FROM %s WHERE id = %d',
            owa_coreAPI::entityFactory('base.property')->getTableName(), self::PROPERTY));

        foreach (['', '_rebuild', '_computed'] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', Cubes::tableFor(self::PROPERTY), $suffix));
        }
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads a real cube.');
        }
    }

    /**
     * The vocabulary as the FILE declares it.
     *
     * Read from the config file rather than from the registry, because the file
     * is the artifact under test -- a column that is not on the cube has to be
     * caught in what someone wrote, not in what survived registration.
     */
    private static function declaredDimensions(): array
    {
        $declaration = include OWA_DIR . 'modules/Base/config/dimensions.php';

        return (array) $declaration['dimensions'];
    }

    /** A manager set up to read the fixture's day. */
    private function manager(string $metrics, string $dimensions, string $siteId = self::SITE)
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray($metrics);
        $rsm->setDimensions($rsm->dimensionsStringToArray($dimensions));
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId($siteId);
        $rsm->setLimit(25);

        return $rsm;
    }

    private function entityFor($rsm, string $name)
    {
        $m = new ReflectionMethod($rsm, 'entityFor');
        $m->setAccessible(true);

        return $m->invoke($rsm, $name);
    }

    /**
     * The binding itself: the cube entity comes back naming THIS site's
     * Property's table, from nothing but the siteId on the query.
     */
    public function testTheCubeEntityIsBoundToThePropertyTheQueryNames(): void
    {
        $entity = $this->entityFor($this->manager('eventCount', 'eventName'), 'base.event');

        $this->assertSame(Cubes::tableFor(self::PROPERTY), $entity->getTableName());
        $this->assertSame((string) self::PROPERTY, (string) $entity->getPropertyId());
    }

    /**
     * An unresolvable Property leaves it UNBOUND, so the next getTableName()
     * throws. A wrong table would be answered silently; this cannot be.
     */
    public function testAnUnknownSiteLeavesTheEntityUnboundRatherThanGuessing(): void
    {
        $entity = $this->entityFor(
            $this->manager('eventCount', 'eventName', 'owa-no-such-site-at-all'), 'base.event');

        $this->expectException(\RuntimeException::class);
        $entity->getTableName();
    }

    /** An entity that is not a cube is returned exactly as it was built. */
    public function testANonCubeEntityIsUntouched(): void
    {
        $entity = $this->entityFor($this->manager('pageViews', 'pagePath'), 'base.request');

        $this->assertSame(
            owa_coreAPI::entityFactory('base.request')->getTableName(),
            $entity->getTableName());
    }

    /**
     * The alias does NOT vary with the Property, and must not.
     *
     * Metrics and dimensions build their column references at registration,
     * from their own entity instance, before any Property is known -- so a
     * per-Property alias would make every one of those references wrong for
     * every Property but one.
     */
    public function testTheAliasIsTheSameWhicheverPropertyIsBound(): void
    {
        $a = owa_coreAPI::entityFactory('base.event');
        $b = owa_coreAPI::entityFactory('base.event');

        $unbound = $a->getTableAlias();

        $a->bindToProperty(self::PROPERTY);
        $b->bindToProperty(self::PROPERTY + 1);

        $this->assertSame($unbound, $a->getTableAlias(),
            'binding must not change the alias');

        $this->assertSame($a->getTableAlias(), $b->getTableAlias(),
            'two Properties must be read under the same alias');

        $this->assertNotSame($a->getTableName(), $b->getTableName(),
            'while still naming different tables -- which is the point');
    }

    /**
     * End to end: the numbers a query returns are the ones in the table.
     */
    public function testAQueryOverTheCubeReturnsWhatTheTableHolds(): void
    {
        $rs = $this->manager('eventCount', 'eventName')->getResults();

        $this->assertSame([], (array) $rs->errors);

        $this->assertSame(8, (int) $rs->aggregates['eventCount']['value'],
            'the fixture holds six events');

        $byName = [];
        $sum    = 0;

        foreach ((array) $rs->resultsRows as $row) {

            $byName[$row['eventName']['value']] = (int) $row['eventCount']['value'];
            $sum += (int) $row['eventCount']['value'];
        }

        /*
         * SORTED, because the query asks for no order and two of these counts
         * tie. Row order is then the server's to choose and it differs between
         * drivers -- asserting it would be asserting something the query never
         * promised. What is under test is the grouping, not the ordering.
         */
        ksort($byName);

        $this->assertSame(['click' => 1, 'page_view' => 4, 'purchase' => 2, 'session_start' => 1], $byName);

        $this->assertSame((int) $rs->aggregates['eventCount']['value'], $sum,
            'the breakdown must sum to its total');
    }

    /**
     * A name v1 also registers resolves against the CUBE.
     *
     * v1 registers `pagePath` normalised against base.document -- a join to a
     * document table keyed by `uri`. The cube's is denormalised, because the
     * path is a column on the event row. The name is therefore registered in
     * both shapes, and the unscoped accessor answers with the cube's.
     *
     * That is the direction rather than a collision: v2's dimensions replace
     * v1's, because v1's history is migrated into v2's raw store and no v1
     * reporting path survives it. There is no v1 registry to keep working.
     */
    public function testANameV1AlsoRegistersResolvesAgainstTheCube(): void
    {
        $rs = $this->manager('eventCount', 'pagePath')->getResults();

        $this->assertSame([], (array) $rs->errors);

        $byPath = [];

        foreach ((array) $rs->resultsRows as $row) {

            $byPath[$row['pagePath']['value']] = (int) $row['eventCount']['value'];
        }

        // Sorted for the same reason as above: both counts are 3, the query
        // orders by nothing, and the two drivers returned the tie differently.
        ksort($byPath);

        $this->assertSame(['/buy' => 2, '/one' => 3, '/two' => 3], $byPath,
            'the cube answers, with its own column and no join');

        $this->assertSame('base.event',
            owa_coreAPI::getAllDimensions()['pagePath']['entity'] ?? null,
            'and the unscoped accessor answers with it too');
    }

    /**
     * The two names the ENGINE requires do resolve against the cube, because
     * without them it refuses every cube query.
     */
    public function testTheCubeCarriesTheDimensionsEveryQueryConstrainsOn(): void
    {
        $registry = owa_coreAPI::serviceSingleton();

        $r = new ReflectionObject($registry);
        $p = $r->getProperty('denormalizedDimensions');
        $p->setAccessible(true);

        $denormalized = (array) $p->getValue($registry);

        foreach (array('date', 'siteId', 'eventName') as $name) {

            $this->assertArrayHasKey('base.event', (array) ($denormalized[$name] ?? []),
                $name . ' must be registered against the cube');
        }

        $this->assertSame('yyyymmdd', $denormalized['date']['base.event']['data_type'],
            "the cube's date must declare the same type v1's does, or it formats differently");
    }

    /**
     * EVERY declared dimension names a column the cube actually has.
     *
     * The one that matters, and it matters because the failure is SILENT.
     * Measured with a column name deliberately misspelled: the result set
     * carries NO error, the aggregate is still right, and the breakdown comes
     * back with zero rows -- MySQL refuses the dimensional query with "Unknown
     * column", and that refusal is swallowed. The report renders an empty grid
     * under a correct total, on whichever screen happens to ask for it.
     *
     * Checked against the real table rather than against the entity's
     * declaration, because the table is what the query hits.
     */
    public function testEveryDeclaredDimensionNamesARealCubeColumn(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $columns = [];

        foreach ((array) $db->get_results(
                     sprintf('SHOW COLUMNS FROM %s', Cubes::tableFor(self::PROPERTY))) as $row) {

            $columns[$row['Field']] = true;
        }

        $declared = self::declaredDimensions();

        $this->assertGreaterThan(40, count($declared),
            'the table must not be able to empty itself unnoticed');

        $missing = [];
        $joined  = 0;

        foreach ($declared as $name => $d) {

            // A dimension is either a column or several columns joined, and
            // EVERY part of a joined one has to be a real column too -- the
            // expression is built from them without anything checking.
            if (isset($d['parts'])) {

                $joined++;

                foreach ((array) $d['parts'] as $part) {

                    if (!isset($columns[$part])) {
                        $missing[$name . '.' . $part] = $part;
                    }
                }

                continue;
            }

            if (!isset($columns[$d['column']])) {
                $missing[$name] = $d['column'];
            }
        }

        $this->assertSame([], $missing,
            'these dimensions name columns the cube does not have');

        $this->assertGreaterThan(0, $joined,
            'the joined form must stay exercised here, not merely supported');
    }

    /** And each declaration carries what registerDimension() is given. */
    public function testEveryDeclarationIsComplete(): void
    {
        foreach (self::declaredDimensions() as $name => $d) {

            $required = isset($d['parts']) ? ['parts'] : ['column'];

            foreach (array_merge($required, ['label', 'family', 'description']) as $key) {

                $this->assertArrayHasKey($key, $d, $name . ' is missing ' . $key);
                $this->assertNotEmpty($d[$key], $name . ' has an empty ' . $key);
            }

            // The two forms are alternatives. A declaration carrying both says
            // one thing to the registry and another to a reader.
            if (isset($d['parts'])) {

                $this->assertArrayNotHasKey('column', $d,
                    $name . ' declares both parts and a column; only parts is read.');

                $this->assertGreaterThan(1, count((array) $d['parts']),
                    $name . ' joins fewer than two columns, so it is just a column.');
            } else {

                $this->assertArrayNotHasKey('separator', $d,
                    $name . ' declares a separator but nothing to separate.');
            }
        }
    }

    /**
     * They resolve in a real query, not merely in the registry.
     *
     * One per family, because the families differ in nothing the query builder
     * sees -- what is being checked is that a registered name reaches the right
     * column with the cube bound underneath it.
     */
    public function testTheColumnDimensionsResolveInAQuery(): void
    {
        foreach (['pageTitle', 'sessionMedium', 'deviceType', 'country', 'clientId'] as $dim) {

            $rs = $this->manager('eventCount', $dim)->getResults();

            $this->assertSame([], (array) $rs->errors, $dim . ' did not resolve');

            $this->assertSame(8, (int) $rs->aggregates['eventCount']['value'],
                $dim . ' changed the total, so it is filtering rather than grouping');
        }
    }

    /**
     * A report can be FILTERED by a metric, not only sorted by one.
     *
     * Every v2 metric that counts a kind of event carries a condition, and the
     * SQL it generates for itself therefore contains a string literal:
     * `sum(CASE WHEN event.event_type = 'page_view' THEN 1 ELSE 0 END)`. That
     * expression is the operand of the HAVING clause, and it used to be run
     * through the value escaper on its way there, which turned every quote into
     * an escaped one and made the statement unparseable. So `pageViews > 1` --
     * the ordinary act of hiding the long tail of a report -- returned nothing
     * at all, on every conditional metric there is.
     *
     * It did not surface as a syntax error, which is why it lasted: the added
     * backslashes desynchronised the placeholder count and the driver's binding
     * guard refused the statement first.
     */
    public function testAReportCanBeFilteredByAConditionalMetric(): void
    {
        $rows = function (string $constraint): array {

            $rsm = $this->manager('pageViews', 'pagePath');
            $rsm->setConstraints($rsm->parseConstraintsString($constraint));

            $rs = $rsm->getResults();

            $this->assertSame([], (array) $rs->errors, $constraint . ' did not resolve');

            $out = [];

            foreach ($rs->getResultsRows() as $row) {
                $out[$row['pagePath']['value']] = (int) $row['pageViews']['value'];
            }

            return $out;
        };

        // The fixture: /one has two page views, /two has two, /buy has none.
        $this->assertSame(['/one' => 2, '/two' => 2], $rows('pageViews>1'));

        // A threshold nothing clears returns nothing -- which is the answer the
        // broken clause also gave, so the assertion above is what distinguishes
        // them and this one only guards the boundary.
        $this->assertSame([], $rows('pageViews>2'));

        // And the filter is on the metric, not on the rows it counts: /buy has
        // events but no page views, so it is absent above and present here.
        $this->assertArrayHasKey('/buy', $rows('eventCount>1'));
    }

    /**
     * The metrics compute what the fixture holds.
     *
     * Six events over two visitors -- one new with a four-event session, one
     * returning with two -- so every number below differs from its neighbours.
     * A fixture where they agreed would prove none of them.
     */
    public function testTheMetricsComputeWhatTheFixtureHolds(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray(
            'eventCount,pageViews,sessions,totalUsers,newUsers,totalEngagementTime');
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(25);

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors);

        $got = [];

        foreach ((array) $rs->aggregates as $name => $a) {
            $got[$name] = (int) $a['value'];
        }

        // Compared by name rather than by position, for the same reason.
        ksort($got);

        $expected = [
            'eventCount'        => 8,   // every row
            'pageViews'         => 4,   // event_type = page_view
            'sessions'            => 2,   // distinct session_id
            'totalUsers'    => 2,   // distinct visitor_id
            'newUsers'       => 1,   // prior_sessions = 0
            'totalEngagementTime' => 1500, // 100 + 250 + 400 + 750
        ];

        ksort($expected);

        $this->assertSame($expected, $got);
    }

    /**
     * A condition restricts the count WITHOUT restricting the query.
     *
     * The distinction that matters: `pageViews` must not filter rows out of the
     * result set, or every other metric beside it would be computed over the
     * filtered rows too. It is a conditional aggregate over the same scan, so
     * asking for it alongside `eventCount` must leave `eventCount` alone.
     */
    public function testAConditionNarrowsTheCountAndNotTheQuery(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViews,eventCount');
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(25);

        $rs = $rsm->getResults();

        $this->assertSame(4, (int) $rs->aggregates['pageViews']['value']);

        $this->assertSame(8, (int) $rs->aggregates['eventCount']['value'],
            'the condition on one metric must not narrow the rows the others see');
    }

    /**
     * The expression does NOT need a database connection.
     *
     * A metric's SELECT is a property of its definition, built once at
     * registration. Escaping the condition's value through the driver made it
     * depend on a live connection -- Mysql::prepare() calls
     * mysqli_real_escape_string( $this->connection, ... ) -- so with no
     * database it rendered `event_type = ''`: a metric that silently counts
     * nothing. CI's unit job has no database, which is where it surfaced.
     */
    public function testTheConditionRendersWithoutADatabaseConnection(): void
    {
        $metric = owa_coreAPI::metricFactory('base.configurableMetric', [
            'name' => 'zzProbe', 'label' => 'Probe', 'data_type' => 'integer',
            'metric_type' => 'count', 'entity' => 'base.event', 'column' => 'id',
            'condition' => ['column' => 'event_type', 'value' => 'page_view'],
        ]);

        $m = new ReflectionMethod($metric, 'renderCondition');
        $m->setAccessible(true);

        $this->assertStringContainsString("= 'page_view'", $m->invoke($metric),
            'the value must reach the statement without being escaped against a connection');
    }

    /**
     * A value that cannot be a literal is refused, and the metric goes MISSING
     * rather than counting every row.
     */
    public function testAValueThatCannotBeALiteralIsRefused(): void
    {
        $metric = owa_coreAPI::metricFactory('base.configurableMetric', [
            'name' => 'zzProbe', 'label' => 'Probe', 'data_type' => 'integer',
            'metric_type' => 'count', 'entity' => 'base.event', 'column' => 'id',
            'condition' => ['column' => 'event_type', 'value' => "x' OR '1'='1"],
        ]);

        $m = new ReflectionMethod($metric, 'renderCondition');
        $m->setAccessible(true);

        $this->assertSame('', $m->invoke($metric));

        [$statement] = $metric->getSelect();

        $this->assertNull($statement,
            'counting every row instead would be a metric answering a different question');
    }

    /** An operator that is not a comparison never reaches the statement. */
    public function testAnUnknownOperatorIsRefusedRatherThanInterpolated(): void
    {
        $metric = owa_coreAPI::metricFactory('base.configurableMetric', [
            'name' => 'zzProbe', 'label' => 'Probe', 'data_type' => 'integer',
            'metric_type' => 'count', 'entity' => 'base.event', 'column' => 'id',
            'condition' => ['column' => 'event_type', 'operator' => ') OR 1=1 --', 'value' => 'x'],
        ]);

        $m = new ReflectionMethod($metric, 'renderCondition');
        $m->setAccessible(true);

        $sql = $m->invoke($metric);

        $this->assertStringNotContainsString('OR 1=1', $sql,
            'the operator is matched against a list; it is never interpolated');

        $this->assertStringContainsString('=', $sql);
    }

    /**
     * A ratio divides two metrics, and says so without an expression.
     *
     * Six pageviews over two sessions and two visitors, so the three ratios
     * differ from each other and from 1 -- a fixture where they agreed would
     * prove none of them.
     */
    public function testARatioDividesItsTwoSides(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray(
            'pageViews,sessions,totalUsers,eventCount,pageViewsPerSession,sessionsPerUser,eventsPerSession');
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(25);

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors);

        // 4 page views, 2 sessions, 2 visitors, 8 events
        $this->assertSame(2.0, (float) $rs->aggregates['pageViewsPerSession']['value'],  '4 / 2');
        $this->assertSame(1.0, (float) $rs->aggregates['sessionsPerUser']['value'], '2 / 2');
        $this->assertSame(4.0, (float) $rs->aggregates['eventsPerSession']['value'], '8 / 2');
    }

    /**
     * The commerce metrics compute what the fixture holds.
     *
     * TWO PURCHASES IN ONE OF THE TWO SESSIONS, deliberately. If both numbers
     * agreed, a conversion rate computed against the wrong denominator would
     * pass: 2 transactions over 2 sessions is 100% whichever way you read it.
     * With the purchases in one session, transactions (2) and converting
     * sessions (1) differ, and 2/2 = 100% is visibly wrong against the 50% a
     * per-session rate would give.
     *
     * The rate here is transactions/visits, which is 100% -- TWO purchases
     * against TWO visits. That is what the metric is declared to mean, and it
     * is GA's shape too; a "share of sessions that converted" is a different
     * metric and would need a distinct count of converting sessions.
     */
    public function testTheCommerceMetricsComputeWhatTheFixtureHolds(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray(
            'transactions,transactionRevenue,revenuePerTransaction,revenuePerSession,ecommerceConversionRate');
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(25);

        $rs = $rsm->getResults();

        $this->assertSame([], (array) $rs->errors);

        $this->assertSame(2, (int) $rs->aggregates['transactions']['value'],
            'two purchase rows');

        // 2500 + 1500, in minor units. Different amounts, so a sum cannot pass
        // by doubling one of them.
        $this->assertSame(4000, (int) $rs->aggregates['transactionRevenue']['value']);

        $this->assertSame(2000.0, (float) $rs->aggregates['revenuePerTransaction']['value'],
            '4000 / 2');

        $this->assertSame(2000.0, (float) $rs->aggregates['revenuePerSession']['value'],
            '4000 / 2 visits');

        $this->assertSame(1.0, (float) $rs->aggregates['ecommerceConversionRate']['value'],
            '2 transactions / 2 visits');
    }

    /**
     * Revenue is summed only over PURCHASES, not over every row.
     *
     * The condition is the whole point: `sum(revenue)` unconditioned happens to
     * be right today only because nothing else writes that column, which is a
     * property of the data rather than a statement of what the metric means.
     */
    public function testRevenueIgnoresRowsThatAreNotPurchases(): void
    {
        $db    = owa_coreAPI::dbSingleton();
        $table = Cubes::tableFor(self::PROPERTY);

        // Put revenue on a PAGE VIEW, which no purchase metric should see.
        $db->query(sprintf(
            "UPDATE %s SET revenue = 9999 WHERE event_type = 'page_view' LIMIT 1", $table));

        try {
            $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
            $rsm->metrics = $rsm->metricsStringToArray('transactionRevenue');
            $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
            $rsm->setSiteId(self::SITE);

            $rs = $rsm->getResults();

            $this->assertSame(4000, (int) $rs->aggregates['transactionRevenue']['value'],
                'a page view carrying revenue must not reach a purchase metric');
        } finally {
            $db->query(sprintf(
                "UPDATE %s SET revenue = NULL WHERE event_type = 'page_view'", $table));
        }
    }

    /**
     * Precision rounds, and a ratio without one does not.
     *
     * Driven directly because the fixture's ratios divide exactly -- 4/2 and
     * 6/2 round to themselves, so a declared precision that was being ignored
     * would change none of them. A value that needs rounding is the only thing
     * that tests rounding.
     */
    public function testPrecisionRoundsAndItsAbsenceDoesNot(): void
    {
        $rounded = owa_coreAPI::metricFactory('base.configurableMetric', [
            'name' => 'zzRounded', 'label' => 'R', 'data_type' => 'decimal',
            'metric_type' => 'ratio', 'entity' => 'base.event',
            'numerator' => 'eventCount', 'denominator' => 'sessions', 'precision' => 2,
        ]);

        $this->assertSame(0.33, $rounded->computeRatio(1, 3));
        $this->assertSame(66.67, $rounded->computeRatio(200, 3));

        $exact = owa_coreAPI::metricFactory('base.configurableMetric', [
            'name' => 'zzExact', 'label' => 'E', 'data_type' => 'decimal',
            'metric_type' => 'ratio', 'entity' => 'base.event',
            'numerator' => 'eventCount', 'denominator' => 'sessions',
        ]);

        $this->assertEqualsWithDelta(1 / 3, $exact->computeRatio(1, 3), 0.0000001,
            'without a declared precision the division is not rounded at all');

        // And a zero numerator is an ordinary zero, not the absent case.
        $this->assertSame(0.0, $rounded->computeRatio(0, 3));
    }

    /**
     * A zero denominator is NULL, not zero.
     *
     * "No sessions, so pages-per-session is not a number" and "pages-per-session
     * is zero" are different answers, and only one of them is true. The formula
     * path returns 0 for both, which is the behaviour this kind exists to stop
     * inheriting.
     */
    public function testAZeroDenominatorIsAbsentRatherThanZero(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('sessions,pageViewsPerSession');
        // A day the fixture wrote nothing on.
        $rsm->setTimePeriod('date_range', '20200101', '20200101');
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(5);

        $rs = $rsm->getResults();

        $this->assertSame(0, (int) $rs->aggregates['sessions']['value']);

        $this->assertNull($rs->aggregates['pageViewsPerSession']['value'],
            'a ratio with nothing to divide by has no value, and 0 would be a claim');
    }

    /**
     * The SORT is rendered in SQL, because it has to be.
     *
     * Computing the value in PHP and sorting that would order the page rather
     * than the result -- LIMIT is applied by the server, before PHP sees a row.
     */
    public function testARatioSortsInSqlRatherThanInPhp(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('pageViewsPerSession');
        $rsm->setDimensions($rsm->dimensionsStringToArray('pagePath'));
        $rsm->setSorts($rsm->sortStringToArray('pageViewsPerSession-'));
        $rsm->setTimePeriod('date_range', date('Ymd'), date('Ymd'));
        $rsm->setSiteId(self::SITE);
        $rsm->setLimit(5);

        /*
         * Generating the set is what chooses the entity the children resolve
         * against; applySorts() is then called explicitly because the query
         * builder clears its parameters once it has produced a statement, so
         * there is nothing left to read afterwards.
         */
        $rsm->getResults();

        $sorts = new ReflectionMethod($rsm, 'applySorts');
        $sorts->setAccessible(true);
        $sorts->invoke($rsm);

        $db = new ReflectionProperty(\OWA\Module\Base\Classes\ResultSetManager::class, 'db');
        $db->setAccessible(true);

        $params = new ReflectionProperty(\OWA\Core\Db::class, '_sqlParams');
        $params->setAccessible(true);

        $orderBy = (array) ($params->getValue($db->getValue($rsm))['orderby'] ?? []);

        $this->assertNotEmpty($orderBy, 'the sort never reached the query');

        $column = (string) $orderBy[0][0];

        /*
         * The division itself is in the ORDER BY -- not the metric's alias, and
         * not a value PHP computed afterwards.
         */
        $this->assertStringContainsString('NULLIF', $column,
            'a zero denominator must be NULL in SQL too, or the sort errors where the value does not');

        $this->assertStringContainsString('COUNT', $column,
            "the children's own expressions must be rendered into the sort");

        $this->assertStringContainsString('round(', $column,
            'and the declared precision with them');

        $this->assertStringNotContainsString('pageViewsPerSession', $column,
            'sorting by the alias would sort on a column the query does not select');
    }

    /** The site-to-Property lookup, which is what the binding rests on. */
    public function testThePropertyLookupAnswersAndRefuses(): void
    {
        $this->assertSame((string) self::PROPERTY, Cubes::propertyIdForSite(self::SITE));

        $this->assertSame('', Cubes::propertyIdForSite('owa-no-such-site-at-all'),
            'an unknown site has no Property, and says so rather than guessing');

        $this->assertSame('', Cubes::propertyIdForSite(''));
    }
}
