<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update090;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update090: every stored base.domain_aliases row is deleted, at every scope,
 * and a second run changes nothing.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update090Test extends TestCase
{
    const TABLE = 'owa_setting_update090_test';

    private Update090 $update;

    protected function setUp(): void
    {
        $this->update = new Update090();

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
            $this->markTestSkipped('deletes rows of the settings table');
        }
    }

    private function row(string $scope, string $id, string $name, $value, string $module = 'base'): void
    {
        $setting = \OWA\Core\CoreAPI::entityFactory('base.setting');

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date) VALUES (?, ?, ?, ?, ?, ?, 0, 1)",
            self::TABLE), [$setting->makeId($scope, $id, $module, $name), $scope, $id, $module, $name, serialize($value)]);
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

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(90, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(90, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertNotSame('', $this->update->down_does_not_restore);
    }

    /** What it deletes no module declares. */
    public function testNoModuleDeclaresTheSetting(): void
    {
        $this->assertFalse(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', Update090::NAME));
    }

    /** Every domain_aliases row goes, install and Profile alike; other rows stay. A second run changes nothing. */
    public function testEveryRowGoesAndASecondRunIsANoOp(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'domain_aliases', 'example.org');
        $this->row('profile', 'update090-a', 'domain_aliases', 'example.net, example.io');
        $this->row('profile', 'update090-b', 'domain_aliases', '');
        $this->row('profile', 'update090-a', 'query_string_filters', 'sessionid');
        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('install', '1', 'domain_aliases', 'kept', 'other_module');

        $this->assertTrue($this->update->up());

        $after = $this->rows();
        $this->assertSame(['install:1:base.timezone', 'install:1:other_module.domain_aliases',
            'profile:update090-a:base.query_string_filters'], array_keys($after));

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->rows(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($after, $this->rows(), 'down() puts nothing back');
    }

    public function testNoStoredRowChangesNothing(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'timezone', 'UTC');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());
    }

    /** The settings the update run saves at its end no longer hold the value, or the save writes it back. */
    public function testTheSettingsInMemoryFollowTheTable(): void
    {
        $this->requireDb();

        $config = new class {
            public $db_settings = ['base' => ['domain_aliases' => 'example.org', 'timezone' => 'UTC']];
            public function removeSetting($module, $key) { unset($this->db_settings[$module][$key]); }
        };
        $this->update->settings = $config;

        $this->row('install', '1', 'domain_aliases', 'example.org');

        $this->assertTrue($this->update->up());
        $this->assertSame(['base' => ['timezone' => 'UTC']], $config->db_settings);
    }
}
