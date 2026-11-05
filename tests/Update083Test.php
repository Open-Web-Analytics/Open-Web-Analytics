<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update083: owa_spam_block, created and dropped by the exact inverse.
 */
final class Update083Test extends TestCase
{
    private \OWA\Module\Base\Update\Update083 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update083();
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->update->up();
        }
    }

    private function exists(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists('owa_spam_block');
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(83, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(83, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertContains('spam_block', \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->entities,
            'registered, so a fresh install creates it');
    }

    public function testUpIsIdempotentAndKeysTheTable(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());

        $keys = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW KEYS FROM owa_spam_block WHERE Key_name = 'PRIMARY'") as $row) {
            $row = (array) $row;
            $keys[(int) $row['Seq_in_index']] = $row['Column_name'];
        }

        ksort($keys);

        $this->assertSame(['yyyymmdd', 'site_id', 'rule'], array_values($keys));
    }

    public function testDownDropsItAndUpPutsItBack(): void
    {
        $this->update->up();

        $rows = (int) (((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT COUNT(*) AS n FROM owa_spam_block'))['n'] ?? 0);

        if ($rows > 0) {
            $this->markTestSkipped("owa_spam_block holds $rows rows; down() would drop them");
        }

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertFalse($this->exists());

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());
    }
}
