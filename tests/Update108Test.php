<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update108;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update108: prior_session_start_ts goes from owa_event_raw and every cube,
 * and the stored rows of base.geolocation_service are deleted at every scope;
 * down() puts the column back, empty, and every row up() removed.
 *
 * The rows against a scratch copy of the settings table, so the install's
 * own rows are neither read nor changed. The column against the suite's own
 * raw table and cubes, created from the current entities: up() leaves them as
 * they are, and down() then up() comes back to them.
 */
final class Update108Test extends TestCase
{
    const TABLE = 'owa_setting_update108_test';

    const FIXTURE_PROPERTY = 7775000000000108;

    private Update108 $update;

    private bool $createdCube = false;

    protected function setUp(): void
    {
        $this->update = new Update108();

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
        if (!owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);

        if ($this->createdCube) {
            foreach (['', '_rebuild', '_computed'] as $suffix) {
                $db->query(sprintf('DROP TABLE IF EXISTS %s%s',
                    \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::FIXTURE_PROPERTY), $suffix));
            }
        }
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('moves rows of the settings table and alters owa_event_raw and the cubes');
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
        return array_diff_key($this->rows(), ['install:1:base.' . Update108::BEFORE => true]);
    }

    /** @return array<string, list<array<string, mixed>>> raw's and every cube's columns, by table */
    private function columns(): array
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $out = [];

        foreach (array_merge([\OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName()],
                 \OWA\Module\Base\Classes\Cube\Cubes::allTables()) as $table) {
            $out[$table] = array_map(fn ($c) => (array) $c, (array) $db->get_results("SHOW COLUMNS FROM $table"));
        }

        return $out;
    }

    private function hasColumn(string $table): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf("SHOW COLUMNS FROM %s LIKE '%s'", $table, Update108::COLUMN));
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(108, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(108, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertStringContainsString('prior_session_start_ts', $this->update->down_does_not_restore,
            'down() restores the column, not its values');
    }

    /** Neither the column nor the setting is declared, and nothing reads them; the record is declared. */
    public function testTheDeclarations(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->assertFalse($c->isRegistered('base', Update108::RETIRED));
        $this->assertTrue($c->isRegistered('base', Update108::BEFORE));

        $this->assertNotContains(Update108::COLUMN, \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns());
        $this->assertNotContains(Update108::COLUMN, \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns());

        $properties = json_decode((string) file_get_contents(\OWA\Core\Env::root() . '/modules/Base/config/tracking_properties.json'), true);
        $this->assertArrayNotHasKey('psts', $properties);

        $readers = [];

        foreach (['modules', 'Core', 'conf'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\OWA\Core\Env::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = (string) $file;

                if (!preg_match('/\.(php|js|json)$/', $path) || strpos($path, '/Update/') !== false || strpos($path, '/node_modules/') !== false) {
                    continue;
                }

                $code = (string) file_get_contents($path);

                if (preg_match("/'base'\\s*,\\s*'" . Update108::RETIRED . "'/", $code)
                    || strpos($code, Update108::COLUMN) !== false || preg_match('/\\bpsts\\b|\\bs_pts\\b/', $code)) {
                    $readers[] = str_replace(\OWA\Core\Env::root() . '/', '', $path);
                }
            }
        }

        $this->assertSame([], $readers);
    }

    public function testNoStoredRowWritesNoRow(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'timezone', 'UTC');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->up(), 'and the column, put back by down(), goes again');
    }

    /** The settings the update process saves are kept in step: boot loaded the install rows. */
    public function testTheSettingsInMemoryFollowTheTable(): void
    {
        $this->requireDb();

        $config = new class {
            public $db_settings = ['base' => ['geolocation_service' => 'maxmind', 'timezone' => 'UTC']];
            public function removeSetting($module, $key) { unset($this->db_settings[$module][$key]); }
        };
        $this->update->settings = $config;

        $this->row('install', '1', 'geolocation_service', 'maxmind', 5);

        try {
            $this->assertTrue($this->update->up());
            $this->assertSame(['base' => ['timezone' => 'UTC']], $config->db_settings);

            $this->assertTrue($this->update->down());
            $held = $config->db_settings['base'];
            ksort($held);
            $this->assertSame(['geolocation_service' => 'maxmind', 'timezone' => 'UTC'], $held);
        } finally {
            $this->assertTrue($this->update->up());
        }
    }

    /** Every geolocation_service row goes, at every scope, others stay; down() puts them back exactly. */
    public function testTheRetiredRowsGoAndComeBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', 'geolocation_service', 'maxmind', 5);
        $this->row('profile', 'abc', 'geolocation_service', 'maxmind', 6);
        $this->row('install', '1', 'geolocation_lookup', true, 7);
        $this->row('install', '1', 'timezone', 'UTC');
        $this->row('profile', 'abc', 'geolocation_service', 'kept', 9, 'other_module');
        $before = $this->rows();

        try {
            $this->assertTrue($this->update->up());

            $after = $this->settings();
            $this->assertSame(['install:1:base.geolocation_lookup', 'install:1:base.timezone', 'profile:abc:other_module.geolocation_service'],
                array_keys($after));

            $this->assertTrue($this->update->up(), 'up again');
            $this->assertSame($after, $this->settings(), 'the second run changes nothing');

            $this->assertTrue($this->update->down());
            $this->assertSame($before, $this->rows(), 'the exact inverse');

            $this->assertTrue($this->update->down(), 'down again');
            $this->assertSame($before, $this->rows());
        } finally {
            $this->assertTrue($this->update->up());
        }
    }

    /**
     * On tables created from the current entities, up() changes nothing;
     * down() puts the column on raw and every cube, nullable BIGINT, and up()
     * takes it off again, leaving the tables as they were.
     */
    public function testTheColumnGoesFromTablesMadeFromTheEntitiesAndComesBack(): void
    {
        $this->requireDb();

        // CI's database has no cube until a build makes one.
        if (!\OWA\Module\Base\Classes\Cube\Cubes::allTables()) {
            $this->assertTrue((bool) \OWA\Module\Base\Classes\Cube\Cubes::create(self::FIXTURE_PROPERTY), 'creating a fixture cube');
            $this->createdCube = true;
        }

        $raw    = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();
        $tables = array_merge([$raw], \OWA\Module\Base\Classes\Cube\Cubes::allTables());
        $made   = $this->columns();

        foreach ($tables as $table) {
            $this->assertFalse($this->hasColumn($table), "$table, made from the entity, has no " . Update108::COLUMN);
        }

        try {
            $this->assertTrue($this->update->up());
            $this->assertSame($made, $this->columns(), 'up() on tables made from the entities changes nothing');

            $this->assertTrue($this->update->down());

            foreach ($tables as $table) {
                $column = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
                    sprintf("SHOW COLUMNS FROM %s LIKE '%s'", $table, Update108::COLUMN));
                $this->assertSame('bigint', strtolower(preg_replace('/\(.*$/', '', (string) $column['Type'])), $table);
                $this->assertSame('YES', $column['Null'], $table);
            }
        } finally {
            $this->assertTrue($this->update->up());
        }

        $this->assertSame($made, $this->columns(), 'down() then up() is where it started');

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table) {
            $this->assertNotTrue(\OWA\Core\CoreAPI::dbSingleton()->hasInstantColumns($table),
                "$table: a cube with instant columns refuses the build's partition swap");
        }
    }
}
