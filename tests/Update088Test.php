<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update088;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update088: Profile and Property domains lose the scheme and trailing slash
 * 1.x stored, and down() puts back each value up() changed.
 *
 * Against scratch copies of the site, property and settings tables, so the
 * install's own rows are neither read nor changed.
 */
final class Update088Test extends TestCase
{
    const SITES      = 'owa_test_update088_site';
    const PROPERTIES = 'owa_test_update088_property';
    const SETTINGS   = 'owa_test_update088_setting';

    /** Stored => what up() makes of it. */
    const DOMAINS = [
        'https://www.example.com'   => 'www.example.com',
        'http://example.org/'       => 'example.org',
        'HTTPS://Shop.example.net/' => 'Shop.example.net',
        'http://localhost:8080'     => 'localhost:8080',
        'https://example.com:443'   => 'example.com:443',
        'example.com'               => 'example.com',
        'example.com:8443'          => 'example.com:8443',
        'owa-test-site'             => 'owa-test-site',
        'https://http://twice.example.com//' => 'twice.example.com',
    ];

    private Update088 $update;

    protected function setUp(): void
    {
        $this->update = new Update088();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            foreach ([self::SITES, self::PROPERTIES, self::SETTINGS] as $table) {
                $db->query('DROP TABLE IF EXISTS ' . $table);
            }
        }
    }

    private function scratchTables(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('rewrites scratch copies of the site and property tables');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ([self::SITES => 'base.site', self::PROPERTIES => 'base.property', self::SETTINGS => 'base.setting'] as $table => $entity) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
            $this->assertNotFalse($db->query(sprintf('CREATE TABLE %s LIKE %s', $table,
                \OWA\Core\CoreAPI::entityFactory($entity)->getTableName())));
        }

        $i = 0;
        foreach (array_keys(self::DOMAINS) as $domain) {
            $i++;
            $this->assertNotFalse($db->query(sprintf('INSERT INTO %s (id, site_id, domain) VALUES (?, ?, ?)', self::SITES),
                [$i, 'site' . $i, $domain]));
            $this->assertNotFalse($db->query(sprintf('INSERT INTO %s (id, domain) VALUES (?, ?)', self::PROPERTIES),
                [$i, $domain]));
        }

        // A row with no domain (an app) and one with an empty domain are left alone.
        $db->query(sprintf('INSERT INTO %s (id, site_id, domain) VALUES (100, ?, NULL)', self::SITES), ['app-site']);
        $db->query(sprintf("INSERT INTO %s (id, domain) VALUES (100, '')", self::PROPERTIES));

        $this->update->tables       = ['site' => self::SITES, 'property' => self::PROPERTIES];
        $this->update->settingTable = self::SETTINGS;
    }

    /** @return array<string,array<string,string|null>> table => id => domain */
    private function domains(): array
    {
        $out = [];

        foreach ([self::SITES, self::PROPERTIES] as $table) {
            foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SELECT id, domain FROM ' . $table . ' ORDER BY id') as $row) {
                $row = (array) $row;
                $out[$table][(string) $row['id']] = $row['domain'] === null ? null : (string) $row['domain'];
            }
        }

        return $out;
    }

    private function recordCount(): int
    {
        return (int) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf("SELECT COUNT(*) AS n FROM %s WHERE name = ?", self::SETTINGS), [Update088::BEFORE]))['n'];
    }

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(88, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(88, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertTrue(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', Update088::BEFORE));
    }

    /** The update applies the function every save applies. */
    public function testTheSavedShapeIsTheHelpers(): void
    {
        foreach (self::DOMAINS as $stored => $bare) {
            $this->assertSame($bare, \OWA\Module\Base\Classes\SiteManager::domainOnly($stored), $stored);
            $this->assertSame($bare, \OWA\Module\Base\Classes\SiteManager::domainOnly($bare), 'unchanged a second time: ' . $bare);
        }
    }

    public function testUpRemovesTheSchemeAndIsIdempotent(): void
    {
        $this->scratchTables();

        $this->assertTrue($this->update->up());

        $expected = [];
        $i = 0;
        foreach (self::DOMAINS as $bare) {
            $i++;
            $expected[(string) $i] = $bare;
        }

        $after = $this->domains();
        $this->assertSame($expected + ['100' => null], $after[self::SITES]);
        $this->assertSame($expected + ['100' => ''], $after[self::PROPERTIES]);
        $this->assertSame(1, $this->recordCount());

        $record = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf('SELECT value FROM %s WHERE name = ?', self::SETTINGS), [Update088::BEFORE]);

        $this->assertTrue($this->update->up(), 'a second run');
        $this->assertSame($after, $this->domains());
        $this->assertSame($record, (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf('SELECT value FROM %s WHERE name = ?', self::SETTINGS), [Update088::BEFORE]),
            'a second run keeps the first run\'s record');
    }

    public function testNothingToChangeWritesNothing(): void
    {
        $this->scratchTables();
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DELETE FROM ' . self::SITES . " WHERE domain LIKE '%/%'");
        $db->query('DELETE FROM ' . self::PROPERTIES . " WHERE domain LIKE '%/%'");
        $before = $this->domains();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->domains());
        $this->assertSame(0, $this->recordCount());
    }

    public function testDownRestoresEveryValueExactly(): void
    {
        $this->scratchTables();
        $before = $this->domains();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());

        $this->assertSame($before, $this->domains());
        $this->assertSame(0, $this->recordCount(), 'down() removes its record');

        $this->assertTrue($this->update->down(), 'down() with nothing to undo');
        $this->assertSame($before, $this->domains());
    }
}
