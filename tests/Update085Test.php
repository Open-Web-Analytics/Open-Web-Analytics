<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update085;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update085: the rows of 1.x settings no module declares are deleted, and
 * down() puts back every row up() removed.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update085Test extends TestCase
{
    const TABLE = 'owa_setting_update085_test';

    private Update085 $update;

    protected function setUp(): void
    {
        $this->update = new Update085();

        if (!owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE,
            \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName()));

        $this->update->table = self::TABLE;
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
        return array_diff_key($this->rows(), ['install:1:base.' . Update085::BEFORE => true]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(85, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(85, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** What it deletes is declared by no module and read by nothing; its record is declared. */
    public function testTheDeclarations(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach (Update085::RETIRED as $name) {
            $this->assertFalse($c->isRegistered('base', $name), $name);
        }

        $this->assertTrue($c->isRegistered('base', Update085::BEFORE));

        $readers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\OWA\Core\Env::root() . '/modules', FilesystemIterator::SKIP_DOTS)) as $file) {
            if (substr((string) $file, -4) !== '.php' || strpos((string) $file, '/Update/') !== false) {
                continue;
            }

            $src = (string) file_get_contents((string) $file);

            foreach (Update085::RETIRED as $name) {
                if (strpos($src, $name) !== false) {
                    $readers[] = $name . ' in ' . str_replace(\OWA\Core\Env::root() . '/', '', (string) $file);
                }
            }
        }

        $this->assertSame([], $readers);
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

    /** Every retired row goes, at every scope, others stay; down() is the exact inverse. */
    public function testTheRetiredRowsGoAndComeBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'is_embedded_admin_user_password_reset', true, 5);
        $this->row('install', '1', 'resolve_hosts', '1', 6);
        $this->row('install', '1', 'fetch_refering_page_info', false, 7);
        $this->row('profile', 'abc', 'resolve_hosts', '0', 8);
        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('install', '1', 'resolve_hosts', '1', 9, 'other_module');
        $before = $this->rows();

        $this->assertTrue($this->update->up());

        $after = $this->settings();
        $this->assertSame(['install:1:base.timezone', 'install:1:other_module.resolve_hosts'], array_keys($after));

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->settings(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'the exact inverse');

        $this->assertTrue($this->update->down(), 'down again');
        $this->assertSame($before, $this->rows());
    }
}
