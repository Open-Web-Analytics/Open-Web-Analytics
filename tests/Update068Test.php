<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_queue_item goes (PLAN 2.30.6); down() puts the table back as it was.
 * Update062 has already refused an upgrade that left unprocessed rows in it
 * (Update062Test).
 *
 * Against a scratch table (Update068::$table), so the install's own is left
 * for cmd=update.
 */
final class Update068Test extends TestCase
{
    private const TABLE = 'owa_queue_item_phpunit';

    private \OWA\Module\Base\Update\Update068 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('drops a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update068();
        $this->update->table = self::TABLE;

        \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->update->down();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }

    }

    private function exists(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists(self::TABLE);
    }

    private function row(string $status): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            'INSERT INTO %s (id, event_type, status) VALUES (?, ?, ?)', self::TABLE),
            array((string) random_int(1, PHP_INT_MAX), 'page_view', $status));
    }

    public function testTheModuleRequiresItAndNoLongerDeclaresTheEntity(): void
    {
        $base = \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');

        $this->assertSame(68, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(68, $base->required_schema_version);
        $this->assertNotContains('queue_item', $base->entities);
    }

    /** By now it holds only rows already handled or given up on, and they go with it. */
    public function testTheTableIsDropped(): void
    {
        $this->row('handled');
        $this->row('broken');

        $this->assertTrue($this->update->up());
        $this->assertFalse($this->exists());
        $this->assertTrue($this->update->up(), 'idempotent');
    }

    /** The exact inverse: the table's shape as the entity made it. */
    public function testDownPutsTheTableBackAsItWas(): void
    {
        $this->update->up();
        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'idempotent');

        $create = (string) \OWA\Core\CoreAPI::dbSingleton()->get_row('SHOW CREATE TABLE ' . self::TABLE)['Create Table'];

        foreach (array('`id` bigint NOT NULL', '`event` blob', '`insertion_datestamp` timestamp NULL',
                       '`not_before_timestamp` int', '`is_assigned` tinyint(1)', 'PRIMARY KEY (`id`)') as $piece) {
            $this->assertStringContainsString($piece, $create);
        }
    }
}
