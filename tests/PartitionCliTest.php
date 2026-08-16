<?php

require_once __DIR__ . '/CliControllerTestCase.php';

/**
 * The partition commands' own decisions: what they accept, what they refuse,
 * and what they leave alone.
 *
 * PartitionOperationsTest covers the database work these drive -- creating,
 * extending, reorganising and dropping partitions. What it cannot reach is the
 * layer above: reading a retention window off the command line, sizing the
 * partition budget, and declining to act. Those are the parts an operator
 * actually types at, and the parts where being wrong is quiet -- a cutoff that
 * parses to nothing prunes nothing, and says so only in a notice.
 *
 * The helpers under test are protected because they are not API; they are
 * reached here by reflection rather than by widening them for the test.
 */
final class PartitionCliTest extends CliControllerTestCase
{
    /** @return mixed */
    private function callProtected(object $ctrl, string $method, array $args = [])
    {
        $m = new ReflectionMethod($ctrl, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($ctrl, $args);
    }

    private function rotate(array $params = []): object
    {
        return new \OWA\Module\Base\Controller\PartitionRotateCli($params);
    }

    private function drop(array $params = []): object
    {
        return new \OWA\Module\Base\Controller\PartitionDropCli($params);
    }

    /**
     * A retention window may be a date or a period back from today. The period
     * form is the one that belongs in cron: a fixed date stops pruning the day
     * it is passed, and does so silently.
     */
    public function testCutoffAcceptsADateOrAPeriod()
    {
        $c = $this->drop();

        $this->assertSame('20260101', $this->callProtected($c, 'resolveCutoff', ['20260101']));

        foreach ([
            '12months' => '-12 months',
            '18m'      => '-18 months',
            '2years'   => '-2 years',
            '90days'   => '-90 days',
            '1month'   => '-1 month',
        ] as $given => $equivalent) {
            $this->assertSame(
                date('Ymd', strtotime($equivalent)),
                $this->callProtected($c, 'resolveCutoff', [$given]),
                "$given should resolve to $equivalent"
            );
        }
    }

    /**
     * Anything unparseable must yield nothing rather than a guess. The caller
     * treats null as "do not proceed"; a silently wrong date here would drop
     * the wrong partitions.
     */
    public function testCutoffRefusesWhatItCannotRead()
    {
        $c = $this->drop();

        foreach (['', 'lastyear', '12', 'months', '2026-01-01', 'now', '-12months', '0'] as $bad) {
            $this->assertNull(
                $this->callProtected($c, 'resolveCutoff', [$bad]),
                var_export($bad, true) . ' should not resolve to a date'
            );
        }
    }

    /**
     * The budget is what stops a granularity choice quietly costing an instance
     * more open files than it has. It is a prompt, not a ceiling.
     */
    public function testBudgetGuardRefusesAnOversizedPlanUnlessForced()
    {
        $c = $this->rotate();

        $budget = $this->callProtected($c, 'partitionLimit', [4]);

        $this->assertIsInt($budget['limit']);
        $this->assertGreaterThan(0, $budget['limit']);
        $this->assertNotEmpty($budget['reason'], 'a refusal has to be able to explain itself');

        $this->assertTrue(
            $this->callProtected($c, 'withinPartitionBudget', ['owa_request', $budget['limit'], $budget]),
            'a plan exactly at the limit is allowed'
        );

        $this->assertFalse(
            $this->callProtected($c, 'withinPartitionBudget', ['owa_request', $budget['limit'] + 1, $budget]),
            'one partition over must be refused'
        );

        // force=1 is there for someone who has done their own arithmetic.
        $forced = $this->rotate(['force' => 1]);

        $this->assertTrue(
            $this->callProtected($forced, 'withinPartitionBudget', ['owa_request', $budget['limit'] * 100, $budget]),
            'force must override the refusal'
        );
    }

    /** More tables to cover means a smaller share of the budget for each. */
    public function testBudgetIsDividedAcrossTheTablesBeingPartitioned()
    {
        $c = $this->rotate();

        $one  = $this->callProtected($c, 'partitionLimit', [1]);
        $many = $this->callProtected($c, 'partitionLimit', [8]);

        $this->assertGreaterThanOrEqual(
            $many['limit'],
            $one['limit'],
            'one table may have at least as much headroom as one of eight'
        );
    }

    /** The commands act on fact tables, and on one when asked. */
    public function testTableSelection()
    {
        $c = $this->rotate();

        $all = $this->callProtected($c, 'factTables', [null]);

        $this->assertNotEmpty($all);
        $this->assertContains('owa_request', $all);
        $this->assertContains('owa_session', $all);

        foreach ($all as $t) {
            $this->assertStringStartsWith('owa_', $t);
        }

        $one = $this->callProtected($c, 'factTables', ['owa_request']);
        $this->assertSame(['owa_request'], $one);

        $this->assertSame(
            [],
            $this->callProtected($c, 'factTables', ['owa_not_a_fact_table']),
            'an unknown table selects nothing rather than everything'
        );
    }

    /**
     * An unreadable keep is refused; an absent one is not.
     *
     * The two mean opposite things. Omitting keep asks to retain everything;
     * "lots" is a mistake, and treating it as absent would silently turn a
     * botched retention policy into no retention policy at all.
     */
    public function testAnUnreadableKeepIsRefusedButAnAbsentOneIsNot()
    {
        $before = $this->partitionCount('owa_request');

        foreach ([['keep' => 'lots'], ['keep' => '-4'], ['keep' => '0'], ['keep' => '2.5']] as $params) {
            $this->rotate($params + ['table' => 'owa_request'])->action();
        }

        $this->assertSame(
            $before,
            $this->partitionCount('owa_request'),
            'an unreadable keep must stop the command, not be ignored'
        );

        // Absent is a valid policy, and must be accepted: it reaches the cutoff
        // resolution path at all only when keep is present.
        $this->assertNull(
            $this->callProtected($this->rotate(), 'resolveCutoff', ['']),
            'nothing to resolve when keep is absent'
        );
    }

    /** A dry run reports and changes nothing, which is what makes it safe to suggest. */
    public function testDryRunChangesNothing()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if (! $db->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition.');
        }

        $before = $this->partitionCount('owa_request');
        $rows   = (int) $db->get_row('SELECT COUNT(*) AS n FROM owa_request')['n'];

        $this->rotate(['keep' => 24, 'table' => 'owa_request', 'dry-run' => 1])->action();
        $this->drop(['older-than' => '1month', 'table' => 'owa_request', 'dry-run' => 1])->action();

        $this->assertSame($before, $this->partitionCount('owa_request'), 'no partition may be added or removed');
        $this->assertSame($rows, (int) $db->get_row('SELECT COUNT(*) AS n FROM owa_request')['n'], 'no row may be lost');
    }

