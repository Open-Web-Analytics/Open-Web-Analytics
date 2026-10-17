<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_app_password: created, dropped by the exact inverse, and the same
 * shape a fresh install gets from the entity.
 */
final class Update069Test extends TestCase
{
    const SCRATCH = 'owa_app_password_update069_test';

    const ENTITY_SCRATCH = 'app_password_entity069_test';

    private \OWA\Module\Base\Update\Update069 $update;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates a table');
        }

        $this->update = new \OWA\Module\Base\Update\Update069();
        $this->update->table = self::SCRATCH;
        $this->dropScratch();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->dropScratch();
        }
    }

    private function dropScratch(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::SCRATCH);
        $db->query('DROP TABLE IF EXISTS owa_' . self::ENTITY_SCRATCH);
    }

    private function exists(string $table): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists($table);
    }

    /** SHOW CREATE TABLE with the table's own name taken out. */
    private function shape(string $table): string
    {
        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row('SHOW CREATE TABLE ' . $table);
        $ddl = (string) ($row['Create Table'] ?? '');

        return str_replace("`$table`", '`T`', $ddl);
    }

    public function testTheModuleRequiresIt(): void
    {
        $base = \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');

        $this->assertSame(69, $this->update->schema_version);
        $this->assertGreaterThanOrEqual(69, $base->required_schema_version);
        $this->assertContains('app_password', $base->entities);
    }

    public function testUpIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists(self::SCRATCH));
    }

    public function testDownDropsItAndUpPutsItBack(): void
    {
        $this->update->up();

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent too');
        $this->assertFalse($this->exists(self::SCRATCH));

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->exists(self::SCRATCH));
    }

    /** An upgraded install and a fresh one must not be told apart. */
    public function testTheUpdateCreatesWhatTheEntityDeclares(): void
    {
        $this->update->up();

        $entity = \OWA\Core\CoreAPI::entityFactory('base.app_password');
        $entity->setTableName(self::ENTITY_SCRATCH);
        $this->assertNotFalse($entity->createTable());

        $this->assertSame($this->shape('owa_' . self::ENTITY_SCRATCH), $this->shape(self::SCRATCH));
    }
}
