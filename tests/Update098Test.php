<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update098: owa_login_failure, created and dropped by the exact inverse.
 */
final class Update098Test extends TestCase
{
    private \OWA\Module\Base\Update\Update098 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update098();
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->update->up();
        }
    }

    private function exists(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists('owa_login_failure');
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(98, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(98, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertContains('login_failure', \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->entities,
            'registered, so a fresh install creates it');
    }

    public function testUpIsIdempotentAndKeysTheTableBySubject(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());

        $keys = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW KEYS FROM owa_login_failure WHERE Key_name = 'PRIMARY'") as $row) {
            $keys[] = ((array) $row)['Column_name'];
        }

        $this->assertSame(['subject'], $keys);
    }

    public function testDownDropsItAndUpPutsItBack(): void
    {
        $this->update->up();

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertFalse($this->exists());

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists());
    }
}
