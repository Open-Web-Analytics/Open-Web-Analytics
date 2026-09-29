<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/V1Schema.php';

/**
 * Update061: the v1 migration, as the blocking CLI update it runs as.
 *
 * Run against the frozen 1.14 schema under its own prefix. What the migration
 * writes is MigrateRequestsTest's; this is the update around it.
 */
final class Update061Test extends TestCase
{
    private const SITE = 'mig-update-site';

    private \OWA\Module\Base\Update\Update061 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        V1Schema::load();
        $this->clean();

        $this->update = new \OWA\Module\Base\Update\Update061();
        $this->update->prefix = V1Schema::PREFIX;

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

        if (owa_test_db_available()) {
            $this->clean();
            V1Schema::drop();
        }
    }

    private function clean(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query('DELETE FROM owa_event_raw WHERE site_id = ?', [self::SITE]);
        $db->query('DELETE FROM owa_migration_progress WHERE site_id = ?', [self::SITE]);
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
        $this->assertSame(61, $this->update->schema_version);
        $this->assertTrue($this->update->isCliModeRequired());
    }

    public function testWithoutAChoiceOfHistoryItFailsAndWritesNothing(): void
    {
        $this->assertFalse($this->update->up());

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

    public function testWithoutV1TablesThereIsNothingToDo(): void
    {
        $this->update->prefix = 'owa_nov1_';

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());
    }
}
