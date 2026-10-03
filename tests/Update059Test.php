<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_migration_progress, owa_migration_tally and owa_migration_day_visitor:
 * created, and dropped by the exact inverse.
 */
final class Update059Test extends TestCase
{
    private \OWA\Module\Base\Update\Update059 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update059();
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->update->up();
        }
    }

    private const TABLES = ['owa_migration_progress', 'owa_migration_tally', 'owa_migration_day_visitor'];

    /** @return bool[] table => exists */
    private function exists(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists($t);
        }

        return $out;
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(59, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(59, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        foreach (['migration_progress', 'migration_tally', 'migration_day_visitor'] as $entity) {
            $this->assertContains($entity, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->entities,
                'a fresh install creates it too');
        }
    }

    public function testUpIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());
        $this->assertSame(array_fill_keys(self::TABLES, true), $this->exists());
    }

    public function testDownDropsItAndUpPutsItBack(): void
    {
        $this->update->up();

        foreach (self::TABLES as $t) {
            $rows = (int) (((array) \OWA\Core\CoreAPI::dbSingleton()->get_row("SELECT COUNT(*) AS n FROM $t"))['n'] ?? 0);

            if ($rows > 0) {
                $this->markTestSkipped("$t holds $rows rows; down() would drop them");
            }
        }

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertSame(array_fill_keys(self::TABLES, false), $this->exists());

        $this->assertTrue($this->update->up());
        $this->assertSame(array_fill_keys(self::TABLES, true), $this->exists());
    }
}
