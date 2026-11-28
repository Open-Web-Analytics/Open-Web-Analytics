<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update102;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update102: a stored auto_update_level of patch becomes minor at every
 * scope, and down() puts back every row up() changed.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update102Test extends TestCase
{
    const TABLE = 'owa_setting_update102_test';

    private Update102 $update;

    protected function setUp(): void
    {
        $this->update = new Update102();

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

    private function row(string $scope, string $id, string $name, $value, int $created = 1, string $module = 'base'): void
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
        return array_diff_key($this->rows(), ['install:1:base.' . Update102::BEFORE => true]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(102, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(102, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertSame('', $this->update->down_does_not_restore, 'down() is the exact inverse');
    }

    /** patch is no longer an option; minor, which replaces it, is; the record is declared. */
    public function testTheDeclarations(): void
    {
        $c        = \OWA\Core\CoreAPI::configSingleton();
        $declared = (array) (include \OWA\Core\Env::root() . '/modules/Base/settings.php')['settings'][Update102::NAME];

        $this->assertArrayNotHasKey(Update102::RETIRED, $declared['options']);
        $this->assertArrayHasKey(Update102::REPLACEMENT, $declared['options']);
        $this->assertTrue($c->isRegistered('base', Update102::BEFORE));
        $this->assertNotSame([], \OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck::levelProblems(Update102::RETIRED), 'the constant is refused at boot');
    }

    public function testNoPatchRowWritesNothing(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('install', '1', 'auto_update_level', 'major');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /**
     * The settings the update process saves are kept in step: boot loaded the
     * install row, and a save of the old value would put it straight back.
     */
    public function testTheSettingsInMemoryFollowTheTable(): void
    {
        $this->requireDb();

        $config = new class {
            public $db_settings = ['base' => ['auto_update_level' => 'patch', 'timezone' => 'UTC']];
            public $set = [];
            public function removeSetting($module, $key) { unset($this->db_settings[$module][$key]); }
            public function set($module, $key, $value) { $this->set[$module][$key] = $value; }
        };
        $this->update->settings = $config;

        $this->row('install', '1', 'auto_update_level', 'patch', 5);

        $this->assertTrue($this->update->up());
        $this->assertSame(['base' => ['auto_update_level' => 'minor', 'timezone' => 'UTC']], $config->db_settings,
            'the closing save writes minor, not patch');

        $this->assertTrue($this->update->down());
        $this->assertSame(['base' => ['auto_update_level' => 'patch', 'timezone' => 'UTC']], $config->db_settings,
            'and patch again after down()');
    }

    /** Every patch row becomes minor, at every scope, others stay; down() is the exact inverse. */
    public function testPatchBecomesMinorAndComesBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'auto_update_level', 'patch', 5);
        $this->row('profile', 'abc', 'auto_update_level', 'patch', 6);
        $this->row('property', 'def', 'auto_update_level', 'major', 7);
        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('install', '1', 'link_template', 'patch', 8);
        $this->row('profile', 'abc', 'auto_update_level', 'patch', 9, 'other_module');
        $before = $this->rows();

        $this->assertTrue($this->update->up());

        $after = $this->settings();
        $values = array_map(static fn ($row) => unserialize($row['value']), $after);
        $this->assertSame([
            'install:1:base.auto_update_level'          => 'minor',
            'install:1:base.link_template'              => 'patch',
            'install:1:base.timezone'                   => 'UTC',
            'profile:abc:base.auto_update_level'        => 'minor',
            'profile:abc:other_module.auto_update_level' => 'patch',
            'property:def:base.auto_update_level'       => 'major',
        ], $values);
        $this->assertSame(array_diff_key($before, ['install:1:base.auto_update_level' => 1, 'profile:abc:base.auto_update_level' => 1]),
            array_diff_key($after, ['install:1:base.auto_update_level' => 1, 'profile:abc:base.auto_update_level' => 1]), 'nothing else changes');

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->settings(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'the exact inverse');

        $this->assertTrue($this->update->down(), 'down again');
        $this->assertSame($before, $this->rows());
    }
}
