<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update::tableFor(): the table a test put in $tables, else the entity's
 * table for a module.entity key, else the bare name under the table prefix.
 */
final class UpdateTableForTest extends TestCase
{
    public function testAnEntityKeyNamesTheEntitysTable(): void
    {
        $u = new \OWA\Core\Update();

        $this->assertSame(
            \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName(),
            $u->tableFor('base.setting'));
    }

    public function testABareNameIsUnderTheTablePrefix(): void
    {
        $u = new \OWA\Core\Update();

        $this->assertSame(\OWA\Core\Entity::TABLE_PREFIX . 'queue_item', $u->tableFor('queue_item'));
    }

    public function testATableInTablesWinsForItsKeyOnly(): void
    {
        $u = new \OWA\Core\Update();
        $u->tables = ['base.setting' => 'owa_scratch_settings', 'queue_item' => 'owa_scratch_queue'];

        $this->assertSame('owa_scratch_settings', $u->tableFor('base.setting'));
        $this->assertSame('owa_scratch_queue', $u->tableFor('queue_item'));
        $this->assertSame(
            \OWA\Core\CoreAPI::entityFactory('base.user')->getTableName(),
            $u->tableFor('base.user'));
    }
}
