<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Cubes;

/**
 * The map between a Property and its reporting cube.
 *
 * There is no owa_event. Everything that has to go from a Property to a table,
 * or back, comes through this class, so what it answers is what the partition
 * commands maintain and what a build writes.
 */
final class CubesTest extends TestCase
{
    /** A Property id that is not in the database and does not need to be. */
    const PROPERTY = 7777000000000001;

    public function testACubeIsNamedAfterItsProperty(): void
    {
        $this->assertSame('owa_event_' . self::PROPERTY, Cubes::tableFor(self::PROPERTY));
    }

    public function testTheNameRoundTripsBackToTheProperty(): void
    {
        // A cube's Property is readable off its name, so nothing has to query
        // to work out which table a build or a partition command is holding.
        $this->assertSame((string) self::PROPERTY,
            Cubes::propertyIdFor(Cubes::tableFor(self::PROPERTY)));
    }

    /**
     * owa_event_raw is not a cube, and cannot be read as one.
     *
     * The two names differ only in the suffix, and raw is partitioned on the
     * same column -- so a loose match would have the partition commands
     * maintaining a daily front on the one table that must never have one, and
     * a build treating it as a swap target.
     */
    public function testRawIsNotACube(): void
    {
        $this->assertSame('', Cubes::propertyIdFor('owa_event_raw'));
        $this->assertSame('', Cubes::propertyIdFor('owa_event_rebuild'));
        $this->assertSame('', Cubes::propertyIdFor('owa_event_7_extra'));
        $this->assertSame('', Cubes::propertyIdFor('owa_session'));
    }

    /** The pre-split table is not a cube either: it is what 041 removes. */
    public function testThePreSplitTableIsNotACube(): void
    {
        $this->assertSame('', Cubes::propertyIdFor(Cubes::preSplitTable()));
        $this->assertSame('owa_event', Cubes::preSplitTable());
    }

    public function testAnIdThatIsNotAPropertyGetsNoTable(): void
    {
        foreach (['', '0', 'raw', '-1', '1.5', 'DROP TABLE', '7 OR 1=1'] as $bad) {
            $this->assertSame('', Cubes::tableFor($bad), var_export($bad, true));
        }
    }

    /**
     * The shape is bound to the table, and knows which Property it is.
     */
    public function testTheEntityComesBackBoundToThatTable(): void
    {
        $entity = Cubes::entityFor(self::PROPERTY);

        $this->assertSame(Cubes::tableFor(self::PROPERTY), $entity->getTableName());
        $this->assertSame((string) self::PROPERTY, $entity->getPropertyId());
    }

    /**
     * An UNBOUND cube entity has no table, and says so rather than guessing.
     *
     * The trap this closes: Event extends EventRaw, so the name it inherits
     * from its parent's constructor is owa_event_RAW. Left in place, a caller
     * that forgot which Property it meant would get a real table back -- and
     * writing a cube's rows into raw is not a failure anything downstream
     * would notice.
     */
    public function testAnUnboundCubeRefusesToNameATable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('There is no owa_event');

