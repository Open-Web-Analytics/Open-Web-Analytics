<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update091;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update091: every stored row of the removed Hello example module is deleted,
 * at every scope, and down() puts back every row up() removed.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update091Test extends TestCase
{
    const TABLE = 'owa_setting_update091_test';

    private Update091 $update;

    protected function setUp(): void
    {
        $this->update = new Update091();

        if (!owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE,
            \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName()));

        $this->update->tables = ['base.setting' => self::TABLE];
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('moves rows of the settings table');
        }
    }

    private function row(string $scope, string $id, string $name, $value, int $created = 1, string $module = 'hello'): void
    {
        $setting = \OWA\Core\CoreAPI::entityFactory('base.setting');

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            self::TABLE), [$setting->makeId($scope, $id, $module, $name), $scope, $id, $module, $name, serialize($value), $created]);
    }

    /** @return array<string,array> "scope:id:module.name" => every column, every row */
    private function rows(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SELECT * FROM ' . self::TABLE) as $row) {
            $row = (array) $row;
            $out[$row['scope_type'] . ':' . $row['scope_id'] . ':' . $row['module'] . '.' . $row['name']] = $row;
        }

        ksort($out);

        return $out;
    }

    /** @return array<string,array> the rows, without the record up() keeps */
    private function settings(): array
    {
        return array_diff_key($this->rows(), ['install:1:base.' . Update091::BEFORE => true]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(91, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(91, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertSame('', $this->update->down_does_not_restore, 'down() is the exact inverse');
    }

    /** The module is gone from disk, and its record is declared. */
    public function testTheModuleIsGoneAndTheRecordIsDeclared(): void
    {
        $this->assertDirectoryDoesNotExist(\OWA\Core\Env::modulesDir() . 'Hello');
        $this->assertFalse(class_exists('OWA\\Module\\Hello\\Module'));
        $this->assertNotContains('Hello', \OWA\Core\CoreAPI::getPresentModules());
        $this->assertTrue(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', Update091::BEFORE));
    }

    public function testNoStoredRowWritesNothing(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'timezone', 'UTC', 1, 'base');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /**
     * The settings the update process saves are kept in step: boot loaded
     * hello's rows, and a save of the old values put them straight back.
     *
     * @covers-setting base.hello_settings_before_091
     */
    public function testTheSettingsInMemoryFollowTheTable(): void
    {
        $this->requireDb();

        $config = new class {
            public $db_settings = ['hello' => ['is_active' => true, 'schema_version' => 1], 'base' => ['timezone' => 'UTC']];
            public function removeSetting($module, $key) { unset($this->db_settings[$module][$key]); }
        };
        $this->update->settings = $config;

        $this->row('install', '1', 'is_active', true, 5);
        $this->row('install', '1', 'schema_version', 1, 6);
        $this->row('property', '42', 'is_active', false, 7);

        $this->assertTrue($this->update->up());
        $this->assertSame(['hello' => [], 'base' => ['timezone' => 'UTC']], $config->db_settings,
            'the deleted rows are still held, and the closing save writes them back');

        $this->assertTrue($this->update->down());
        $held = $config->db_settings['hello'];
        ksort($held);
        $this->assertSame(['is_active' => true, 'schema_version' => 1], $held,
            'the restored rows are not held, and the closing save prunes them again');
    }

    /**
     * Every hello row goes, at every scope, others stay; down() is the exact inverse.
     *
     * @covers-setting base.hello_settings_before_091
     */
    public function testTheHelloRowsGoAndComeBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'is_active', true, 5);
        $this->row('install', '1', 'schema_version', 1, 6);
        $this->row('install', '1', 'greeting', 'hi', 7);
        $this->row('profile', 'update091-a', 'greeting', 'hey', 8);
        $this->row('install', '1', 'timezone', 'UTC', 1, 'base');
        $this->row('install', '1', 'is_active', true, 9, 'domstream');
        $this->row('install', '1', 'hello', 'kept', 10, 'other_module');
        $before = $this->rows();

        $this->assertTrue($this->update->up());

        $after = $this->settings();
        $this->assertSame(['install:1:base.timezone', 'install:1:domstream.is_active', 'install:1:other_module.hello'], array_keys($after));

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->settings(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'the exact inverse');

        $this->assertTrue($this->update->down(), 'down again');
        $this->assertSame($before, $this->rows());
    }
}
