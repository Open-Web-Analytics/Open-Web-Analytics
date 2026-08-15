<?php

use PHPUnit\Framework\TestCase;

/**
 * Partitioning a table, changing its granularity, and dropping old periods.
 *
 * The retention rule is the part worth guarding: only whole partitions are
 * dropped, so a partition straddling the cutoff is kept. Dropping it would
 * remove data on or after the cutoff -- more than was asked for -- which is why
 * the boundary actually reached is reported rather than assumed to equal the
 * one requested.
 */
final class PartitionOperationsTest extends TestCase
{
    /** @var string[] */
    private $tables = array();

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping partition test.');
        }

        if (!\OWA\Core\CoreAPI::dbSingleton()->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition; skipping.');
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->tables as $t) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $t));
        }

        $this->tables = array();
    }

    /** A table with the composite key partitioning requires. */
    private function makeTable()
    {
        $name = 'owa_test_part_' . bin2hex(random_bytes(4));
        $this->tables[] = $name;

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, PRIMARY KEY (id, yyyymmdd))',
            $name
        ));

        return $name;
    }

    private function partitionNames($table)
    {
        $names = array();

        foreach (\OWA\Core\CoreAPI::dbSingleton()->listPartitions($table) as $p) {
            $names[] = $p['name'];
        }

        return $names;
    }

    public function testPartitionAndDropAPeriod()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $this->assertFalse($db->isPartitioned($t));

        $ok = $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260315, 'monthly'));
        $this->assertTrue($ok, 'partitioning should succeed');
        $this->assertTrue($db->isPartitioned($t));

        // A malformed date has no lower bound to fall below, so it lands in the
        // first partition rather than being rejected.
        $db->query(sprintf('INSERT INTO %s VALUES (1,20260103),(2,20260210),(3,20260315),(4,0)', $t));
        $this->assertSame(4, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n']);

        $this->assertTrue($db->dropPartition($t, 'p20260101'));
        $this->assertSame(2, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'the January rows should be gone');
    }

    /** The primary key is widened where it does not already carry the column. */
    public function testPartitioningWidensThePrimaryKey()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $name = 'owa_test_part_' . bin2hex(random_bytes(4));
        $this->tables[] = $name;

        $db->query(sprintf('CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, PRIMARY KEY (id))', $name));
        $this->assertSame(array('id'), $db->getPrimaryKeyColumns($name));

        $this->assertTrue($db->partitionTable($name, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260131, 'monthly')));
        $this->assertSame(array('id', 'yyyymmdd'), $db->getPrimaryKeyColumns($name));
    }

    /** Changing granularity rewrites one month at a time, and is a no-op when already correct. */
    public function testRepartitionIsPerMonthAndIdempotent()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260315, 'monthly'));
        $db->query(sprintf('INSERT INTO %s VALUES (1,20260103),(2,20260210),(3,20260315)', $t));

        $result = $db->repartitionTable($t, 'half-month');

        $this->assertCount(3, $result['changed'], 'one rewrite per month, not one for the table');
        $this->assertEmpty($result['failed']);
        $this->assertSame(3, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'rows must survive');

        $again = $db->repartitionTable($t, 'half-month');
        $this->assertEmpty($again['changed'], 're-running must change nothing');
        $this->assertGreaterThan(0, $again['skipped']);
    }

    /** Only whole partitions are droppable; one straddling the cutoff is kept. */
    public function testRetentionKeepsAStraddlingPartition()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20251101, 20260315, 'monthly'));

        // Mid-January: December and earlier can go, January cannot.
        $plan = $db->getDroppablePartitions($t, 20260115);

        $this->assertSame(array('p20251101', 'p20251201'), $plan['drop']);
        $this->assertSame('20260101', $plan['effective'], 'the boundary reached, not the one asked for');
        $this->assertNotNull($plan['straddling'], 'the partition holding both sides should be reported');
        $this->assertSame('p20260101', $plan['straddling']['name']);

        // On a boundary, nothing straddles and the cutoff is reached exactly.
        $exact = $db->getDroppablePartitions($t, 20260201);
        $this->assertSame('20260201', $exact['effective']);
        $this->assertNull($exact['straddling']);

        // Before all data, nothing is droppable.
        $none = $db->getDroppablePartitions($t, 20250101);
        $this->assertSame(array(), $none['drop']);
        $this->assertNull($none['effective']);
    }

    /** The catch-all is never droppable: it has no upper bound and holds current traffic. */
    public function testCatchAllIsNeverDroppable()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260131, 'monthly'));

        $plan = $db->getDroppablePartitions($t, 29991231);

        $this->assertNotContains('pmax', $plan['drop']);
        $this->assertContains('pmax', $this->partitionNames($t));
    }

    /**
     * A range converts only the periods it touches, which is what allows one
     * table to be coarse for old data and fine for recent. Converting
     * a whole table finely would give years of history a partition -- and so a
     * file -- for every period.
     */
    public function testRepartitionHonoursARange()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260415, 'monthly'));
        $db->query(sprintf('INSERT INTO %s VALUES (1,20260105),(2,20260205),(3,20260305),(4,20260405)', $t));

        $result = $db->repartitionTable($t, 'quarter-month', false, '20260301', '20260401');

        $this->assertCount(1, $result['changed'], 'only March should be rewritten');
        $this->assertEmpty($result['failed']);
        $this->assertSame(4, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'rows must survive');

        $names = $this->partitionNames($t);

        $this->assertContains('p20260101', $names, 'January must still be monthly');
        $this->assertContains('p20260201', $names, 'February must still be monthly');
        $this->assertContains('p20260401', $names, 'April must be untouched');
        $this->assertContains('p20260322', $names, 'March must now be quarter-month');
    }

    /** A range matching no partition changes nothing rather than everything. */
    public function testRangeOutsideTheDataIsANoOp()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260131, 'monthly'));
        $before = $this->partitionNames($t);

        $result = $db->repartitionTable($t, 'quarter-month', false, '20200101', '20200201');

        $this->assertEmpty($result['changed']);
        $this->assertSame($before, $this->partitionNames($t));
    }

    /**
     * The planned count is what the budget guard judges, so it must describe
     * the whole table -- the converted span plus whatever the range left alone.
     */
    public function testRepartitionReportsThePlannedCount()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        // Three months, monthly.
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20260101, 20260315, 'monthly'));

        // Converting only March: four parts for March, plus January and February.
        $plan = $db->repartitionTable($t, 'quarter-month', true, '20260301', '20260401');

        $this->assertSame(6, $plan['planned'], 'March in four parts, plus January and February');

        // Whole table to half-month: two parts for each of three months.
        $all = $db->repartitionTable($t, 'half-month', true);

        $this->assertSame(6, $all['planned']);
    }

    /**
     * A cutoff in the future keeps the current period rather than wiping the
     * table. Asking to drop everything older than a date years ahead is a
     * mistyped year; taken literally it would discard data being written right
     * now, since every bounded partition precedes it.
     */
    public function testFutureCutoffIsClampedToToday()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $today = date('Ymd');
        $month = substr($today, 0, 6) . '01';

        // Monthly, with boundaries current through today.
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime($month . ' -4 months')), $today, 'monthly'
        ));

        $db->query(sprintf(
            'INSERT INTO %s VALUES (1,%s),(2,%s)',
            $t, date('Ymd', strtotime($month . ' -2 months')), $today
        ));

        $plan = $db->getDroppablePartitions($t, 29990101);

        $this->assertSame('29990101', $plan['requested'], 'the untouched request should be reported');
        $this->assertNotContains('p' . $month, $plan['drop'], 'the current period must survive');
        $this->assertSame('p' . $month, $plan['straddling']['name']);

        foreach ($plan['drop'] as $p) {
            $db->dropPartition($t, $p);
        }

        $this->assertSame(1, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], "today's row must survive");
        $this->assertContains('p' . $month, $this->partitionNames($t));
    }

    /** A cutoff in the past is taken at face value. */
    public function testPastCutoffIsNotClamped()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20240101, 20240415, 'monthly'));

        $this->assertNull($db->getDroppablePartitions($t, 20240301)['requested']);
    }

    /**
     * The lead is what keeps the catch-all empty, and so what keeps retention
     * able to reach the date it is given. Topping it up must converge: the
     * layout should depend on the date, not on how many times this has run.
     */
    public function testExtendingTheLeadIsIdempotent()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $month = date('Ym') . '01';

        // A table partitioned months ago and never topped up: recent writes
        // have piled into the catch-all, where retention cannot reach them.
        $start = date('Ymd', strtotime($month . ' -6 months'));
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges($start, $start, 'monthly'));
        $db->query(sprintf('INSERT INTO %s VALUES (1,%s),(2,%s)', $t, $start, date('Ymd')));

        $this->assertSame(1, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t PARTITION (pmax)")['n'],
            'the recent row should be stuck in the catch-all');

        $through = \OWA\Core\Db::partitionLeadBoundary();

        $first = $db->extendPartitions($t, 'monthly', $through);

        $this->assertNotEmpty($first['added']);
        $this->assertFalse($first['covered']);
        $this->assertSame(2, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'no rows may be lost');
        $this->assertSame(0, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t PARTITION (pmax)")['n'],
            'the catch-all should now be empty');

        $count = count($this->partitionNames($t));

        // Running again changes nothing.
        $second = $db->extendPartitions($t, 'monthly', $through);

        $this->assertTrue($second['covered']);
        $this->assertEmpty($second['added']);
        $this->assertCount($count, $this->partitionNames($t));

        // And what was trapped in the catch-all is now droppable.
        $cutoff = date('Ymd', strtotime($month . ' -1 month'));
        $this->assertNotEmpty($db->getDroppablePartitions($t, $cutoff)['drop']);
    }

    /** The lead counts whole future months from the start of this one. */
    public function testLeadBoundary()
    {
        $month = date('Ym') . '01';

        $this->assertSame(
            date('Ymd', strtotime($month . ' +13 months')),
            \OWA\Core\Db::partitionLeadBoundary(12),
            'twelve future months, plus the current one'
        );

        $this->assertSame(
            date('Ymd', strtotime($month . ' +4 months')),
            \OWA\Core\Db::partitionLeadBoundary(3)
        );
    }

    /** A table already reaching past the target is left alone. */
    public function testExtendIsANoOpWhenAlreadyCovered()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $through = \OWA\Core\Db::partitionLeadBoundary();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd'), date('Ymd', strtotime($through . ' -1 day')), 'monthly'
        ));

        $before = $this->partitionNames($t);
        $result = $db->extendPartitions($t, 'monthly', $through);

        $this->assertTrue($result['covered']);
        $this->assertSame($through, $result['top'], 'the lead should already be exactly met');
        $this->assertSame($before, $this->partitionNames($t));
    }
}
