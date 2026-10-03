<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/V1Schema.php';

/**
 * Update062: the v1 migration, as the blocking CLI update it runs as.
 *
 * Run against the frozen 1.14 schema under its own prefix. What the migration
 * writes is MigrateRequestsTest's; this is the update around it.
 */
final class Update062Test extends TestCase
{
    private const SITE = 'mig-update-site';

    private \OWA\Module\Base\Update\Update062 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        V1Schema::load();
        $this->clean();

        $this->update = new \OWA\Module\Base\Update\Update062();
        $this->update->prefix = V1Schema::PREFIX;
        // The installation's own raw table and cubes are not the tests' to re-partition.
        $this->update->partitioned_tables = [];

        // A site the migration recognises, and two page views a year apart.
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_site (id, site_id, domain, name) VALUES (?, ?, ?, ?)',
            [\OWA\Core\Lib::setStringGuid(self::SITE), self::SITE, 'mig-update.example.com', 'Migration update']);

        $this->request('1790000000000000301', 1790000000, 20260921);
        $this->request('1790000000000000302', 1758000000, 20250916);
    }

    protected function tearDown(): void
    {
        foreach (['since', 'all'] as $name) {
            \OWA\Core\CoreAPI::setRequestParam($name, null);
        }

        if (isset($this->savedLogDir)) {
            \OWA\Core\CoreAPI::setSetting('base', 'async_log_dir', $this->savedLogDir);
            exec('rm -rf ' . escapeshellarg($this->logDir));
        }

        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . V1Schema::PREFIX . 'queue_item');
            $this->clean();
            V1Schema::drop();
        }
    }

    private function clean(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query('DELETE FROM owa_event_raw WHERE site_id = ?', [self::SITE]);
        $db->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);
        $db->query("DELETE FROM owa_visitor_acquisition WHERE visitor_id = '1790000000000000311'");
        $db->query('DELETE FROM owa_site WHERE site_id = ?', [self::SITE]);
    }

    private function request(string $id, int $timestamp, int $day): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_v1fx_request (id, site_id, visitor_id, session_id, timestamp, yyyymmdd) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, self::SITE, '1790000000000000311', '1790000000000000321', $timestamp, $day]);
    }

    private function days(): array
    {
        return array_map('intval', array_column(array_map(fn ($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()
            ->get_results('SELECT DISTINCT yyyymmdd FROM owa_event_raw WHERE site_id = ? ORDER BY yyyymmdd',
                [self::SITE])), 'yyyymmdd'));
    }

    public function testItIsCliOnly(): void
    {
        $this->assertSame(62, $this->update->schema_version);
        $this->assertTrue($this->update->isCliModeRequired());
    }

    public function testWithoutAChoiceOfHistoryItFailsAndWritesNothing(): void
    {
        $this->assertFalse($this->update->up());
        $this->assertSame('a choice of how much history to migrate', $this->update->awaiting,
            'it waits for the operator rather than failing, so cmd=update says so instead of "failed"');

        \OWA\Core\CoreAPI::setRequestParam('since', '2years');
        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertFalse($this->update->up(), 'both is not a choice either');

        $this->assertSame([], $this->days());
    }

    public function testAnUnreadableCutoffFails(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('since', 'last tuesday');

        $this->assertFalse($this->update->up());
        $this->assertSame([], $this->days());
    }

    public function testAllMigratesEverything(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('all', true);

        $this->assertTrue($this->update->up());
        $this->assertSame([20250916, 20260921], $this->days());
    }

    public function testACutoffLeavesOlderHistoryBehind(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('since', '20260101');

        $this->assertTrue($this->update->up());
        $this->assertSame([20260921], $this->days());
    }

    /** v2's partitioned tables reach back to the oldest day migrated, and no further. */
    public function testPartitionsReachBackToTheOldestDayMigrated(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        if (!$db->supportsPartitioning()) {
            $this->markTestSkipped('Driver cannot partition.');
        }

        $table = 'owa_test_reach_' . bin2hex(random_bytes(4));
        $this->update->partitioned_tables = [$table];

        try {
            foreach ([['since', '20260101', '20260901'], ['all', true, '20250901']] as [$param, $value, $start]) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s', $table));
                $db->query(sprintf('CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL,'
                    . ' PRIMARY KEY (id, yyyymmdd))', $table));
                $db->partitionTable($table, 'yyyymmdd',
                    \OWA\Core\Db::makePartitionRanges('20260901', '20260930', 'daily'));

                foreach (['since', 'all'] as $name) {
                    \OWA\Core\CoreAPI::setRequestParam($name, null);
                }
                \OWA\Core\CoreAPI::setRequestParam($param, $value);
                $this->clean();
                $db->query('INSERT INTO owa_site (id, site_id, domain, name) VALUES (?, ?, ?, ?)',
                    [\OWA\Core\Lib::setStringGuid(self::SITE), self::SITE, 'mig-update.example.com', 'Migration update']);

                $this->assertTrue($this->update->up());
                $this->assertSame($start, $db->getPartitionSpans($table)[0]['start'], $param);
            }
        } finally {
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }

    public function testThePreflightCountsWhatWillBeLeftBehind(): void
    {
        $this->request('1790000000000000303', 1790000000, 20260921);
        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_v1fx_request SET site_id = 'mig-update-gone' WHERE id = 1790000000000000303");

        $lines = $this->update->preflight(new \OWA\Module\Base\Classes\Migration\RequestMigrator(V1Schema::PREFIX));

        $this->assertContains(sprintf('  %-40s %4d  %10d', self::SITE, 2026, 1), $lines);
        $this->assertContains('  Not migrated: 1 rows for 1 site ids no site carries any more.', $lines);
    }

    /** down() deletes what the migration wrote and nothing a beacon did. */
    public function testDownRemovesExactlyTheMigratedRows(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());

        $live = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $live->setProperties(['id' => '1790000000000000399', 'event_type' => 'page_view', 'site_id' => self::SITE,
            'visitor_id' => '1790000000000000311', 'session_id' => '1790000000000000399',
            'ts' => 1790000500 * 1000000, 'yyyymmdd' => 20260921]);
        $this->assertTrue((bool) $live->create());

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent');

        $left = array_column(array_map(fn ($r) => (array) $r, (array) \OWA\Core\CoreAPI::dbSingleton()
            ->get_results('SELECT id FROM owa_event_raw WHERE site_id = ?', [self::SITE])), 'id');

        $this->assertSame(['1790000000000000399'], array_map('strval', $left));

        $this->assertTrue($this->update->up(), 'and up runs again from the start');
        $this->assertSame([20250916, 20260921], $this->days());
    }

    /** Every pass, through the update, and back. */
    public function testEveryPassRunsAndDownUndoesThemAll(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $base = ['site_id' => self::SITE, 'visitor_id' => '1790000000000000311', 'session_id' => '1790000000000000321',
            'timestamp' => 1790000000, 'yyyymmdd' => 20260921];

        foreach ([
            ['click', ['id' => '1790000000000000351', 'click_x' => 1]],
            ['action_fact', ['id' => '1790000000000000352', 'action_name' => 'Download']],
            ['commerce_transaction_fact', ['id' => '1790000000000000353', 'order_id' => 'X-1', 'total_revenue' => 500]],
        ] as [$table, $row]) {
            $row += $base;
            $db->query(sprintf('INSERT INTO owa_v1fx_%s (%s) VALUES (%s)', $table, implode(',', array_keys($row)),
                implode(',', array_fill(0, count($row), '?'))), array_values($row));
        }

        $db->query('INSERT INTO owa_v1fx_referer (id, url) VALUES (?, ?)', ['701', 'https://ref.example/']);
        $db->query('INSERT INTO owa_v1fx_visitor (id, first_session_id) VALUES (?, ?)',
            ['1790000000000000311', '1790000000000000321']);
        $db->query('INSERT INTO owa_v1fx_session (id, site_id, visitor_id, timestamp, yyyymmdd, referer_id) VALUES (?, ?, ?, ?, ?, ?)',
            ['1790000000000000321', self::SITE, '1790000000000000311', 1790000000, 20260921, '701']);

        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());

        $types = array_count_values(array_column(array_map(fn ($r) => (array) $r, (array) $db->get_results(
            'SELECT event_type FROM owa_event_raw WHERE site_id = ?', [self::SITE])), 'event_type'));

        $this->assertSame(1, $types['click'] ?? 0);
        $this->assertSame(1, $types['download'] ?? 0);
        $this->assertSame(1, $types['purchase'] ?? 0);
        $this->assertSame(2, $types['page_view'] ?? 0);
        $this->assertTrue((bool) $db->get_row('SELECT visitor_id FROM owa_visitor_acquisition WHERE visitor_id = ?',
            ['1790000000000000311']));

        $this->assertTrue($this->update->down());

        $this->assertSame([], (array) $db->get_results('SELECT id FROM owa_event_raw WHERE site_id = ?', [self::SITE]));
        $this->assertFalse((bool) $db->get_row('SELECT visitor_id FROM owa_visitor_acquisition WHERE visitor_id = ?',
            ['1790000000000000311']));
        $this->assertSame([], (array) $db->get_results('SELECT id FROM owa_migration_progress WHERE site_id = ?', [self::SITE]));
    }

    /** After cmd=v1-drop there is nothing to revert to, and down() says so. */
    public function testDownIsRefusedOnceV1IsDropped(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'v1_tables_dropped', true);

        try {
            $this->assertFalse($this->update->down());
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'v1_tables_dropped', false);
        }
    }

    /** Empty v1 tables have no history to choose a cutoff for. */
    public function testEmptyV1TablesNeedNoChoiceOfHistory(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_v1fx_request');

        $this->assertTrue($this->update->up(), 'no since= or --all was given, and none is needed');
    }

    private function reconciled(string $class = 'RequestMigrator'): array
    {
        $class = '\\OWA\\Module\\Base\\Classes\\Migration\\' . $class;

        return (new $class(V1Schema::PREFIX))->reconcileSite(self::SITE);
    }

    /** A clean run reconciles: every v1 row is in v2, per day. */
    public function testACompleteMigrationReconciles(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());

        $days = $this->reconciled();

        $this->assertSame([20250916, 20260921], array_keys($days));
        $this->assertSame([], \OWA\Module\Base\Classes\Migration\FactMigrator::discrepancies($days));
        $this->assertSame(1, $days[20260921]['types']['page_view']['present']);
        $this->assertSame(1, $days[20260921]['visitors_present']);
    }

    /** A refused row is accounted for by its reason, not reported as missing. */
    public function testARefusedRowIsAccountedForNotMissing(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_v1fx_request (id, site_id, visitor_id, session_id, timestamp, yyyymmdd) VALUES (?, ?, ?, ?, ?, ?)',
            ['1790000000000000303', self::SITE, '0', '1790000000000000321', 1790000100, 20260921]);

        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());

        $days = $this->reconciled();

        $this->assertSame(2, $days[20260921]['read']);
        $this->assertSame(['no_visitor' => 1], $days[20260921]['refused']);
        $this->assertSame([], \OWA\Module\Base\Classes\Migration\FactMigrator::discrepancies($days));
        $this->assertStringContainsString('1 refused (no_visitor 1)',
            \OWA\Module\Base\Classes\Migration\FactMigrator::summary($days));
    }

    /** A row missing from v2 leaves the update pending, and names the day. */
    public function testAMissingRowLeavesTheUpdatePending(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());

        \OWA\Core\CoreAPI::dbSingleton()->query(
            "DELETE FROM owa_event_raw WHERE site_id = ? AND yyyymmdd = 20250916 AND event_type = 'page_view'",
            [self::SITE]);

        $wrong = \OWA\Module\Base\Classes\Migration\FactMigrator::discrepancies($this->reconciled());

        $this->assertContains('20250916 page_view: 1 expected, 0 in v2', $wrong);
        $this->assertFalse($this->update->up(), 'the pass is complete, so only the reconciliation can refuse');
    }

    /** Revenue is compared in minor units, not only the row count. */
    public function testADifferentRevenueIsADiscrepancy(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO owa_v1fx_commerce_transaction_fact (id, site_id, visitor_id, session_id, timestamp, yyyymmdd, order_id, total_revenue)"
            . " VALUES ('1790000000000000360', '%s', '1790000000000000311', '1790000000000000321', 1790000000, 20260921, 'R-1', 1250)",
            self::SITE));

        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());
        $this->assertSame([], \OWA\Module\Base\Classes\Migration\FactMigrator::discrepancies($this->reconciled('PurchaseMigrator')));

        \OWA\Core\CoreAPI::dbSingleton()->query(
            "UPDATE owa_event_raw SET revenue = 1 WHERE site_id = ? AND event_type = 'purchase'", [self::SITE]);

        $wrong = \OWA\Module\Base\Classes\Migration\FactMigrator::discrepancies($this->reconciled('PurchaseMigrator'));

        $this->assertSame(['20260921 purchase revenue: 1250 expected, 1 in v2 (minor units)'], $wrong);
    }

    public function testWithoutV1TablesThereIsNothingToDo(): void
    {
        $this->update->prefix = 'owa_nov1_';

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());
    }

    private ?string $savedLogDir = null;
    private string $logDir = '';

    /** A file queue of the test's own, so the install's is not read. */
    private function queueDir(): string
    {
        $this->savedLogDir = (string) \OWA\Core\CoreAPI::getSetting('base', 'async_log_dir');
        $this->logDir = sys_get_temp_dir() . '/owa-v1q-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->logDir . 'unprocessed', 0700, true);
        \OWA\Core\CoreAPI::setSetting('base', 'async_log_dir', $this->logDir);

        return $this->logDir;
    }

    /** Beacons 1.x queued to its file queue and never ingested stop the migration (PLAN 2.30.6). */
    public function testBeaconsTheOneXFileQueueNeverIngestedStopTheMigration(): void
    {
        $dir = $this->queueDir();
        file_put_contents($dir . 'unprocessed/incoming_tracking_events-eventfile-2026.txt',
            "12:00:00 2026-01-01|*|incoming_tracking_events|*|1|*|O%3A9%3A%22owa_event%22\n");
        file_put_contents($dir . 'events.txt', '{"r":0,"e":{"v":1}}' . "\n");

        $this->assertSame(1, $this->update->undrainedV1Queue()['file_lines'], 'v2\'s own JSON lines are not 1.x work left undone');

        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertFalse($this->update->up());
        $this->assertSame(0, (int) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(*) AS n FROM owa_event_raw WHERE site_id = ?', [self::SITE])['n'], 'nothing migrated');

        // Processed on 1.x, the upgrade goes ahead.
        unlink($dir . 'unprocessed/incoming_tracking_events-eventfile-2026.txt');
        $this->assertTrue($this->update->up());
    }

    /** Retries awaiting in owa_queue_item are counted, not a reason to refuse: they are v1 handler failures, dropped with the table. */
    public function testRetriesInTheOneXQueueTableDoNotStopIt(): void
    {
        $this->queueDir();

        $q = new \OWA\Module\Base\Update\Update068();
        $q->table = V1Schema::PREFIX . 'queue_item';
        $q->down();
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, status) VALUES (1, 'unhandled'), (2, 'unhandled'), (3, 'broken')", $q->table));

        $this->assertSame(array('file_lines' => 0, 'queue_rows' => 2), $this->update->undrainedV1Queue(),
            'only rows still awaiting a retry are counted');

        \OWA\Core\CoreAPI::setRequestParam('all', true);
        $this->assertTrue($this->update->up());
    }

    /** Checked before anything else, so an install whose v1 tables are empty is stopped too. */
    public function testTheCheckComesBeforeTheNothingToMigrateAnswer(): void
    {
        $dir = $this->queueDir();
        file_put_contents($dir . 'events.txt', "12:00:00 2026-01-01|*|incoming_tracking_events|*|1|*|x\n");

        $this->update->prefix = 'owa_nov1_';

        $this->assertFalse($this->update->up());
    }

    public function testAnEmptyOneXQueueDoesNotStopIt(): void
    {
        $this->queueDir();

        $this->assertSame(array('file_lines' => 0, 'queue_rows' => 0), $this->update->undrainedV1Queue());
    }
}
