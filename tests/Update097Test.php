<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update097: replaces and replaces_hash on owa_custom_report.
 */
final class Update097Test extends TestCase
{
    private \OWA\Module\Base\Update\Update097 $update;

    protected function setUp(): void
    {
        $this->update = new \OWA\Module\Base\Update\Update097();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->update->up();
        }
    }

    /** @return string[] the two columns the table has, in table order */
    private function columns(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SHOW COLUMNS FROM owa_custom_report') as $row) {
            $field = ((array) $row)['Field'];

            if (in_array($field, \OWA\Module\Base\Update\Update097::COLUMNS, true)) {
                $out[] = $field;
            }
        }

        return $out;
    }

    public function testTheModuleRequiresIt(): void
    {
        $this->assertSame(97, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(97, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** The entity declares the same two, last, in the order ADD COLUMN appends them. */
    public function testTheEntityDeclaresThem(): void
    {
        $columns = \OWA\Core\CoreAPI::entityFactory('base.custom_report')->getColumns();

        $this->assertSame(\OWA\Module\Base\Update\Update097::COLUMNS, array_slice($columns, -2));
    }

    public function testUpDownAndAgain(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters owa_custom_report');
        }

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'up is idempotent');
        $this->assertSame(['replaces', 'replaces_hash'], $this->columns());

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent');
        $this->assertSame([], $this->columns());

        $this->assertTrue($this->update->up());
        $this->assertSame(['replaces', 'replaces_hash'], $this->columns());
    }

    /** down() takes the overrides with the columns: without them they would be custom reports on the roster. */
    public function testDownDeletesTheOverridesAndKeepsTheCustomReports(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters owa_custom_report');
        }

        $this->assertTrue($this->update->up());

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory('base.custom_report')->getTableName();

        $db->query("INSERT INTO $table (id, name, user_id, property_id, definition, replaces) VALUES (?, ?, ?, ?, ?, ?)",
            ['7781000000000301', 'An override', 'update097@example.test', '7781000000000201', '{}', 'dashboard']);
        $db->query("INSERT INTO $table (id, name, user_id, property_id, definition, replaces) VALUES (?, ?, ?, ?, ?, ?)",
            ['7781000000000302', 'A custom report', 'update097@example.test', '7781000000000201', '{}', '']);

        try {
            $this->assertTrue($this->update->down());

            $ids = array_column((array) $db->get_results("SELECT id FROM $table WHERE user_id = ?", ['update097@example.test']), 'id');

            $this->assertSame(['7781000000000302'], array_map('strval', $ids));
        } finally {
            $this->update->up();
            $db->query("DELETE FROM $table WHERE user_id = ?", ['update097@example.test']);
        }
    }
}
