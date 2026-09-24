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

        $rows = [
            ['page_view',     '/one'],
            ['page_view',     '/one'],
            ['page_view',     '/two'],
            ['session_start', '/one'],
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
                'id'         => 900000 + $i,
                'event_type' => $row[0],
                'site_id'    => self::SITE,
                'visitor_id' => self::VISITOR,
                'session_id' => self::SESSION,
                'ts'         => $ts + $i,
                'yyyymmdd'   => $day,
                'page_path'  => $row[1],
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

        $this->assertSame(4, (int) $rs->aggregates['eventCount']['value'],
            'the fixture holds four events');

        $byName = [];
        $sum    = 0;

        foreach ((array) $rs->resultsRows as $row) {

            $byName[$row['eventName']['value']] = (int) $row['eventCount']['value'];
            $sum += (int) $row['eventCount']['value'];
        }

        $this->assertSame(['page_view' => 3, 'session_start' => 1], $byName);

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

        $this->assertSame(['/one' => 3, '/two' => 1], $byPath,
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

        $declared = \OWA\Module\Base\Module::cubeColumnDimensions();

        $this->assertGreaterThan(40, count($declared),
            'the table must not be able to empty itself unnoticed');

        $missing = [];

        foreach ($declared as $name => $d) {

            if (!isset($columns[$d['column']])) {
                $missing[$name] = $d['column'];
            }
        }

        $this->assertSame([], $missing,
            'these dimensions name columns the cube does not have');
    }

    /** And each declaration carries what registerDimension() is given. */
    public function testEveryDeclarationIsComplete(): void
    {
        foreach (\OWA\Module\Base\Module::cubeColumnDimensions() as $name => $d) {

            foreach (['column', 'label', 'family', 'description'] as $key) {

                $this->assertArrayHasKey($key, $d, $name . ' is missing ' . $key);
                $this->assertNotSame('', (string) $d[$key], $name . ' has an empty ' . $key);
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
        foreach (['pageTitle', 'sessionMedium', 'deviceType', 'country', 'visitorId'] as $dim) {

            $rs = $this->manager('eventCount', $dim)->getResults();

            $this->assertSame([], (array) $rs->errors, $dim . ' did not resolve');

            $this->assertSame(4, (int) $rs->aggregates['eventCount']['value'],
                $dim . ' changed the total, so it is filtering rather than grouping');
        }
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
