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

    /**
     * partition-init is a one-time conversion, not a maintenance command.
     *
     * Everything it could usefully do to an already-partitioned table belongs to
     * another command: extending the lead and coarsening the tail is exactly
     * partition-rotate, and applying a granularity would convert only the
     * periods being added, leaving the rest as they were -- a silent, partial
     * reorganisation. So it reports and skips, and names the command that does
     * the job being asked for.
     */
    public function testInitLeavesAnAlreadyPartitionedTableAlone()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if (! $db->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition.');
        }

        $t = 'owa_test_initonce_' . $this->tok;

        try {
            $db->query("CREATE TABLE $t (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, PRIMARY KEY (id,yyyymmdd))");

            // A deliberately stale layout: short lead, fine tail. A maintaining
            // command would change all of it.
            $db->partitionTable($t, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                date('Ymd', strtotime(date('Ym') . '01 -60 months')),
                date('Ymd', strtotime(date('Ym') . '01 +1 month')),
                'monthly'
            ));

            $before = array_map(
                fn($s) => $s['name'] . ':' . $s['less_than'],
                $db->getPartitionSpans($t)
            );

            // Neither a plain run nor one asking for a different granularity may
            // touch it.
            foreach ([[], ['granularity' => 'half-month'], ['months-ahead' => '24']] as $params) {

                $ctrl = new \OWA\Module\Base\Controller\PartitionInitCli($params + ['table' => $t]);
                $ctrl->action();

                $this->assertSame(
                    $before,
                    array_map(fn($s) => $s['name'] . ':' . $s['less_than'], $db->getPartitionSpans($t)),
                    'init must not modify a partitioned table, whatever it is asked for: ' . json_encode($params)
                );
            }

        } finally {
            $db->query("DROP TABLE IF EXISTS $t");
        }
    }

    // -----------------------------------------------------------------------
    // partition-status
    // -----------------------------------------------------------------------

    private function statusCli(array $params = []): object
    {
        return new \OWA\Module\Base\Controller\PartitionStatusCli($params);
    }

    /**
     * A layout as describePartitionLayout() returns it, for driving the report
     * through states a live table cannot cheaply be put into.
     */
    private function layout(array $overrides = []): array
    {
        return $overrides + [
            'partitioned' => true,
            'spans'       => 24,
            'total'       => 25,
            'covers'      => ['start' => '20240101', 'end' => '20260101'],
            'granularity' => 'monthly',
            'tiers'       => [
                ['period' => 'monthly', 'count' => 24, 'start' => '20240101', 'end' => '20260101'],
            ],
            'catch_all'   => 'pmax',
            'through'     => '20260101',
            'ahead'       => 120,
            'lead'        => 4,
            'contents'    => ['rows' => 0, 'min' => null, 'max' => null],
        ];
    }

    private function report(array $layout, array $budget = ['limit' => 200, 'reason' => 'test']): string
    {
        return implode("\n", $this->callProtected($this->statusCli(), 'describeTable', ['owa_x', $layout, $budget]));
    }

    /**
     * The healthy case, which is most of them: say what is there, and do not
     * imply work that is not needed.
     */
    public function testStatusDescribesAHealthyTable()
    {
        $out = $this->report($this->layout());

        $this->assertStringContainsString('owa_x: 25 partitions (24 bounded + catch-all)', $out);
        $this->assertStringContainsString('13% of budget', $out, '25 of 200');
        $this->assertStringContainsString('2024-01-01 to 2026-01-01', $out);
        $this->assertStringContainsString('granularity   monthly', $out);
        $this->assertStringContainsString('empty', $out);
        $this->assertStringContainsString('4 partitions ahead', $out);
        $this->assertStringContainsString('120 days from today', $out);

        foreach (['ACTION', 'REJECTED', 'Rotate now', 'not partitioned'] as $alarm) {
            $this->assertStringNotContainsString($alarm, $out, "a healthy table must not say '$alarm'");
        }
    }

    /** An unpartitioned table gets one line and the command that fixes it. */
    public function testStatusDescribesAnUnpartitionedTable()
    {
        $out = $this->report($this->layout([
            'partitioned' => false, 'spans' => 0, 'total' => 0, 'covers' => null,
            'granularity' => null, 'tiers' => [], 'catch_all' => null,
            'through' => null, 'ahead' => null, 'lead' => 0, 'contents' => null,
        ]));

        $this->assertSame(
            'owa_x: not partitioned. Run cmd=partition-init to convert it.',
            $out,
            'nothing else is knowable, so nothing else is said'
        );
    }

    /**
     * The tiered case, which is why this is not reported as one granularity.
     *
     * The tail is years per partition and the head is the granularity in force.
     * Both must appear, and the granularity line must report the head -- that is
     * what the next rotate will cut new periods at.
     */
    public function testStatusDescribesTiersRatherThanOneGranularity()
    {
        $out = $this->report($this->layout([
            'spans' => 40, 'total' => 41,
            'covers' => ['start' => '20060101', 'end' => '20270101'],
            'granularity' => 'half-month',
            'tiers' => [
                ['period' => '5 years',    'count' => 3,  'start' => '20060101', 'end' => '20210101'],
                ['period' => '1 year',     'count' => 4,  'start' => '20210101', 'end' => '20250101'],
                ['period' => 'monthly',    'count' => 9,  'start' => '20250101', 'end' => '20251001'],
                ['period' => 'half-month', 'count' => 24, 'start' => '20251001', 'end' => '20271001'],
            ],
        ]));

        $this->assertStringContainsString('5 years', $out);
        $this->assertStringContainsString('1 year ', $out);
        $this->assertStringContainsString('half-month    24 partitions', $out);
        $this->assertStringContainsString('granularity   half-month', $out,
            'the granularity is the head, not the tail');
        $this->assertStringContainsString('merged to fit the budget', $out);
        $this->assertStringContainsString('OWA_PARTITION_DETAIL_MONTHS', $out,
            'name the setting that governs it');

    }

    /** Counts read as English, singular and plural, in the tiers and the lead. */
    public function testStatusCountsReadAsEnglish()
    {
        $one = $this->callProtected($this->statusCli(), 'describeTiers', [$this->layout([
            'tiers' => [['period' => '1 year', 'count' => 1, 'start' => '20250101', 'end' => '20260101']],
        ])]);

        $this->assertStringContainsString('1 partition,', $one[0]);
        $this->assertStringNotContainsString('1 partitions,', $one[0]);

        $many = $this->callProtected($this->statusCli(), 'describeTiers', [$this->layout()]);
        $this->assertStringContainsString('24 partitions,', $many[0]);

        $lead = $this->callProtected($this->statusCli(), 'describeLead', [$this->layout(['lead' => 1, 'ahead' => 1])]);
        $this->assertStringContainsString('1 partition ahead', $lead[0]);
        $this->assertStringContainsString('1 day from today', $lead[0]);

        $gone = $this->callProtected($this->statusCli(), 'describeLead', [$this->layout(['ahead' => -1])]);
        $this->assertStringContainsString('1 day ago', $gone[0]);
        $this->assertStringNotContainsString('1 days ago', $gone[0]);
    }

    /** A uniform table has one tier, and must not be told it was merged. */
    public function testStatusDoesNotClaimMergingOnAUniformTable()
    {
        $this->assertStringNotContainsString('merged', $this->report($this->layout()));
    }

    /**
     * Rows in the catch-all are worth reporting precisely, and worth not
     * alarming about: they are queryable, they are not at risk, and the next
     * rotate moves them into dated partitions.
     */
    public function testStatusReportsCatchAllContents()
    {
        $out = $this->report($this->layout([
            'contents' => ['rows' => 1234567, 'min' => '20260101', 'max' => '20260815'],
        ]));

        $this->assertStringContainsString('1,234,567 rows', $out, 'readable at scale');
        $this->assertStringContainsString('2026-01-01 to 2026-08-15', $out);
        $this->assertStringContainsString('queryable', $out);
        $this->assertStringContainsString('not at risk', $out);
        $this->assertStringContainsString('cannot prune', $out);
        $this->assertStringContainsString('partition-rotate', $out);
    }

    /**
     * A missing catch-all is the one layout state that loses data outright:
     * MySQL rejects a row that fits no partition rather than storing it
     * somewhere. It has to read as an alarm.
     */
    public function testStatusFlagsAMissingCatchAll()
    {
        $out = $this->report($this->layout(['catch_all' => null, 'contents' => null]));

        $this->assertStringContainsString('REJECTED', $out);
        $this->assertStringContainsString('partition-rotate', $out);
    }

    /** An unreadable catch-all is said to be unreadable, not reported as empty. */
    public function testStatusDoesNotReportAnUnreadableCatchAllAsEmpty()
    {
        $out = $this->report($this->layout(['contents' => null]));

        $this->assertStringContainsString('could not be read', $out);
        $this->assertStringNotContainsString('empty', $out);
    }

    /** An exhausted lead is the state that calls for action today. */
    public function testStatusFlagsAnExhaustedLead()
    {
        foreach ([-1 => '1 day ago', -45 => '45 days ago', 0 => '0 days ago'] as $ahead => $expected) {

            $out = $this->report($this->layout(['ahead' => $ahead, 'lead' => 0]));

            $this->assertStringContainsString('lead          NONE', $out, "ahead=$ahead");
            $this->assertStringContainsString($expected, $out, "ahead=$ahead");
            $this->assertStringContainsString('Rotate now', $out, "ahead=$ahead");
        }
    }

    /** An unrecognised granularity names the command that sets one deliberately. */
    public function testStatusExplainsAnUnrecognisedGranularity()
    {
        $out = $this->report($this->layout(['granularity' => null]));

        $this->assertStringContainsString('not recognised', $out);
        $this->assertStringContainsString('cut monthly', $out, 'say what will happen by default');
        $this->assertStringContainsString('partition-reorganize', $out);
    }

    /** The percentage is of the budget, and survives a nonsense budget. */
    public function testStatusReportsBudgetUse()
    {
        foreach ([[100, 25, '25%'], [25, 25, '100%'], [10, 25, '250%'], [0, 25, '0%']] as $case) {

            list($limit, $total, $expected) = $case;

            $this->assertStringContainsString(
                $expected . ' of budget',
                $this->report($this->layout(['total' => $total]), ['limit' => $limit, 'reason' => 'x']),
                "total $total of limit $limit"
            );
        }
    }

    /**
     * The summary is the part an operator acts on, so an exhausted lead outranks
     * everything else -- it is the only state where data is accumulating
     * somewhere it should not be.
     */
    public function testStatusSummaryPrioritisesAnExhaustedLead()
    {
        $out = implode("\n", $this->callProtected($this->statusCli(), 'summarise', [
            [
                'owa_a' => $this->layout(['ahead' => 90]),
                'owa_b' => $this->layout(['ahead' => -5]),
                'owa_c' => $this->layout(['partitioned' => false, 'covers' => null, 'ahead' => null, 'total' => 0]),
            ],
            ['limit' => 200, 'reason' => 'x'],
        ]));

        $this->assertStringContainsString('2 of 3 fact tables partitioned', $out);
        $this->assertStringContainsString('Not partitioned: owa_c', $out);
        $this->assertStringContainsString('ACTION: owa_b has run out of lead', $out);
        $this->assertStringNotContainsString('shortest lead runs out', $out,
            'a deadline is irrelevant while one table is already past it');
    }

    /** With every lead intact, the summary gives the earliest deadline. */
    public function testStatusSummaryGivesTheEarliestDeadline()
    {
        $out = implode("\n", $this->callProtected($this->statusCli(), 'summarise', [
            [
                'owa_a' => $this->layout(['ahead' => 200, 'total' => 30, 'covers' => ['start' => '20200101', 'end' => '20270101']]),
                'owa_b' => $this->layout(['ahead' => 31,  'total' => 12, 'covers' => ['start' => '20250101', 'end' => '20260601']]),
            ],
            ['limit' => 200, 'reason' => 'x'],
        ]));

        $this->assertStringContainsString('2 of 2 fact tables partitioned, 42 partitions in total', $out);
        $this->assertStringContainsString('covering 2020-01-01 to 2027-01-01', $out,
            'the overall range spans the widest of the tables');
        $this->assertStringContainsString('31 days from now', $out, 'the shortest lead sets the deadline');
        $this->assertStringContainsString(
            (new DateTimeImmutable('+31 days'))->format('Y-m-d'), $out
        );
        $this->assertStringNotContainsString('ACTION', $out);
        $this->assertStringNotContainsString('Not partitioned', $out);
    }

    /** Nothing partitioned at all: init, and no deadline to invent. */
    public function testStatusSummaryWhenNothingIsPartitioned()
    {
        $bare = $this->layout([
            'partitioned' => false, 'covers' => null, 'ahead' => null, 'total' => 0,
        ]);

        $out = implode("\n", $this->callProtected($this->statusCli(), 'summarise', [
            ['owa_a' => $bare, 'owa_b' => $bare],
            ['limit' => 200, 'reason' => 'x'],
        ]));

        $this->assertStringContainsString('0 of 2 fact tables partitioned, 0 partitions in total.', $out);
        $this->assertStringContainsString('cmd=partition-init converts them', $out);
        $this->assertStringNotContainsString('covering', $out, 'no range to report');
        $this->assertStringNotContainsString('deadline', $out);
        $this->assertStringNotContainsString('ACTION', $out);
    }

    /**
     * The open-file budget belongs to the server, not to the invocation.
     *
     * Sizing it from the tables named on the command line would hand
     * `table=owa_session` several times the allowance of the same command
     * without a filter -- reporting a table as comfortably inside a budget it is
     * in fact straining, and letting the mutating commands build to it.
     */
    public function testBudgetIsSizedAgainstEveryFactTableNotTheFilteredSet()
    {
        $all = $this->callProtected($this->statusCli(), 'factTableBudget');

        $this->assertSame(
            $all['limit'],
            $this->callProtected($this->statusCli(['table' => 'owa_session']), 'factTableBudget')['limit'],
            'filtering the report must not change the budget'
        );

        foreach ([$this->rotate(), $this->drop()] as $other) {
            $this->assertSame($all['limit'], $this->callProtected($other, 'factTableBudget')['limit'],
                'every command sizes the budget the same way');
        }
    }

    /**
     * End to end against the real fact tables: the command must run, describe
     * every one of them, and report a total that matches what is on disk.
     */
    public function testStatusRunsAgainstTheRealFactTables()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if (! $db->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition.');
        }

        $ctrl   = $this->statusCli();
        $tables = $this->callProtected($ctrl, 'factTables');
        $budget = $this->callProtected($ctrl, 'factTableBudget');

        $this->assertNotEmpty($tables, 'the entity registry must yield fact tables');

        $layouts = [];

        foreach ($tables as $table) {
            $layout = $db->describePartitionLayout($table);
            $layout['contents'] = $layout['catch_all']
                ? $db->getPartitionContents($table, $layout['catch_all'])
                : null;
            $layouts[$table] = $layout;

            $lines = $this->callProtected($ctrl, 'describeTable', [$table, $layout, $budget]);

            $this->assertNotEmpty($lines, "$table produced no report");
            $this->assertStringStartsWith($table . ':', $lines[0]);

            if ($layout['partitioned']) {
                $this->assertSame(
                    count($db->listPartitions($table)),
                    $layout['total'],
                    "$table: the reported total must match the partitions on disk"
                );
                $this->assertSame(
                    count($db->getPartitionSpans($table)),
                    $layout['spans'],
                    "$table: bounded partitions exclude the catch-all"
                );
            }
        }

        $summary = implode("\n", $this->callProtected($ctrl, 'summarise', [$layouts, $budget]));

        $this->assertStringContainsString(
            sprintf('of %d fact tables partitioned', count($tables)),
            $summary
        );
    }

    /** A table that is not a fact table is refused, not reported on. */
    public function testStatusRefusesATableItDoesNotPartition()
    {
        $this->assertSame(
            [],
            $this->callProtected($this->statusCli(), 'factTables', ['owa_user']),
            'partitioning applies to the fact tables only'
        );
    }
}
