<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update002: the two recording tables, created and dropped.
 */
final class DomstreamUpdate002Test extends TestCase
{
    private \OWA\Module\Domstream\Update\Update002 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates tables');
        }

        $this->update = new \OWA\Module\Domstream\Update\Update002();
    }

    protected function tearDown(): void
    {
        if (isset($this->update)) {
            $this->update->up();
        }
    }

    /** @return string[] */
    private function tables(): array
    {
        return array_map(fn ($name) => \OWA\Core\CoreAPI::entityFactory($name)->getTableName(),
            \OWA\Module\Domstream\Update\Update002::ENTITIES);
    }

    private function exists(string $table): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists($table);
    }

    public function testTheModuleRequiresThisSchema(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $maps = $s->maps;

        try {
            $module = new \OWA\Module\Domstream\Module();
        } finally {
            $s->maps = $maps;
        }

        $this->assertSame($module->required_schema_version, $this->update->schema_version);
    }

    public function testUpCreatesBothAndIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());

        foreach ($this->tables() as $table) {
            $this->assertTrue($this->exists($table), $table);
        }
    }

    public function testDownDropsBothAndUpPutsThemBack(): void
    {
        $this->update->up();

        $chunk = $this->tables()[0];
        $rows = (int) (\OWA\Core\CoreAPI::dbSingleton()->get_row("SELECT COUNT(*) AS n FROM $chunk")['n'] ?? 0);

        if ($rows > 0) {
            $this->markTestSkipped("$chunk holds $rows recordings; down() would drop them");
        }

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');

        foreach ($this->tables() as $table) {
            $this->assertFalse($this->exists($table), "$table after down");
        }

        $this->assertTrue($this->update->up());

        foreach ($this->tables() as $table) {
            $this->assertTrue($this->exists($table), "$table after up");
        }
    }
}
