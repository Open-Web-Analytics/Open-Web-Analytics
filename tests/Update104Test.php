<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update104;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update104: the stored rows of tracker_url_fragments are deleted at every
 * scope, and down() puts back every row up() removed.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update104Test extends TestCase
{
    const TABLE = 'owa_setting_update104_test';

    private Update104 $update;

    protected function setUp(): void
    {
        $this->update = new Update104();

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
        return array_diff_key($this->rows(), ['install:1:base.' . Update104::BEFORE => true]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(104, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(104, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertSame('', $this->update->down_does_not_restore, 'down() is the exact inverse');
    }

    /** The retired settings are declared by no module and read by no getSetting(); the record is declared. */
    public function testTheDeclarations(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach (Update104::RETIRED as $name) {
            $this->assertFalse($c->isRegistered('base', $name), $name);
        }
        $this->assertTrue($c->isRegistered('base', Update104::BEFORE));

        $readers = [];

        foreach (['modules', 'Core'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\OWA\Core\Env::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (substr((string) $file, -4) !== '.php' || strpos((string) $file, '/Update/') !== false) {
                    continue;
                }

                foreach (Update104::RETIRED as $name) {
                    if (preg_match("/Setting\\(\\s*'base'\\s*,\\s*'" . $name . "'/", (string) file_get_contents((string) $file))) {
                        $readers[] = $name . ' in ' . str_replace(\OWA\Core\Env::root() . '/', '', (string) $file);
                    }
                }
            }
        }

        $this->assertSame([], $readers);
    }

    /** The setting had no constant, so none is retired; it is declared by no module. */
    public function testThereIsNoConstantToRetire(): void
    {
        $this->assertSame(['tracker_url_fragments'], Update104::RETIRED);
        $this->assertArrayNotHasKey('OWA_TRACKER_URL_FRAGMENTS', \OWA\Core\Requirements::RETIRED_CONSTANTS);
        $this->assertArrayNotHasKey('tracker_url_fragments',
            (array) (include \OWA\Core\Env::root() . '/modules/Base/settings.php')['settings']);
    }

    public function testNoStoredRowWritesNothing(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'timezone', 'UTC');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /**
     * The settings the update process saves are kept in step: boot loaded the
     * install rows, and a save of the old values would put them straight back.
     */
    public function testTheSettingsInMemoryFollowTheTable(): void
    {
        $this->requireDb();

        $config = new class {
            public $db_settings = ['base' => ['tracker_url_fragments' => true, 'timezone' => 'UTC']];
            public function removeSetting($module, $key) { unset($this->db_settings[$module][$key]); }
        };
        $this->update->settings = $config;

        $this->row('install', '1', 'tracker_url_fragments', true, 5);
        $this->row('profile', 'abc', 'tracker_url_fragments', false, 6);

        $this->assertTrue($this->update->up());
        $this->assertSame(['base' => ['timezone' => 'UTC']], $config->db_settings,
            'the deleted rows are still held, and the closing save writes them back');

        $this->assertTrue($this->update->down());
        $held = $config->db_settings['base'];
        ksort($held);
        $this->assertSame(['timezone' => 'UTC', 'tracker_url_fragments' => true], $held,
            'the restored rows are not held, and the closing save prunes them again');
    }

    /** Every retired row goes, at every scope, others stay; down() is the exact inverse. */
    public function testTheRetiredRowsGoAndComeBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'tracker_url_fragments', true, 5);
        $this->row('profile', 'abc', 'tracker_url_fragments', true, 6);
        $this->row('property', 'def', 'tracker_url_fragments', false, 7);
        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('install', '1', 'tracker_route_changes', true, 8);
        $this->row('profile', 'abc', 'tracker_url_fragments', 'kept', 9, 'other_module');
        $before = $this->rows();

        $this->assertTrue($this->update->up());

        $after = $this->settings();
        $this->assertSame(['install:1:base.timezone', 'install:1:base.tracker_route_changes', 'profile:abc:other_module.tracker_url_fragments'],
            array_keys($after));

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->settings(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'the exact inverse');

        $this->assertTrue($this->update->down(), 'down again');
        $this->assertSame($before, $this->rows());
    }
}
