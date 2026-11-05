<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update080;
use OWA\Module\Base\Classes\V2Event;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update080: default_page gives way to directory_index_pages, and down() puts
 * back every row up() removed or wrote.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update080Test extends TestCase
{
    const TABLE = 'owa_setting_update080_test';

    const IN_LIST   = 'update080-in-list';
    const EXTRA     = 'update080-extra';
    const SAME      = 'update080-same-extra';
    const DIRECTORY = 'update080-directory';

    private Update080 $update;

    protected function setUp(): void
    {
        $this->update = new Update080();

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

    private function row(string $scope, string $id, $value, string $name = 'default_page', int $created = 1): void
    {
        $setting = \OWA\Core\CoreAPI::entityFactory('base.setting');

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date) VALUES (?, ?, ?, 'base', ?, ?, 0, ?)",
            self::TABLE), [$setting->makeId($scope, $id, 'base', $name), $scope, $id, $name, serialize($value), $created]);
    }

    /** @return array<string,array> "scope:id:name" => every column, every row */
    private function rows(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SELECT * FROM ' . self::TABLE) as $row) {
            $row = (array) $row;
            $out[$row['scope_type'] . ':' . $row['scope_id'] . ':' . $row['name']] = $row;
        }

        ksort($out);

        return $out;
    }

    /** @return array<string,array> the rows, without the record up() keeps */
    private function settings(): array
    {
        return array_diff_key($this->rows(), ['install:1:' . Update080::BEFORE => true]);
    }

    /** The install's directory_index_pages, as stored, or null. */
    private function indexList(): ?array
    {
        $row = $this->rows()['install:1:directory_index_pages'] ?? null;

        return $row === null ? null : unserialize((string) $row['value']);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(80, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(80,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    public function testNoStoredRowsWritesNothing(): void
    {
        $this->requireDb();

        // Another setting, which this does not touch.
        $this->row('profile', self::EXTRA, 'example.test', 'domain_aliases');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /** A name the default list has needs nothing: the rows go, and no list is written. */
    public function testAValueAlreadyInTheListIsOnlyRemoved(): void
    {
        $this->requireDb();

        $this->row('profile', self::IN_LIST, 'index.php');
        $this->row('profile', self::EXTRA, 'Default.ASPX');
        $this->row('profile', self::SAME, '');
        $this->row('install', '1', 'index.html');

        $this->assertTrue($this->update->up());

        $this->assertSame([], $this->settings(), 'every default_page row is gone, and nothing else is written');
        $this->assertArrayHasKey('install:1:' . Update080::BEFORE, $this->rows(), 'kept for down()');
    }

    /** Any other name is added after the list, once, for every Profile. */
    public function testAnExtraValueIsAddedToTheList(): void
    {
        $this->requireDb();

        $this->row('profile', self::IN_LIST, 'index.php');
        $this->row('profile', self::EXTRA, 'home.cfm');
        $this->row('profile', self::SAME, ' Home.CFM ');
        $this->row('install', '1', 'welcome.html');
        $this->row('profile', self::DIRECTORY, '/en/index.html');

        $this->assertTrue($this->update->up());

        $this->assertSame(array_merge(V2Event::DIRECTORY_INDEX_PAGES, ['home.cfm', 'welcome.html']), $this->indexList());
        $this->assertSame(['install:1:directory_index_pages'], array_keys($this->settings()),
            'every default_page row is gone');
        $this->assertSame('1', (string) $this->rows()['install:1:directory_index_pages']['autoload'],
            'ingest reads it on every event');
    }

    /** An install that already stored a list keeps it, with the extras after it. */
    public function testAStoredListIsExtended(): void
    {
        $this->requireDb();

        $this->row('install', '1', ['index.html', 'start.htm'], 'directory_index_pages');
        $this->row('profile', self::EXTRA, 'home.cfm');
        $this->row('profile', self::IN_LIST, 'start.htm');

        $this->assertTrue($this->update->up());

        $this->assertSame(['index.html', 'start.htm', 'home.cfm'], $this->indexList());
    }

    public function testASecondUpChangesNothing(): void
    {
        $this->requireDb();

        $this->row('profile', self::IN_LIST, 'index.php');
        $this->row('profile', self::EXTRA, 'home.cfm');

        $this->assertTrue($this->update->up());
        $once = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($once, $this->rows(), 'the record of the first run is kept');
    }

    /** @return array<string, array{0: callable}> */
    public static function stored(): array
    {
        return [
            'no extra'                   => [static function (self $t) {
                $t->row('profile', self::IN_LIST, 'index.php');
                $t->row('install', '1', 'index.html');
            }],
            'an extra, no list stored'   => [static function (self $t) {
                $t->row('profile', self::IN_LIST, 'index.php');
                $t->row('profile', self::EXTRA, 'home.cfm', 'default_page', 1700000000);
                $t->row('profile', self::DIRECTORY, '/en/index.html');
            }],
            'an extra, a list stored'    => [static function (self $t) {
                $t->row('install', '1', ['start.htm'], 'directory_index_pages', 1600000000);
                $t->row('profile', self::EXTRA, 'home.cfm');
            }],
        ];
    }

    /**
     * down() puts back exactly what was there, every column, and removes the
     * list only when up() wrote it. A second down() changes nothing.
     *
     * @dataProvider stored
     */
    public function testDownRestoresTheExactRows(callable $seed): void
    {
        $this->requireDb();

        // Another setting, which neither direction touches.
        $this->row('profile', self::EXTRA, 'example.test', 'domain_aliases');
        $seed($this);
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertNotSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'a second down() changes nothing');

        // And the cycle repeats.
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }
}