    /**
     * An unknown granularity is refused rather than approximated.
     *
     * The load-bearing assertion is isPartitionGranularity() -- the function
     * the commands actually gate on. The partition count is a secondary check
     * that a refusal really did leave the table alone.
     */
    public function testUnknownGranularityIsRefused()
    {
        $before = $this->partitionCount('owa_request');

        foreach (['daily', 'weekly', '7day', 'tenday', 'hourly', 'yearly'] as $bad) {
            $this->assertFalse(
                \OWA\Core\Db::isPartitionGranularity($bad),
                "$bad must not be a granularity"
            );

            $this->rotate(['keep' => 24, 'granularity' => $bad, 'table' => 'owa_request'])->action();
        }

        $this->assertSame(
            $before,
            $this->partitionCount('owa_request'),
            'a refused granularity must leave the table alone'
        );
    }

    private function partitionCount(string $table): int
    {
        return count(\OWA\Core\CoreAPI::dbSingleton()->listPartitions($table));
    }

    /**
     * The budget is a setting, not an argument.
     *
     * How much of a server's open-file capacity partitioning may claim is a
     * property of the installation, so it is set once with a constant in
     * owa-config.php rather than chosen per invocation. Passing it as an
     * argument must have no effect at all.
     */
    public function testTheBudgetComesFromSettingsNotArguments()
    {
        $derived = $this->callProtected($this->rotate(), 'partitionLimit', [7]);

        $this->assertSame(
            $derived['limit'],
            $this->callProtected($this->rotate(['max-partitions' => '500']), 'partitionLimit', [7])['limit'],
            'an argument must not be able to change the budget'
        );

        $original = \OWA\Core\CoreAPI::getSetting('base', 'partition_max_partitions');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'partition_max_partitions', 500);
            $stated = $this->callProtected($this->rotate(), 'partitionLimit', [7]);
            $this->assertSame(500, $stated['limit']);
            $this->assertStringContainsString('OWA_PARTITION_MAX_PARTITIONS', $stated['reason']);

