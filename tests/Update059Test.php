<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_migration_progress: created, and dropped by the exact inverse.
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

    private function exists(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists('owa_migration_progress');
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(59, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(59, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertContains('migration_progress', \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->entities);
    }

    public function testUpIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());
    }

    public function testDownDropsItAndUpPutsItBack(): void
    {
        $this->update->up();

        $rows = (int) (((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(*) AS n FROM owa_migration_progress'))['n'] ?? 0);

        if ($rows > 0) {
            $this->markTestSkipped("owa_migration_progress holds $rows rows; down() would drop them");
        }

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertFalse($this->exists());

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());
    }
}
