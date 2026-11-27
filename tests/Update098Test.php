<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update098;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update098: the stored rows of log_named_users, log_visitor_pii,
 * announce_visitors and notice_email are deleted at every scope, and down()
 * puts back every row up() removed.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update098Test extends TestCase
{
    const TABLE = 'owa_setting_update098_test';

    private Update098 $update;

    protected function setUp(): void
    {
        $this->update = new Update098();

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
        return array_diff_key($this->rows(), ['install:1:base.' . Update098::BEFORE => true]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(98, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(98, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertSame('', $this->update->down_does_not_restore, 'down() is the exact inverse');
    }

    /** The retired settings are declared by no module and read by no getSetting(); the record is declared. */
    public function testTheDeclarations(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach (Update098::RETIRED as $name) {
            $this->assertFalse($c->isRegistered('base', $name), $name);
        }
        $this->assertTrue($c->isRegistered('base', Update098::BEFORE));

        $readers = [];

        foreach (['modules', 'Core'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\OWA\Core\Env::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (substr((string) $file, -4) !== '.php' || strpos((string) $file, '/Update/') !== false) {
                    continue;
                }

                foreach (Update098::RETIRED as $name) {
                    if (preg_match("/Setting\\(\\s*'base'\\s*,\\s*'" . $name . "'/", (string) file_get_contents((string) $file))) {
                        $readers[] = $name . ' in ' . str_replace(\OWA\Core\Env::root() . '/', '', (string) $file);
                    }
                }
            }
        }

        $this->assertSame([], $readers);
    }

    /** What they drove is gone: the announcement action, its handler and template, and the ingest gate. */
    public function testWhatTheyDroveIsGone(): void
    {
        $this->assertEmpty(\OWA\Core\CoreAPI::serviceSingleton()->getMapValue('actions', 'base.notifyNewSession'));
        $this->assertFalse(class_exists('OWA\\Module\\Base\\Handler\\NotifyHandlers'));
        $this->assertFalse(class_exists('OWA\\Module\\Base\\Controller\\NotifyNewSession'));
        $this->assertFileDoesNotExist(\OWA\Core\Env::modulesDir() . 'Base/templates/new_session_email.php');
        $this->assertFalse(method_exists(\OWA\Module\Base\Classes\TrackingEventHelpers::class, 'gateVisitorPii'));
        $this->assertFalse(method_exists(\OWA\Module\Base\Classes\TrackingEventHelpers::class, 'gateUserId'));
        $this->assertArrayHasKey('OWA_LOG_VISITOR_PII', \OWA\Core\Requirements::RETIRED_CONSTANTS);
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
            public $db_settings = ['base' => ['log_named_users' => false, 'notice_email' => 'ops@owatest.example.com', 'timezone' => 'UTC']];
            public function removeSetting($module, $key) { unset($this->db_settings[$module][$key]); }
        };
        $this->update->settings = $config;

        $this->row('install', '1', 'log_named_users', false, 5);
        $this->row('install', '1', 'notice_email', 'ops@owatest.example.com', 6);
        $this->row('profile', 'abc', 'announce_visitors', true, 7);

        $this->assertTrue($this->update->up());
        $this->assertSame(['base' => ['timezone' => 'UTC']], $config->db_settings,
            'the deleted rows are still held, and the closing save writes them back');

        $this->assertTrue($this->update->down());
        $held = $config->db_settings['base'];
        ksort($held);
        $this->assertSame(['log_named_users' => false, 'notice_email' => 'ops@owatest.example.com', 'timezone' => 'UTC'], $held,
            'the restored rows are not held, and the closing save prunes them again');
    }

    /** Every retired row goes, at every scope, others stay; down() is the exact inverse. */
    public function testTheRetiredRowsGoAndComeBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'log_named_users', false, 5);
        $this->row('profile', 'abc', 'log_named_users', true, 6);
        $this->row('install', '1', 'log_visitor_pii', false, 7);
        $this->row('install', '1', 'announce_visitors', true, 8);
        $this->row('profile', 'abc', 'announce_visitors', true, 9);
        $this->row('profile', 'abc', 'notice_email', 'ops@owatest.example.com', 10);
        $this->row('property', 'def', 'notice_email', 'owner@owatest.example.com', 11);
        $this->row('install', '1', 'admin_notice_email', 'admin@owatest.example.com', 12);
        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('profile', 'abc', 'notice_email', 'kept', 13, 'other_module');
        $before = $this->rows();

        $this->assertTrue($this->update->up());

        $after = $this->settings();
        $this->assertSame(['install:1:base.admin_notice_email', 'install:1:base.timezone', 'profile:abc:other_module.notice_email'],
            array_keys($after));

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->settings(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'the exact inverse');

        $this->assertTrue($this->update->down(), 'down again');
        $this->assertSame($before, $this->rows());
    }
}
