<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_job_queue: created, and dropped by the exact inverse.
 */
final class Update067Test extends TestCase
{
    private \OWA\Module\Base\Update\Update067 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update067();
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->update->up();
        }
    }

    private function exists(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists('owa_job_queue');
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(67, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(67, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertContains('job_queue', \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->entities);
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
            'SELECT COUNT(*) AS n FROM owa_job_queue'))['n'] ?? 0);

        if ($rows > 0) {
            $this->markTestSkipped("owa_job_queue holds $rows rows; down() would drop them");
        }

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertFalse($this->exists());

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());
    }
}
