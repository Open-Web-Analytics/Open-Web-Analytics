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
     * Rotation needs a window. Without one it must do nothing at all -- not
     * fall back to a default that silently discards more than intended.
     *
     * A smoke test by nature: it asserts the absence of a side effect and that
     * nothing fatals on bad input. The parsing it depends on is pinned properly
     * by the resolveCutoff tests above, which fail under mutation; this one
     * would not catch a command that did nothing for some unrelated reason.
     */
    public function testRotateRequiresAUsableWindow()
    {
        $before = $this->partitionCount('owa_request');

        foreach ([[], ['keep' => ''], ['keep' => '0'], ['keep' => 'lots'], ['keep' => '-4']] as $params) {
            $this->rotate($params)->action();
        }

        $this->assertSame(
            $before,
            $this->partitionCount('owa_request'),
            'a rotation without a usable window must change nothing'
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
     * The budget can be stated outright.
     *
     * This is the considered escape hatch, as opposed to force: it makes the
     * operator name the number they are accepting -- which means looking at
     * innodb_open_files to choose it -- and it lands in the log as a figure the
     * next person can evaluate rather than as "the guard was switched off".
     */
    public function testAnExplicitBudgetOverridesTheDerivedOne()
    {
        $derived = $this->callProtected($this->rotate(), 'partitionLimit', [7]);

        $stated = $this->callProtected($this->rotate(['max-partitions' => '500']), 'partitionLimit', [7]);
        $this->assertSame(500, $stated['limit']);
        $this->assertStringContainsString('explicitly', $stated['reason']);

        // Junk falls back to the derived budget rather than to something permissive.
        foreach (['abc', '0', '-5', ''] as $bad) {
            $this->assertSame(
                $derived['limit'],
                $this->callProtected($this->rotate(['max-partitions' => $bad]), 'partitionLimit', [7])['limit'],
                var_export($bad, true) . ' should not be accepted as a budget'
            );
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
}