        owa_coreAPI::entityFactory('base.event')->getTableName();
    }

    /** Every cube is the same shape, whichever Property it belongs to. */
    public function testEveryCubeHasTheSameColumnsAndTheSameLead(): void
    {
        $one = Cubes::entityFor(self::PROPERTY);
        $two = Cubes::entityFor(self::PROPERTY + 1);

        $this->assertSame($one->getColumns(), $two->getColumns());
        $this->assertSame($one->getDailyLeadMonths(), $two->getDailyLeadMonths());
        $this->assertSame($one->partitionsNeeded('monthly'), $two->partitionsNeeded('monthly'));

        $this->assertNotSame($one->getTableName(), $two->getTableName());
    }

    /**
     * Asking for a shape's columns must not need a table.
     *
     * A build composes its statement from the cube's column list before it has
     * decided anything about a table, and getColumns() used to resolve the
     * table name whether or not it was going to use it.
     */
    public function testTheShapesColumnsCanBeReadWithoutATable(): void
    {
        $columns = owa_coreAPI::entityFactory('base.event')->getColumns();

        $this->assertContains('is_exit', $columns);
        $this->assertContains('acq_source', $columns);
    }

    public function testTheCubeIsNotInTheEntityRegistry(): void
    {
        // Registering it would have Module::install() create owa_event on
        // every fresh installation, and the partition commands maintain a lead
        // on a table nothing writes to.
        $entities = \OWA\Core\CoreAPI::serviceSingleton()->modules['base']->getEntities();

        $this->assertNotContains('event', $entities,
            'the cube is a shape, not a table the module owns');
        $this->assertContains('event_raw', $entities,
            'raw IS one table per installation and stays registered');
    }

    public function testSiteIdsOfAPropertyThatHasNoneIsEmpty(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this reads owa_site.');
        }

        $this->assertSame([], Cubes::siteIds(self::PROPERTY));
    }

    /** A Property id has to be digits before it reaches a query. */
    public function testSiteIdsRefusesAnythingThatIsNotAnId(): void
    {
        $this->assertSame([], Cubes::siteIds("1; DROP TABLE owa_site"));
        $this->assertSame([], Cubes::siteIds('abc'));
    }

    /**
     * The partition commands recognise a cube BY NAME.
     *
     * This is what makes cmd=partition-rotate keep the daily front on it and
     * cmd=partition-reorganize leave that front alone. Both ask entityFor(),
     * and the cubes are not in the entity registry -- so if the name does not
     * resolve, every cube silently becomes an ordinary fact table and the next
     * rotate merges two months of daily partitions away.
     */
    public function testAPartitionCommandResolvesACubeFromItsName(): void
    {
        $entity = $this->resolve(Cubes::tableFor(self::PROPERTY));

        $this->assertNotNull($entity, 'a cube name must resolve to an entity');
        $this->assertSame(\OWA\Core\Db::CUBE_DAILY_MONTHS, $entity->getDailyLeadMonths(),
            'and to one that asks for a daily front');
        $this->assertSame(Cubes::tableFor(self::PROPERTY), $entity->getTableName());
    }

    /** And an ordinary fact table still resolves to itself. */
    public function testAnOrdinaryFactTableStillResolves(): void
    {
        $raw = $this->resolve('owa_event_raw');

        $this->assertNotNull($raw);
        $this->assertSame(0, $raw->getDailyLeadMonths(),
            'raw is one granularity throughout, and must not gain a daily front');
    }

    public function testANameThatIsNeitherResolvesToNothing(): void
    {
        $this->assertNull($this->resolve('owa_not_a_table'));
    }

    /** PartitionsCli::entityFor(), which every partition command inherits. */
    private function resolve(string $table)
    {
        $class = new ReflectionClass(\OWA\Module\Base\Controller\PartitionStatusCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $m = new ReflectionMethod($cli, 'entityFor');
        $m->setAccessible(true);

        return $m->invoke($cli, $table);
    }

    public function testExistingReadsTheDatabaseRatherThanThePropertyList(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this lists tables.');
        }

        // What the partition commands maintain is the tables that are there,
        // which after a Property is deleted is not the set of Properties.
        $db = owa_coreAPI::dbSingleton();

        foreach (Cubes::existing() as $property_id => $table) {
            $this->assertTrue($db->tableExists($table), "$table was listed but does not exist");
            $this->assertSame($table, Cubes::tableFor($property_id));
        }

        $this->assertNotContains(Cubes::preSplitTable(), Cubes::existing(),
            'owa_event is never a cube, even on an installation that still has it');
    }

    /**
     * And every one of them is a table the partition commands maintain.
     *
     * A cube has a lead like any other partitioned table -- it just has a daily
     * front as well -- so leaving them out of factTables() would leave them
     * with no lead to write into once the year ran out.
     */
    public function testEveryCubeIsAPartitionedTableTheCommandsMaintain(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this lists tables.');
        }

        $class = new ReflectionClass(\OWA\Module\Base\Controller\PartitionStatusCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $m = new ReflectionMethod($cli, 'factTables');
        $m->setAccessible(true);

        $tables = (array) $m->invoke($cli, null);

        foreach (Cubes::existing() as $table) {
            $this->assertContains($table, $tables);
        }

        $this->assertContains('owa_event_raw', $tables, 'raw is still one of them');
        $this->assertNotContains(Cubes::preSplitTable(), $tables,
            'and owa_event is not, so nothing maintains a lead on a table 041 removes');
    }
}
