<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update084;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/InstallSettingRowsFixture.php';

/**
 * Update084: log_robots gives way to spam_threshold, and down() puts back
 * every row up() removed or wrote.
 *
 * Against a scratch copy of the settings table, so the install's own rows are
 * neither read nor changed.
 */
final class Update084Test extends TestCase
{
    use InstallSettingRowsFixture;

    const TABLE = 'owa_setting_update084_test';

    private Update084 $update;

    protected function setUp(): void
    {
        $this->update = new Update084();

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
            $this->putBackInstallRows();
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('moves rows of the settings table');
        }
    }

    private function row(string $scope, string $id, $value, string $name = 'log_robots', int $created = 1): void
    {
        $setting = \OWA\Core\CoreAPI::entityFactory('base.setting');

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date) VALUES (?, ?, ?, 'base', ?, ?, 1, ?)",
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
        return array_diff_key($this->rows(), ['install:1:' . Update084::BEFORE => true]);
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(84, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(84, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** log_robots is declared no more; spam_threshold and its record are. */
    public function testTheDeclarations(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->assertFalse($c->isRegistered('base', 'log_robots'));
        $this->assertTrue($c->isRegistered('base', 'spam_threshold'));
        $this->assertTrue($c->isRegistered('base', Update084::BEFORE));
    }

    public function testNoStoredRowWritesNothing(): void
    {
        $this->requireDb();

        $this->row('install', '1', '7.5', 'spam_threshold');
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->rows());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /**
     * On: the threshold becomes blank, never block, and the row goes.
     *
     * @covers-setting base.log_robots_before_084
     */
    public function testOnBecomesABlankThresholdAndComesBack(): void
    {
        $this->requireDb();

        $this->row('install', '1', true);
        $before = $this->rows();

        $this->assertTrue($this->update->up());

        $after = $this->settings();
        $this->assertArrayNotHasKey('install:1:log_robots', $after);
        $this->assertSame('', unserialize($after['install:1:spam_threshold']['value']), 'blank: never block');

        $this->assertTrue($this->update->up(), 'up again');
        $this->assertSame($after, $this->settings(), 'the second run changes nothing');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows(), 'the exact inverse');

        $this->assertTrue($this->update->down(), 'down again');
        $this->assertSame($before, $this->rows());
    }

    /**
     * A threshold the install already had is kept by the record and given back.
     *
     * @covers-setting base.log_robots_before_084
     */
    public function testAThresholdAlreadyStoredIsRestored(): void
    {
        $this->requireDb();

        $this->row('install', '1', '1', 'log_robots', 5);
        $this->row('install', '1', '9.0', 'spam_threshold', 7);
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame('', unserialize($this->settings()['install:1:spam_threshold']['value']));

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /** Off: nothing to set, the row goes, and down() puts it back. */
    public function testOffNeedsNothing(): void
    {
        $this->requireDb();

        $this->row('install', '1', false);
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame([], $this->settings(), 'the row is gone, and no threshold was written');

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /** A row at another scope is not the install's choice; it goes, and comes back. */
    public function testARowBelowTheInstallIsOnlyRemoved(): void
    {
        $this->requireDb();

        $this->row('profile', 'abc', true);
        $before = $this->rows();

        $this->assertTrue($this->update->up());
        $this->assertSame([], $this->settings());

        $this->assertTrue($this->update->down());
        $this->assertSame($before, $this->rows());
    }

    /**
     * The update run ends with Settings::save(), which writes back what the
     * settings hold and prunes the eager install rows they do not. Against the
     * install's own table and settings, the threshold up() wrote and the one
     * down() put back are both still stored after it.
     */
    public function testTheClosingSaveKeepsWhatUpAndDownStored(): void
    {
        $this->requireDb();

        $c = \OWA\Core\CoreAPI::configSingleton();
        $this->assertTrue((bool) $c->config_from_db, 'boot read the store, so save() prunes');

        $this->keepInstallRows(['log_robots', 'spam_threshold', Update084::BEFORE]);
        $this->update->tables = [];

        // No threshold stored: up() creates the row.
        $c->removeSetting('base', 'spam_threshold');
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf("DELETE FROM %s WHERE scope_type = 'install' AND module = 'base' AND name IN ('spam_threshold', ?)",
            $this->installTable()), [Update084::BEFORE]);
        $this->writeInstallValue('log_robots', true);

        $this->assertTrue($this->update->up());
        $c->save();
        $this->assertSame('', $this->storedInstallValue('spam_threshold'), 'the blank threshold up() wrote');
        $this->assertSame('', $c->get('base', 'spam_threshold'));

        $this->assertTrue($this->update->down());
        $c->save();
        $this->assertNull($this->storedInstallValue('spam_threshold'), 'up() created it, so down() removes it');
        $this->assertTrue($this->storedInstallValue('log_robots'));

        // A threshold stored before: down() puts it back, in a later process
        // whose boot loaded the blank one.
        $this->writeInstallValue('spam_threshold', '9.0');
        $c->db_settings['base']['spam_threshold'] = '9.0';
        $c->set('base', 'spam_threshold', '9.0');

        $this->assertTrue($this->update->up());
        $c->save();
        $this->assertSame('', $this->storedInstallValue('spam_threshold'), 'the threshold boot loaded is not written back');

        $c->db_settings['base']['spam_threshold'] = '';
        $c->set('base', 'spam_threshold', '');

        $this->assertTrue($this->update->down());
        $c->save();
        $this->assertSame('9.0', $this->storedInstallValue('spam_threshold'), 'the threshold down() put back');
        $this->assertSame('9.0', $c->get('base', 'spam_threshold'));
    }
}
