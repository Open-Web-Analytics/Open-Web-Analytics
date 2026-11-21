<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\TableInventory;

/**
 * cmd=tables-cleanup (Classes\TableInventory): reports the tables nothing in
 * the running code owns, drops only those and only when asked, and leaves the
 * cubes and the 1.x tracking tables alone.
 *
 * Against fixture tables under a prefix of their own.
 */
final class TablesCleanupCliTest extends CliControllerTestCase
{
    private const TABLES = ['site', 'event_raw', 'event_7', 'event_7_rebuild', 'request', 'exit', 'removed_module_log'];

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'owa_zztc' . getmypid() . '_';

        foreach (self::TABLES as $t) {
            $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->query(
                sprintf('CREATE TABLE %s%s (id BIGINT PRIMARY KEY)', $this->prefix, $t)));
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s%s', $this->prefix, $t));
        }

        parent::tearDown();
    }

    public function testEachTableIsWhatTheCodeMakesOfIt(): void
    {
        $p = $this->prefix;

        $this->assertSame([
            'application' => [$p . 'site'],
            'data'        => [$p . 'event_raw'],
            'cube'        => [$p . 'event_7', $p . 'event_7_rebuild'],
            'v1'          => [$p . 'request'],
            'old'         => [$p . 'exit', $p . 'removed_module_log'],
        ], (new TableInventory(\OWA\Core\CoreAPI::dbSingleton(), $p))->classify());
    }

    public function testWithoutDropNothingIsDropped(): void
    {
        [$cli, $out] = $this->run_([]);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome']);
        $this->assertStringContainsString($this->prefix . 'exit', $out);
        $this->assertStringContainsString($this->prefix . 'removed_module_log', $out);
        $this->assertStringNotContainsString($this->prefix . 'site', $out);
        $this->assertStringContainsString('1 1.x tracking table left to cmd=v1-drop', $out);
        $this->assertSame(self::TABLES, $this->present());
    }

    public function testDropRemovesOnlyTheOldTables(): void
    {
        [$cli, $out] = $this->run_(['drop' => true]);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertStringContainsString('Dropped 2 tables.', $out);
        $this->assertSame(['site', 'event_raw', 'event_7', 'event_7_rebuild', 'request'], $this->present(),
            'what the code owns, the cubes, and the 1.x table v1-drop keeps a record of');
        $this->assertFalse((bool) \OWA\Core\CoreAPI::getSetting('base', 'v1_tables_dropped'));
    }

    /** A table a pending update is about to rename has no entity yet, so this waits for cmd=update. */
    public function testItWaitsForPendingUpdates(): void
    {
        $this->assertTrue((new \OWA\Module\Base\Controller\TablesCleanupCli([]))->needsCurrentSchema());
    }

    /** @return array{0: \OWA\Module\Base\Controller\TablesCleanupCli, 1: string} what it wrote */
    private function run_(array $params): array
    {
        $cli = new class($params) extends \OWA\Module\Base\Controller\TablesCleanupCli {
            public array $lines = [];
            protected function write($lines) { foreach ((array) $lines as $l) { $this->lines[] = (string) $l; } }
        };
        $cli->prefix = $this->prefix;
        $cli->action();

        return [$cli, implode("\n", $cli->lines)];
    }

    /** @return list<string> the fixture tables still present, in TABLES order */
    private function present(): array
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        return array_values(array_filter(self::TABLES, fn ($t) => $db->tableExists($this->prefix . $t)));
    }
}