            // Zero means "derive it", not "no partitions allowed".
            \OWA\Core\CoreAPI::setSetting('base', 'partition_max_partitions', 0);
            $this->assertSame($derived['limit'], $this->callProtected($this->rotate(), 'partitionLimit', [7])['limit']);

        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'partition_max_partitions', $original);
        }
    }

    /** The numbers behind the budget are named constants, not literals. */
    public function testBudgetConstantsAreExposed()
    {
        $this->assertGreaterThanOrEqual(1, \OWA\Core\Db::PARTITION_BUDGET_RESERVE);
        $this->assertGreaterThan(0, \OWA\Core\Db::PARTITION_MIN_LIMIT);
        $this->assertGreaterThan(0, \OWA\Core\Db::PARTITION_DETAIL_MONTHS);
        $this->assertGreaterThan(0, \OWA\Core\Db::PARTITION_MAX_YEARS_PER_BLOCK);

        // The floor must not exceed what a derived budget could yield, or it
        // would silently become the only value this ever returns.
        $this->assertLessThan(
            \OWA\Core\Db::PARTITION_COUNT_LIMIT,
            \OWA\Core\Db::PARTITION_MIN_LIMIT
        );
    }

    /**
     * Rotation without a retention window compacts and deletes nothing.
     *
     * The controller's own step, not the database helper it calls: the ordering
     * -- extend, then compact, then drop only if asked -- is what could regress.
     * Lives here rather than in PartitionOperationsTest because constructing a
     * CLI controller needs the admin_cli instance role this harness boots.
     */
    public function testCompactTableMergesWithoutDeleting()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if (! $db->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition.');
        }

        $t = 'owa_test_compact_' . $this->tok;

        try {
            $db->query("CREATE TABLE $t (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, PRIMARY KEY (id,yyyymmdd))");
            $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                date('Ymd', strtotime(date('Ym') . '01 -120 months')),
                date('Ymd', strtotime(\OWA\Core\Db::partitionLeadBoundary() . ' -1 day')),
                'monthly'
            ));

            for ($m = -120; $m <= 0; $m += 6) {
                $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, $m + 500,
                    date('Ymd', strtotime(date('Ym') . "01 $m months +14 days"))));
            }

            $rows   = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
            $before = count($db->getPartitionSpans($t));
            $budget = ['limit' => 60, 'reason' => 'test'];
            $ctrl   = $this->rotate();

            // Dry run reports without acting.
            $this->callProtected($ctrl, 'compactTable', [$t, $budget, true]);
            $this->assertSame($before, count($db->getPartitionSpans($t)), 'a dry run must not merge');

            $merged = $this->callProtected($ctrl, 'compactTable', [$t, $budget, false]);

            $this->assertGreaterThan(0, $merged, 'a ten-year monthly table has something to merge');
            $this->assertLessThanOrEqual(60, count($db->getPartitionSpans($t)), 'must reach the budget');
            $this->assertSame($rows, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'no row may be deleted');

            // Idempotent: a settled table needs no further merging, however many
            // times the scheduled job runs.
            $settled = count($db->getPartitionSpans($t));
            $this->assertSame(0, $this->callProtected($ctrl, 'compactTable', [$t, $budget, false]));
            $this->assertSame($settled, count($db->getPartitionSpans($t)), 'the layout must settle');

            // The detail window is untouched, so recent retention stays precise.
            $boundary = date('Ymd', strtotime(date('Ym') . '01 -' . \OWA\Core\Db::PARTITION_DETAIL_MONTHS . ' months'));

            foreach ($db->getPartitionSpans($t) as $span) {
                if ($span['start'] >= $boundary) {
                    $this->assertSame('01', substr($span['start'], 6, 2), 'detail-window partitions stay monthly');
                }
            }

        } finally {
            $db->query("DROP TABLE IF EXISTS $t");
        }
    }

    /**
     * A finer granularity trades tail detail for recent detail.
     *
     * Moving to quarter-month multiplies the detail window, which on a
     * long-history table exceeds the budget. Refusing outright would leave the
     * operator no way to get finer recent partitions on exactly the
     * installations where old history is what is consuming the budget. Old
     * periods are merged to make room instead -- the tail exists to be traded.
     */
    public function testReorganiseMergesTheTailToMakeRoom()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if (! $db->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition.');
        }

        $t = 'owa_test_room_' . $this->tok;

        try {
            $db->query("CREATE TABLE $t (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, PRIMARY KEY (id,yyyymmdd))");
            $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makeTieredPartitionRanges(
                date('Ymd', strtotime(date('Ym') . '01 -180 months')),
                \OWA\Core\Db::partitionLeadBoundary(),
                'monthly',
                \OWA\Core\Db::PARTITION_DETAIL_MONTHS
            ));

            for ($m = -180; $m <= 0; $m += 12) {
                $db->query(sprintf('INSERT INTO %s VALUES (%d,%s)', $t, $m + 900,
                    date('Ymd', strtotime(date('Ym') . "01 $m months +14 days"))));
            }

            $rows   = (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'];
            $before = count($db->getPartitionSpans($t));
            $wanted = $db->repartitionTable($t, 'quarter-month', true)['planned'];

            $this->assertGreaterThan($before, $wanted, 'the fixture must actually need more partitions');

            // Reserving what the finer granularity will need is the part that
            // matters: measured against today's count the table already fits,
            // and nothing would be merged.
            $extra  = $wanted - $before;
            $budget = ['limit' => max(1, 200 - $extra), 'reason' => 'test, room reserved'];

            $merged = $this->callProtected($this->rotate(), 'compactTable', [$t, $budget, false]);

            $this->assertGreaterThan(0, $merged, 'room must actually be made');
            $this->assertLessThan($before, count($db->getPartitionSpans($t)), 'the tail should be coarser');
            $this->assertSame($rows, (int) $db->get_row("SELECT COUNT(*) AS n FROM $t")['n'], 'no row may be lost');

            // The detail window is still intact, which is the point of the trade.
            $boundary = date('Ymd', strtotime(date('Ym') . '01 -' . \OWA\Core\Db::PARTITION_DETAIL_MONTHS . ' months'));
            $fine = 0;

            foreach ($db->getPartitionSpans($t) as $span) {
                if ($span['start'] >= $boundary) { $fine++; }
            }

            $this->assertGreaterThan(0, $fine, 'the detail window must survive the trade');

        } finally {
            $db->query("DROP TABLE IF EXISTS $t");
        }
    }
}
