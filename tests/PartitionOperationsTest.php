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

    /** Every scheme is recognisable from the boundaries it cuts on. */
    public function testGranularityIsInferredFromTheTable()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $month = date('Ym') . '01';

        foreach (array_keys(\OWA\Core\Db::PARTITION_CUTS) as $granularity) {

            $t = $this->makeTable();

            $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                date('Ymd', strtotime($month . ' -2 months')), date('Ymd'), $granularity
            ));

            $this->assertSame($granularity, $db->inferPartitionGranularity($t));
        }
    }

    /**
     * The point of inferring: a table converted to a finer granularity must
     * keep extending at it. Topping the lead up with the command's own default
     * would quietly undo the conversion on the next scheduled run.
     */
    public function testTheLeadKeepsTheTablesOwnGranularity()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();
        $month = date('Ym') . '01';

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            $month, date('Ymd', strtotime(\OWA\Core\Db::partitionLeadBoundary(3) . ' -1 day')), 'quarter-month'
        ));

        $db->extendPartitions($t, $db->inferPartitionGranularity($t), \OWA\Core\Db::partitionLeadBoundary());

        $this->assertSame('quarter-month', $db->inferPartitionGranularity($t),
            'the granularity must survive a top-up');

        // The final month must be cut into four, not left whole.
        $spans = $db->getPartitionSpans($t);
        $last  = substr($spans[count($spans) - 1]['start'], 0, 6);
        $parts = 0;

        foreach ($spans as $span) {
            if (substr($span['start'], 0, 6) === $last) {
                $parts++;
            }
        }

        $this->assertSame(4, $parts, 'the newest month should still be quartered');
    }

    /** A table coarse in history and finer recently follows its recent end. */
    public function testInferenceFollowsTheRecentEnd()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();
        $month = date('Ym') . '01';

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime($month . ' -6 months')), date('Ymd'), 'monthly'
        ));

        $db->repartitionTable($t, 'quarter-month', false, date('Ymd', strtotime($month . ' -1 month')), null);

        $this->assertSame('quarter-month', $db->inferPartitionGranularity($t));
    }

    /** An unpartitioned table has nothing to infer from. */
    public function testInferenceYieldsNullWhenThereIsNoScheme()
    {
        $this->assertNull(\OWA\Core\CoreAPI::dbSingleton()->inferPartitionGranularity($this->makeTable()));
    }

    /**
     * Rotation is the two halves of one policy: keep N months, stay a lead
     * ahead. The invariant afterwards is that the table covers the lead AND
     * holds nothing older than the cutoff -- and that running it again changes
     * nothing.
     *
     * Ordering matters. The lead is added before anything is dropped, so a run
     * that fails partway has gained coverage rather than discarded history and
     * then failed to create anywhere for new data to go.
     */
    public function testRotationExtendsAndPrunesAndSettles()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();
        $month = date('Ym') . '01';

        // Three years of history, and a lead that ran down long ago.
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime($month . ' -36 months')), date('Ymd'), 'monthly'
        ));

        $db->query(sprintf(
            'INSERT INTO %s VALUES (1,%s),(2,%s),(3,%s)',
            $t,
            date('Ymd', strtotime($month . ' -30 months')),   // outside a 24-month window
            date('Ymd', strtotime($month . ' -6 months')),    // inside it
            date('Ymd')                                       // today
        ));

        $through = \OWA\Core\Db::partitionLeadBoundary();
        $cutoff  = date('Ymd', strtotime('-24 months'));

        $rotate = function () use ($db, $t, $through, $cutoff) {
            $added = $db->extendPartitions($t, $db->inferPartitionGranularity($t), $through);
            $plan  = $db->getDroppablePartitions($t, $cutoff);
            foreach ($plan['drop'] as $p) {
                $db->dropPartition($t, $p);
            }
            return array(count($added['added']), count($plan['drop']));
        };

        list($added, $dropped) = $rotate();

        $this->assertGreaterThan(0, $added, 'the lead should have been extended');
        $this->assertGreaterThan(0, $dropped, 'stale history should have been dropped');

        // The row outside the window is gone; the two inside it are not.
        $this->assertSame(2, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n']);

        $spans = $db->getPartitionSpans($t);

        $this->assertSame($through, $spans[count($spans) - 1]['less_than'], 'must reach the lead');
        $this->assertGreaterThanOrEqual($cutoff, $spans[0]['less_than'] , 'nothing wholly older than the cutoff may remain');

        // Settled: a second rotation is a no-op.
        list($added2, $dropped2) = $rotate();

        $this->assertSame(0, $added2, 'nothing left to add');
        $this->assertSame(0, $dropped2, 'nothing left to drop');
        $this->assertSame(2, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n']);
    }

    /**
     * The bound used to prune per-session summaries must never exclude a row
     * that belongs to the session. It is a lower bound taken from the session's
     * own server-assigned date, so the only thing it can remove is a partition
     * the session cannot be in.
     */
    public function testFactDateRangeNeverExcludesTheSessionsOwnRows()
    {
        // Slack for queue lag: a session is dated when its event was processed,
        // so a delayed event can be dated after requests that belong to it.
        $slack = \OWA\Core\Db::FACT_LOWER_BOUND_SLACK_DAYS;

        foreach ([date('Ymd'), date('Ymd', strtotime('-6 months')), date('Ymd', strtotime('-2 years'))] as $date) {
            $range = \OWA\Core\Db::factDateRange($date);

            $this->assertSame(
                date('Ymd', strtotime("$date -$slack days")),
                $range['start'],
                "must subtract $slack days from $date across month, year and leap boundaries"
            );

            // The upper bound is today, not the session's date: rows are added
            // to a session as it runs, and a replayed row lands on the day it
            // was processed. It also prunes the lead, which a floor cannot.
            $this->assertSame(date('Ymd', strtotime('tomorrow')), $range['end'], 'closes at today');
            $this->assertGreaterThan($range['start'], $range['end']);
        }

        $this->assertGreaterThanOrEqual(2, $slack, 'must cover at least the anomaly observed in the field');

        // A session dated ahead of the server inverts the range. BETWEEN would
        // then match nothing, turning a summary into a zero rather than a slow
        // query, so no bound is applied at all.
        $this->assertNull(
            \OWA\Core\Db::factDateRange(date('Ymd', strtotime('+2 years'))),
            'a future-dated session must not produce an inverted range'
        );

        // Unusable dates yield no bound at all, so the caller queries unconstrained.
        foreach ([0, '0', '', null, 'garbage', '19700101', '2026081', '202608155'] as $bad) {
            $this->assertNull(\OWA\Core\Db::factDateRange($bad), var_export($bad, true) . ' should not bound');
        }
    }

    /** The bound prunes on a partitioned table and changes no result. */
    public function testFactDateRangePrunesWithoutChangingTheAnswer()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->query(sprintf('ALTER TABLE %s ADD session_id BIGINT, ADD KEY session_id (session_id)', $t));
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20250101, 20251231, 'monthly'));

        // One session on 15 June with three requests, one of them next day.
        $db->query(sprintf(
            'INSERT INTO %s (id, yyyymmdd, session_id) VALUES (1,20250615,77),(2,20250615,77),(3,20250616,77),(4,20250201,88)',
            $t
        ));

        $range = \OWA\Core\Db::factDateRange('20250615');

        $unbounded = $db->get_row("SELECT COUNT(DISTINCT id) AS n FROM $t WHERE session_id = 77");
        $bounded   = $db->get_row("SELECT COUNT(DISTINCT id) AS n FROM $t WHERE session_id = 77
                                   AND yyyymmdd BETWEEN {$range['start']} AND {$range['end']}");

        $this->assertSame(3, (int) $unbounded['n']);
        $this->assertSame((int) $unbounded['n'], (int) $bounded['n'], 'the bound must not change the count');

        // And it really does prune.
        $plan = $db->get_results("EXPLAIN SELECT COUNT(DISTINCT id) FROM $t WHERE session_id = 77
                                  AND yyyymmdd BETWEEN {$range['start']} AND {$range['end']}");
        $scanned = count(explode(',', $plan[0]['partitions']));
        $all     = count($db->getPartitionSpans($t));

        $this->assertLessThan($all, $scanned, 'the bound should reduce the partitions scanned');
    }

    /**
     * The id-derived range is a hint drawn from a clock we do not control, so
     * it must be usable only where a miss can fall back. These pin the shape
     * and the refusals; the fallback itself is at the call sites.
     */
    public function testFactDateRangeFromId()
    {
        // generateRandomUid(): 10 digits of unix time, 6 random, 3 server.
        $ts = strtotime('2026-08-15 12:00:00');
        $id = $ts . '611353' . '957';

        $this->assertSame(19, strlen($id), 'the fixture must be a well-formed uid');

        $range = \OWA\Core\Db::factDateRangeFromId($id, 2);

        $this->assertSame(date('Ymd', strtotime('2026-08-13')), $range['start']);
        $this->assertSame(date('Ymd', strtotime('2026-08-17')), $range['end']);
        $this->assertLessThan($range['end'], $range['start']);

        // The window is configurable, and always brackets the id's own day.
        $wide = \OWA\Core\Db::factDateRangeFromId($id, 10);
        $this->assertLessThan($range['start'], $wide['start']);
        $this->assertGreaterThan($range['end'], $wide['end']);

        // A crc32-era id is a hash: its leading digits are not a date, and
        // reading one as a timestamp would send the query to a wrong partition.
        foreach (['71927192', '-1', '', null, 'abc', '123', str_repeat('1', 18), str_repeat('1', 20)] as $bad) {
            $this->assertNull(\OWA\Core\Db::factDateRangeFromId($bad), var_export($bad, true) . ' must not yield a range');
        }
    }

    /** A constrained entity load still finds a row the constraint excludes. */
    public function testConstrainedLoadFallsBackWhenTheHintIsWrong()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(20250101, 20251231, 'monthly'));
        $db->query(sprintf('INSERT INTO %s (id, yyyymmdd) VALUES (42, 20250615)', $t));

        // Deliberately wrong window -- as a skewed browser clock would give.
        $wrong = array('yyyymmdd' => array(
            'value' => array('start' => '20251101', 'end' => '20251130'), 'operator' => 'between'
        ));

        $bounded = $db->getOneRowFromTable($t, 42, $wrong);
        $this->assertEmpty($bounded, 'the wrong window must genuinely exclude the row');

        // What getByColumn() does on a miss: repeat without the constraint.
        $unbounded = $db->getOneRowFromTable($t, 42, array());
        $this->assertSame(42, (int) $unbounded['id'], 'the row must still be reachable');
    }

    /**
     * The safety property behind every bounded lookup on the write path: a
     * constraint that excludes the row must not make the row look absent.
     *
     * This is exercised through a real entity rather than the db seam, because
     * the retry lives in Entity::getByColumn() precisely so that no call site
     * has to remember it. A session that is not found is not a slow path -- it
     * is a second session row for a visit that already had one.
     */
    public function testEntityLoadRetriesWhenTheConstraintExcludesTheRow()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $id = (string) random_int(1, PHP_INT_MAX);
        $ymd = date('Ymd', strtotime('-90 days'));

        $s = \OWA\Core\CoreAPI::entityFactory('base.session');
        $s->set('id', $id);
        $s->set('site_id', 'phpunit-partition');
        $s->set('yyyymmdd', $ymd);
        $s->set('timestamp', strtotime('-90 days'));
        $s->create();

        try {
            // A window nowhere near the row -- what a browser clock decades out
            // would produce, or a session older than the lookup window.
            $wrong = \OWA\Core\Db::factDateConstraint(date('Ymd'));

            $this->assertNotEmpty($wrong, 'the fixture needs a real constraint to be a test');

            $found = \OWA\Core\CoreAPI::entityFactory('base.session');
            $found->getByPk('id', $id, $wrong);

            $this->assertSame($id, (string) $found->get('id'), 'the row must be found despite the wrong window');
            $this->assertTrue($found->wasPersisted(), 'and must be reported as persisted, not as a new session');

            // The unconstrained load agrees, so the retry returns the same row.
            $plain = \OWA\Core\CoreAPI::entityFactory('base.session');
            $plain->getByPk('id', $id);
            $this->assertSame((string) $plain->get('yyyymmdd'), (string) $found->get('yyyymmdd'));

        } finally {
            $db->query(sprintf("DELETE FROM owa_session WHERE id = '%s'", $db->prepare($id)));
        }
    }

    /**
     * A long-running installation must be partitionable at all.
     *
     * A flat monthly layout over decades asks for one partition per month, which
     * no open-file budget accommodates — and there is no way out, since monthly
     * is already the coarsest granularity and old data cannot be pruned before
     * partitions exist to prune. The old tier is what makes it possible.
     */
    public function testTieredRangesMakeALongHistoryPartitionable()
    {
        $through = \OWA\Core\Db::partitionLeadBoundary();
        $min     = date('Ymd', strtotime(date('Ym') . '01 -300 months'));

        $flat   = \OWA\Core\Db::makePartitionRanges($min, date('Ymd', strtotime($through . ' -1 day')), 'monthly');
        $tiered = \OWA\Core\Db::makeTieredPartitionRanges($min, $through, 'monthly', 36);

        $this->assertGreaterThan(300, count($flat), '25 years flat should be unmanageable');
        $this->assertLessThan(100, count($tiered), 'tiered should be manageable');

        // Ascending, contiguous, and every boundary a real date.
        $prev = null;
        foreach ($tiered as $name => $less_than) {
            $this->assertMatchesRegularExpression('/^p\d{8}$/', $name, 'names must stay p<yyyymmdd>');
            if ($prev !== null) {
                $this->assertSame($prev, substr($name, 1), 'tiers must not leave a gap or overlap');
            }
            $prev = $less_than;
        }

        // Recent history keeps its granularity.
        $recent = array_slice(array_keys($tiered), -12);
        foreach ($recent as $name) {
            $this->assertSame('01', substr($name, 7, 2), 'detail-window partitions are monthly');
        }
    }

    /** An install with only recent data gets no old tier at all. */
    public function testTieredRangesAreFlatWhenAllDataIsRecent()
    {
        $through = \OWA\Core\Db::partitionLeadBoundary();
        $min     = date('Ymd', strtotime(date('Ym') . '01 -6 months'));

        $this->assertSame(
            count(\OWA\Core\Db::makePartitionRanges($min, date('Ymd', strtotime($through . ' -1 day')), 'monthly')),
            count(\OWA\Core\Db::makeTieredPartitionRanges($min, $through, 'monthly', 36)),
            'nothing outside the detail window means nothing to coarsen'
        );
    }

    /**
     * Compaction keeps a table within its budget by merging, never by deleting.
     *
     * This is the property the whole design rests on: partition count stops
     * being a reason to discard data.
     */
    public function testCompactionMergesWithoutLosingRows()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $start = date('Ymd', strtotime(date('Ym') . '01 -120 months'));
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            $start, date('Ymd', strtotime(\OWA\Core\Db::partitionLeadBoundary() . ' -1 day')), 'monthly'));

        for ($m = -120; $m <= 0; $m += 6) {
            $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, $m + 200,
                date('Ymd', strtotime(date('Ym') . "01 $m months +14 days"))));
        }

        $before = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
        $count  = count($db->getPartitionSpans($t));

        $plan = $db->planPartitionCompaction($t, 60, 36);
        $this->assertNotEmpty($plan['operations'], 'a 10-year monthly table should have something to reshape');

        foreach ($plan['operations'] as $op) {
            $this->assertTrue($db->reshapePartitions($t, $op['names'], $op['ranges']));
        }

        $after = count($db->getPartitionSpans($t));

        $this->assertLessThan($count, $after, 'compaction must reduce the partition count');
        $this->assertSame($before, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'no row may be lost');
        $this->assertSame($plan['projected'], $after, 'the plan must predict the outcome');

        // The detail window is untouched, so recent retention stays precise.
        $boundary = date('Ymd', strtotime(date('Ym') . '01 -36 months'));
        foreach ($db->getPartitionSpans($t) as $span) {
            if ($span['start'] >= $boundary) {
                $this->assertSame('01', substr($span['start'], 6, 2), 'recent partitions stay monthly');
            }
        }
    }

    /**
     * An unreachable budget must not collapse all of history into one partition.
     *
     * That would fit no better and would leave a table whose entire past ages
     * out in a single drop — the cliff this design exists to avoid.
     */
    public function testCompactionRefusesToBuildACliff()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $start = date('Ymd', strtotime(date('Ym') . '01 -240 months'));
        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            $start, date('Ymd', strtotime(\OWA\Core\Db::partitionLeadBoundary() . ' -1 day')), 'monthly'));

        // A budget below the floor: the detail window alone exceeds it.
        $plan = $db->planPartitionCompaction($t, 5, 36);

        $this->assertFalse($plan['fits'], 'this budget is not reachable');
        $this->assertGreaterThan(1, count($plan['operations']), 'must not merge everything into one partition');
        $this->assertGreaterThan(0, $plan['floor'], 'must report the floor so a caller can explain why');

        foreach ($plan['operations'] as $m) {
            $span = (strtotime($m['less_than']) - strtotime($m['start'])) / 86400 / 365;
            $this->assertLessThanOrEqual(
                \OWA\Core\Db::PARTITION_MAX_YEARS_PER_BLOCK + 0.1,
                $span,
                'no merged block may exceed the configured maximum span'
            );
        }
    }



    /** Build a table with $months of monthly history plus the standard lead. */
    // -----------------------------------------------------------------------
    // Describing a layout -- what partition-status reads
    // -----------------------------------------------------------------------

    /**
     * A period is named in the vocabulary the commands use.
     *
     * partition-status has to describe a tiered table, where the tail holds
     * years per partition and the head holds fractions of a month. Naming a
     * sub-month period after the granularity that cuts it there keeps the report
     * in the same words as the reorganize command's argument.
     */
    public function testPeriodsAreNamedAsOperatorsNameThem()
    {
        $cases = array(
            // monthly
            array('20260101', '20260201', 'monthly'),
            array('20260201', '20260301', 'monthly'),   // short month
            array('20241201', '20250101', 'monthly'),   // across a year end
            // sub-month, by the cuts that produce them
            array('20260101', '20260116', 'half-month'),
            array('20260116', '20260201', 'half-month'),
            array('20260216', '20260301', 'half-month'),
            array('20260101', '20260108', 'quarter-month'),
            array('20260108', '20260115', 'quarter-month'),
            array('20260115', '20260122', 'quarter-month'),
            array('20260122', '20260201', 'quarter-month'),
            // merged blocks
            array('20260101', '20270101', '1 year'),
            array('20200101', '20250101', '5 years'),
            array('20200101', '20300101', '10 years'),
            array('20260101', '20260401', '3 months'),
            array('20260101', '20261101', '10 months'),
            // a block that begins mid-month, which merging never produces but
            // a hand-altered table can
            array('20260116', '20260316', '59 days'),
            // unreadable
            array('20260101', '20260101', 'unknown'),   // empty
            array('20260201', '20260101', 'unknown'),   // reversed
            array('garbage',  '20260101', 'unknown'),
            array('20260101', '',         'unknown'),
        );

        foreach ($cases as list($start, $less_than, $expected)) {
            $this->assertSame(
                $expected,
                \OWA\Core\Db::describePartitionPeriod($start, $less_than),
                "$start .. $less_than"
            );
        }
    }

    /** An unpartitioned table reports as such, and nothing else claims to know. */
    public function testLayoutOfAnUnpartitionedTable()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $layout = $db->describePartitionLayout($this->makeTable());

        $this->assertFalse($layout['partitioned']);
        $this->assertSame(0, $layout['total']);
        $this->assertSame(0, $layout['spans']);
        $this->assertNull($layout['covers']);
        $this->assertNull($layout['granularity']);
        $this->assertNull($layout['catch_all']);
        $this->assertNull($layout['ahead']);
        $this->assertSame(array(), $layout['tiers']);
    }

    /**
     * A uniform table is one tier, and the counts add up: the catch-all is
     * counted in the total and excluded from the bounded spans, because that is
     * the distinction the budget and the retention rules both turn on.
     */
    public function testLayoutOfAUniformTable()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime(date('Ym') . '01 -6 months')),
            date('Ymd', strtotime(date('Ym') . '01 +3 months')),
            'monthly'
        ));

        $layout = $db->describePartitionLayout($t);

        $this->assertTrue($layout['partitioned']);
        $this->assertSame($layout['spans'] + 1, $layout['total'], 'catch-all is in the total');
        $this->assertSame('monthly', $layout['granularity']);
        $this->assertNotNull($layout['catch_all']);
        $this->assertCount(1, $layout['tiers']);
        $this->assertSame('monthly', $layout['tiers'][0]['period']);
        $this->assertSame($layout['spans'], $layout['tiers'][0]['count'], 'every span is in a tier');
        $this->assertSame($layout['covers']['start'], $layout['tiers'][0]['start']);
        $this->assertSame($layout['covers']['end'], $layout['tiers'][0]['end']);
    }

    /**
     * The reason this is reported as tiers rather than a granularity.
     *
     * A rotated table is coarse at the tail and fine at the head. One word
     * cannot describe it without being wrong about one end, so the report gives
     * each run of equal-length partitions with the span it covers -- and the
     * tiers must tile the whole range without a gap.
     */
    public function testLayoutOfATieredTableIsReportedAsTiers()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (array('monthly', 'half-month', 'quarter-month') as $granularity) {

            $t      = $this->tieredTable(180, $granularity);
            $layout = $db->describePartitionLayout($t);

            $this->assertGreaterThan(1, count($layout['tiers']),
                "$granularity: 15 years of history must produce a coarse tail and a fine head");

            // The finest tier is the newest, and is the granularity in force.
            $newest = $layout['tiers'][count($layout['tiers']) - 1];
            $this->assertSame($granularity, $newest['period'],
                "$granularity: the head keeps the table's granularity");
            $this->assertSame($granularity, $layout['granularity'],
                "$granularity: inference agrees with the newest tier");

            // The tail is coarser than a month.
            $this->assertNotSame($granularity, $layout['tiers'][0]['period'],
                "$granularity: the tail must have been merged");

            // Tiers tile the covered range end to end.
            $this->assertSame($layout['covers']['start'], $layout['tiers'][0]['start']);
            $this->assertSame($layout['covers']['end'], $newest['end']);

            $counted = 0;
            $previous = null;

            foreach ($layout['tiers'] as $tier) {
                if ($previous !== null) {
                    $this->assertSame($previous, $tier['start'],
                        "$granularity: tiers must be contiguous");
                }
                $previous = $tier['end'];
                $counted += $tier['count'];
            }

            $this->assertSame($layout['spans'], $counted,
                "$granularity: every bounded partition belongs to exactly one tier");
        }
    }

    /**
     * The lead is what running out of costs, so it is counted as partitions
     * bought in advance and as days until the last boundary.
     */
    public function testLayoutCountsTheLead()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (array(0, 1, 3, 12) as $ahead) {

            $t = $this->makeTable();

            $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                date('Ymd', strtotime(date('Ym') . '01 -6 months')),
                date('Ymd', strtotime(date('Ym') . "01 +$ahead months")),
                'monthly'
            ));

            $layout = $db->describePartitionLayout($t);

            // The end date names the last period to create, so the boundary
            // above it is a month later.
            $top = date('Ymd', strtotime(date('Ym') . '01 +' . ($ahead + 1) . ' months'));

            $this->assertSame($top, $layout['through'], "lead of $ahead months: top boundary");

            // Partitions that begin after today. The current month's began on
            // the 1st, so it is not lead however early in the month this runs.
            $this->assertSame($ahead, $layout['lead'],
                "lead of $ahead months: partitions wholly in the future");

            $this->assertSame(
                (int) (new DateTimeImmutable('today'))
                    ->diff(DateTimeImmutable::createFromFormat('Ymd|', $top))->format('%r%a'),
                $layout['ahead'],
                "lead of $ahead months: days to the top boundary"
            );
        }
    }

    /**
     * A lead that has run out reports as negative days, not as zero or as an
     * absence. That sign is what the command turns into "rotate now".
     */
    public function testLayoutReportsAnExpiredLeadAsNegative()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime(date('Ym') . '01 -12 months')),
            date('Ymd', strtotime(date('Ym') . '01 -3 months')),
            'monthly'
        ));

        $layout = $db->describePartitionLayout($t);

        $this->assertLessThan(0, $layout['ahead'], 'a boundary in the past is negative days ahead');
        $this->assertSame(0, $layout['lead'], 'no partition begins in the future');
        $this->assertSame(date('Ymd', strtotime(date('Ym') . '01 -2 months')), $layout['through'],
            'the last period created is -3 months, so its upper bound is -2');
    }

    /**
     * Counting the catch-all reads the partition, not the InnoDB row estimate.
     *
     * The question being asked -- has real data collected outside the dated
     * partitions -- cannot be answered by an estimate that is routinely out by
     * a wide margin, and is zero for a freshly loaded table.
     */
    public function testCatchAllContentsAreCountedExactly()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime(date('Ym') . '01 -6 months')),
            date('Ymd', strtotime(date('Ym') . '01 +1 month')),
            'monthly'
        ));

        $catch_all = $db->getCatchAllPartition($t);

        $empty = $db->getPartitionContents($t, $catch_all);
        $this->assertSame(0, $empty['rows']);
        $this->assertNull($empty['min'], 'an empty partition has no bounds');
        $this->assertNull($empty['max']);

        // Rows past the top boundary land in the catch-all.
        $dates = array();
        $id = 0;

        foreach (array(3, 6, 9) as $months) {
            $d = date('Ymd', strtotime(date('Ym') . "01 +$months months +5 days"));
            $dates[] = $d;
            $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, ++$id, $d));
        }

        // ...and one that does not, to prove the count is scoped to the partition.
        $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, ++$id,
            date('Ymd', strtotime(date('Ym') . '01 -2 months +5 days'))));

        $filled = $db->getPartitionContents($t, $catch_all);

        $this->assertSame(3, $filled['rows'], 'only the catch-all is counted');
        $this->assertSame(min($dates), $filled['min']);
        $this->assertSame(max($dates), $filled['max']);
    }

    /**
     * Identifiers are interpolated into the statement, so they are validated --
     * and validated before the statement is built, not after MySQL rejects it.
     *
     * Asserting only that a bad identifier yields null proves nothing: an
     * unvalidated identifier produces a syntax error, and that yields null too.
     * The observable difference is whether a query is issued at all, so the
     * driver is watched rather than its return value.
     */
    public function testPartitionContentsValidatesIdentifiersBeforeQuerying()
    {
        $spy = new class extends \OWA\Core\Db\Mysql {

            /** @var string[] */
            public $sql = array();

            public function __construct() {}

            function get_row($sql, array $params = array())
            {
                $this->sql[] = $sql;

                return array('n' => 0, 'lo' => null, 'hi' => null);
            }
        };

        $bad = array(
            array('owa_t',  'pmax; DROP TABLE x', 'yyyymmdd'),
            array('owa_t',  'p max',              'yyyymmdd'),
            array('owa_t',  "p'max",              'yyyymmdd'),
            array('owa_t',  '',                   'yyyymmdd'),
            array('owa t',  'pmax',               'yyyymmdd'),
            array('x; DROP TABLE y', 'pmax',      'yyyymmdd'),
            array('owa_t',  'pmax',               'yyyymmdd; DROP TABLE x'),
            array('owa_t',  'pmax',               '1) OR (1'),
        );

        foreach ($bad as list($table, $partition, $column)) {
            $this->assertNull(
                $spy->getPartitionContents($table, $partition, $column),
                sprintf('%s / %s / %s should be refused', $table, $partition, $column)
            );
        }

        $this->assertSame(array(), $spy->sql,
            'a refused identifier must not reach the database at all');

        // ...and a well-formed one does query, so the guard is not simply
        // refusing everything.
        $this->assertNotNull($spy->getPartitionContents('owa_t', 'pmax', 'yyyymmdd'));
        $this->assertCount(1, $spy->sql);
        $this->assertStringContainsString('PARTITION (pmax)', $spy->sql[0],
            'the count must be scoped to the one partition');
    }

    /** The same validation, exercised against a real table. */
    public function testPartitionContentsRefusesUnsafeIdentifiers()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime(date('Ym') . '01 -2 months')),
            date('Ymd', strtotime(date('Ym') . '01 +1 month')),
            'monthly'
        ));

        foreach (array('pmax; DROP TABLE ' . $t, 'p max', "p'max", '') as $bad) {
            $this->assertNull($db->getPartitionContents($t, $bad), var_export($bad, true));
        }

        $this->assertNotEmpty($db->listPartitions($t), 'the table is still there');
    }

    private function historyTable($months)
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
            date('Ymd', strtotime(date('Ym') . "01 -$months months")),
            date('Ymd', strtotime(\OWA\Core\Db::partitionLeadBoundary() . ' -1 day')),
            'monthly'
        ));

        $id = 0;
        for ($m = -$months; $m <= 0; $m += 2) {
            $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, ++$id,
                date('Ymd', strtotime(date('Ym') . "01 $m months +14 days"))));
        }

        return $t;
    }

    /**
     * The detail window setting must actually decide how much is coarsened.
     *
     * It is the knob an operator is told to reach for when a table will not fit
     * its budget, so it has to demonstrably do something.
     */
    public function testDetailWindowSettingChangesWhatIsCompacted()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->historyTable(120);

        $wide   = $db->planPartitionCompaction($t, 40, 72);   // six years kept fine
        $narrow = $db->planPartitionCompaction($t, 40, 12);   // one year kept fine

        $this->assertGreaterThan(0, $wide['projected'], 'a plan must report a projection');

        $this->assertLessThan(
            $wide['projected'],
            $narrow['projected'],
            'a narrower detail window must leave fewer partitions'
        );

        $this->assertLessThan(
            $wide['floor'],
            $narrow['floor'],
            'and a narrower window must lower the floor, which is why it is the remedy'
        );
    }

    /**
     * The block cap must bound the coarsest partition. Without it an unreachable
     * budget collapses history into one partition -- the cliff this design
     * exists to prevent.
     */
    public function testBlockCapBoundsTheCoarsestPartition()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->historyTable(240);

        // A budget far below the floor, so the planner widens blocks as far as
        // it is allowed and no further.
        $plan = $db->planPartitionCompaction($t, 2, 36);

        $this->assertFalse($plan['fits'], 'this budget is unreachable by design');
        $this->assertGreaterThan(1, count($plan['operations']), 'must not collapse into a single partition');

        $cap = \OWA\Core\Db::PARTITION_MAX_YEARS_PER_BLOCK;

        foreach ($plan['operations'] as $m) {
            $years = (strtotime($m['less_than']) - strtotime($m['start'])) / 86400 / 365.25;
            $this->assertLessThanOrEqual($cap + 0.05, $years, "no block may exceed $cap years");
        }
    }

    /**
     * Retention across the range of keep values: larger than the history drops
     * nothing, smaller drops, and the boundary partition is kept either way
     * because only whole partitions go.
     */
    public function testKeepAcrossItsRange()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->historyTable(60);

        $rows = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];

        // Longer than the data: nothing is old enough to drop.
        $this->assertSame(
            array(),
            $db->getDroppablePartitions($t, date('Ymd', strtotime('-600 months')))['drop'],
            'a keep longer than the history must drop nothing'
        );

        // Shorter than the data: something goes, and never the straddler.
        $cut  = date('Ymd', strtotime('-24 months'));
        $plan = $db->getDroppablePartitions($t, $cut);

        $this->assertNotEmpty($plan['drop'], 'a keep shorter than the history must drop something');
        $this->assertNotContains($plan['straddling']['name'] ?? '', $plan['drop'], 'the straddler is kept');
        $this->assertLessThanOrEqual($cut, $plan['effective'], 'the boundary reached cannot exceed the cutoff');

        foreach ($plan['drop'] as $p) {
            $db->dropPartition($t, $p);
        }

        $this->assertLessThan($rows, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n']);

        // And re-running the same cutoff now drops nothing: retention settles.
        $this->assertSame(
            array(),
            $db->getDroppablePartitions($t, $cut)['drop'],
            'retention must be idempotent for a fixed cutoff'
        );
    }

    /**
     * The whole cycle settles. Running it twice must leave the table identical,
     * whether or not a retention window was given -- otherwise a scheduled job
     * would churn the table on every run.
     */
    public function testTheFullCycleIsIdempotent()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (array( 'with keep' => date('Ymd', strtotime('-24 months')), 'without keep' => null ) as $label => $cutoff) {

            $t = $this->historyTable(96);

            // Db-layer calls in the same order rotate performs them. The
            // controller cannot be constructed under this bootstrap; its own
            // step is covered in PartitionCliTest, which boots the CLI role.
            $cycle = function () use ($db, $t, $cutoff) {
                $db->extendPartitions($t, 'monthly', \OWA\Core\Db::partitionLeadBoundary());
                foreach ($db->planPartitionCompaction($t, 50, 36)['operations'] as $op) {
                    $db->reshapePartitions($t, $op['names'], $op['ranges']);
                }
                if ($cutoff) {
                    foreach ($db->getDroppablePartitions($t, $cutoff)['drop'] as $p) {
                        $db->dropPartition($t, $p);
                    }
                }
                return array_map(fn($s) => $s['name'] . ':' . $s['less_than'], $db->getPartitionSpans($t));
            };

            $first  = $cycle();
            $rows   = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
            $second = $cycle();

            $this->assertSame($first, $second, "$label: a second cycle must change nothing");
            $this->assertSame(
                $rows,
                (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'],
                "$label: and must delete nothing"
            );
        }
    }

    /** A tiered table: coarse year blocks, a fine detail window, and a lead. */
    private function tieredTable($history_months = 120, $granularity = 'monthly')
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makeTieredPartitionRanges(
            date('Ymd', strtotime(date('Ym') . "01 -$history_months months")),
            \OWA\Core\Db::partitionLeadBoundary(),
            $granularity,
            \OWA\Core\Db::PARTITION_DETAIL_MONTHS
        ));

        $id = 0;
        for ($m = -$history_months; $m <= 12; $m += 4) {
            $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, ++$id,
                date('Ymd', strtotime(date('Ym') . "01 $m months +14 days"))));
        }

        return $t;
    }

    private function coarseAndFine($table)
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $coarse = 0; $fine = 0;

        foreach ($db->getPartitionSpans($table) as $span) {
            $month_after = date('Ymd', strtotime(substr($span['start'], 0, 6) . '01 +1 month'));
            if ($span['less_than'] <= $month_after) { $fine++; } else { $coarse++; }
        }

        return array('coarse' => $coarse, 'fine' => $fine);
    }

    /**
     * Reorganising a tiered table must refine only the detail window.
     *
     * The coarse tail exists to keep the partition count within the server's
     * open-file budget. Splitting it back to the table's granularity would undo
     * exactly what compaction did, and on a long history it multiplies the
     * partition count several times over.
     */
    public function testReorganiseLeavesTheCoarseTailAlone()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['half-month', 'quarter-month', 'monthly'] as $granularity) {

            $t      = $this->tieredTable(120);
            $before = $this->coarseAndFine($t);
            $rows   = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];

            $this->assertGreaterThan(0, $before['coarse'], "$granularity: fixture needs a tail");
            $this->assertGreaterThan(0, $before['fine'],   "$granularity: fixture needs a detail window");

            $db->repartitionTable($t, $granularity);

            $after = $this->coarseAndFine($t);

            $this->assertSame(
                $before['coarse'],
                $after['coarse'],
                "$granularity: the coarse tail must be untouched"
            );

            $this->assertSame(
                $rows,
                (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'],
                "$granularity: no row may be lost"
            );

            // And the fine end really did become the requested granularity.
            $this->assertSame(
                $granularity,
                $db->inferPartitionGranularity($t),
                "$granularity: the recent end should now read as that granularity"
            );
        }
    }

    /** An explicit range may still refine the tail, for an operator who means it. */
    public function testAnExplicitRangeCanStillRefineTheTail()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->tieredTable(120);

        $spans = $db->getPartitionSpans($t);
        $block = $spans[0];

        $this->assertGreaterThan(
            date('Ymd', strtotime(substr($block['start'], 0, 6) . '01 +1 month')),
            $block['less_than'],
            'the fixture\'s first partition should be a coarse block'
        );

        $rows = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
        $before = count($spans);

        $db->repartitionTable($t, 'monthly', false, $block['start'], $block['less_than']);

        $this->assertGreaterThan($before, count($db->getPartitionSpans($t)), 'the block should have been split');
        $this->assertSame($rows, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'no row may be lost');
    }

    /**
     * The whole shape holds together across budgets: lead, tail and detail
     * window survive compaction at every level, and the plan predicts what
     * happens.
     */
    public function testTieredTableCompactsCorrectlyAtEveryBudget()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([200, 60, 40, 5] as $limit) {

            $t     = $this->tieredTable(120);
            $rows  = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
            $spans = $db->getPartitionSpans($t);
            $lead  = end($spans)['less_than'];

            $plan = $db->planPartitionCompaction($t, $limit, \OWA\Core\Db::PARTITION_DETAIL_MONTHS);

            foreach ($plan['operations'] as $op) {
                $this->assertTrue($db->reshapePartitions($t, $op['names'], $op['ranges']),
                    "budget $limit: every planned reshape must succeed");
            }

            $spans = $db->getPartitionSpans($t);

            $this->assertSame($plan['projected'], count($spans), "budget $limit: the plan must predict the result");
            $this->assertSame($rows, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'],
                "budget $limit: no row may be lost");
            $this->assertSame($lead, end($spans)['less_than'], "budget $limit: the lead must be preserved");

            if ($plan['fits']) {
                $this->assertLessThanOrEqual($limit, count($spans), "budget $limit: should have reached the budget");
            } else {
                $this->assertGreaterThanOrEqual($plan['floor'], count($spans), "budget $limit: cannot go below the floor");
            }

            // Settles regardless of budget.
            $again = $db->planPartitionCompaction($t, $limit, \OWA\Core\Db::PARTITION_DETAIL_MONTHS);
            $this->assertEmpty($again['operations'], "budget $limit: a second pass must find nothing to do");
        }
    }

    /**
     * The layout is a function of the settings, not of the order things
     * happened in.
     *
     * Without splitting as well as merging, a table coarsened under a tight
     * budget would stay coarse after the budget grew, while an identical
     * installation partitioned fresh would not -- so two installations with the
     * same data and the same configuration would differ permanently, and
     * reorganising would never be safe to repeat.
     */
    public function testTheLayoutIsDeterministicWhateverThePath()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $shape = fn($t) => implode(',', array_map(
            fn($s) => $s['name'] . ':' . $s['less_than'],
            $db->getPartitionSpans($t)
        ));

        $apply = function ($t, $limit) use ($db) {
            foreach ($db->planPartitionCompaction($t, $limit, 36)['operations'] as $op) {
                $db->reshapePartitions($t, $op['names'], $op['ranges']);
            }
        };

        // Squeezed hard, then given room.
        $a = $this->tieredTable(120);
        $fresh = count($db->getPartitionSpans($a));
        $apply($a, 45);
        $squeezed = count($db->getPartitionSpans($a));
        $this->assertLessThan($fresh, $squeezed, 'sanity: the squeeze must actually coarsen the table');
        $apply($a, 200);

        // Only ever generous.
        $b = $this->tieredTable(120);
        $apply($b, 200);

        $this->assertGreaterThan($squeezed, count($db->getPartitionSpans($a)), 'and relaxing must undo it');
        $this->assertSame($shape($b), $shape($a), 'the same settings must produce the same layout by either path');

        // And a third path: relaxed, squeezed, relaxed again.
        $c = $this->tieredTable(120);
        $apply($c, 200);
        $apply($c, 45);
        $apply($c, 200);

        $this->assertSame($shape($b), $shape($c), 'repeated reorganisation must converge, not drift');
    }

    /** A coarse tail is split back to finer blocks when the budget allows. */
    public function testACoarseTailIsUnpackedWhenTheBudgetGrows()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->tieredTable(180);

        $rows = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];

        // Squeeze: the tail should end up in multi-year blocks.
        foreach ($db->planPartitionCompaction($t, 45, 36)['operations'] as $op) {
            $db->reshapePartitions($t, $op['names'], $op['ranges']);
        }

        $tight = $db->planPartitionCompaction($t, 45, 36);
        $this->assertGreaterThan(1, $tight['block_years'], 'a tight budget should need multi-year blocks');

        $squeezed = count($db->getPartitionSpans($t));

        // Relax: it must come back to one-year blocks.
        $plan = $db->planPartitionCompaction($t, 300, 36);

        $this->assertNotEmpty($plan['operations'], 'a grown budget must produce work, not nothing');
        $this->assertSame(1, $plan['block_years'], 'with room, the tail should be one year per block');

        foreach ($plan['operations'] as $op) {
            $this->assertTrue($db->reshapePartitions($t, $op['names'], $op['ranges']));
        }

        $this->assertGreaterThan($squeezed, count($db->getPartitionSpans($t)), 'the tail should have been split');
        $this->assertSame($rows, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'splitting must not lose a row');
    }

    /** Layout as a comparable string, for asserting two paths agree. */
    private function shapeOf($table)
    {
        return implode(',', array_map(
            fn($s) => $s['name'] . ':' . $s['less_than'],
            \OWA\Core\CoreAPI::dbSingleton()->getPartitionSpans($table)
        ));
    }

    /** What partition-init does to an unpartitioned table. */
    private function runInit($table, $granularity = 'monthly', $history = 120, $limit = 200)
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->partitionTable($table, 'yyyymmdd', \OWA\Core\Db::makeTieredPartitionRanges(
            date('Ymd', strtotime(date('Ym') . "01 -$history months")),
            \OWA\Core\Db::partitionLeadBoundary(),
            $granularity,
            \OWA\Core\Db::PARTITION_DETAIL_MONTHS,
            $limit
        ));
    }

    /** What partition-rotate does: extend the lead, then converge the tail. */
    private function runRotate($table, $limit = 200)
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->extendPartitions($table, $db->inferPartitionGranularity($table) ?: 'monthly',
            \OWA\Core\Db::partitionLeadBoundary());

        foreach ($db->planPartitionCompaction($table, $limit, \OWA\Core\Db::PARTITION_DETAIL_MONTHS)['operations'] as $op) {
            $db->reshapePartitions($table, $op['names'], $op['ranges']);
        }
    }

    /**
     * A freshly initialised table must already be in the shape rotation
     * converges on.
     *
     * If the two disagree about where the coarse tail ends, every scheduled
     * rotate rewrites what init built -- the table is reorganised on each run
     * for no change in shape, and on a large installation that is an expensive
     * no-op with writes blocked.
     */
    public function testInitProducesTheShapeRotationWouldConvergeOn()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['monthly', 'half-month', 'quarter-month'] as $granularity) {

            $t = $this->makeTable();
            $this->runInit($t, $granularity);

            $before = $this->shapeOf($t);
            $plan   = $db->planPartitionCompaction($t, 200, \OWA\Core\Db::PARTITION_DETAIL_MONTHS);

            $this->assertEmpty(
                $plan['operations'],
                "$granularity: rotation should find nothing to reshape straight after init"
            );

            $this->runRotate($t);

            $this->assertSame($before, $this->shapeOf($t), "$granularity: rotation must not churn a fresh table");
        }
    }

    /**
     * The three commands compose in any order and settle on the same layout.
     *
     * They are separate entry points onto one piece of state, so the risk is
     * that each undoes another's work: reorganise refining the tail rotation
     * coarsened, rotation reverting the granularity reorganise chose, init
     * disagreeing with both.
     */
    public function testInitReorganiseAndRotateComposeInAnyOrder()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        // init -> reorganise -> rotate
        $a = $this->makeTable();
        $this->runInit($a);
        $db->repartitionTable($a, 'half-month');
        $this->runRotate($a);

        // init -> rotate -> reorganise -> rotate
        $b = $this->makeTable();
        $this->runInit($b);
        $this->runRotate($b);
        $db->repartitionTable($b, 'half-month');
        $this->runRotate($b);

        $this->assertSame($this->shapeOf($a), $this->shapeOf($b), 'order must not change the destination');

        $this->assertSame('half-month', $db->inferPartitionGranularity($a),
            'rotation must not revert the granularity reorganise chose');
        $this->assertSame('half-month', $db->inferPartitionGranularity($b));

        // And it is settled: another full pass changes nothing.
        $settled = $this->shapeOf($a);
        $this->runRotate($a);
        $db->repartitionTable($a, 'half-month');
        $this->runRotate($a);

        $this->assertSame($settled, $this->shapeOf($a), 'repeating the sequence must not drift');
    }

    /**
     * Running every command repeatedly on a table with data must never lose a
     * row, whatever order they are invoked in.
     */
    public function testTheCommandsNeverLoseDataHoweverTheyAreSequenced()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t  = $this->makeTable();

        $this->runInit($t, 'monthly', 96);

        $id = 0;
        for ($m = -96; $m <= 12; $m += 3) {
            $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, ++$id,
                date('Ymd', strtotime(date('Ym') . "01 $m months +14 days"))));
        }

        $rows = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
        $this->assertGreaterThan(0, $rows, 'the fixture needs rows to be a test');

        // A deliberately awkward sequence, including a tight budget in the middle.
        $this->runRotate($t, 45);
        $db->repartitionTable($t, 'quarter-month');
        $this->runRotate($t, 200);
        $db->repartitionTable($t, 'monthly');
        $this->runRotate($t, 60);
        $this->runRotate($t, 60);

        $this->assertSame(
            $rows,
            (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'],
            'no sequence of these commands may lose a row'
        );

        // The lead is still maintained after all of that.
        $spans = $db->getPartitionSpans($t);
        $this->assertSame(
            \OWA\Core\Db::partitionLeadBoundary(),
            end($spans)['less_than'],
            'the lead must survive the sequence'
        );
    }
}
