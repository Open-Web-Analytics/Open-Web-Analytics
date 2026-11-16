<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update088;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update088: Profile and Property domains lose the scheme and trailing slash
 * 1.x stored, and down() changes nothing.
 *
 * Against scratch copies of the site and property tables, so the
 * install's own rows are neither read nor changed.
 */
final class Update088Test extends TestCase
{
    const SITES      = 'owa_test_update088_site';
    const PROPERTIES = 'owa_test_update088_property';

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
            foreach ([self::SITES, self::PROPERTIES] as $table) {
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

        foreach ([self::SITES => 'base.site', self::PROPERTIES => 'base.property'] as $table => $entity) {
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

        $this->update->tables = ['site' => self::SITES, 'property' => self::PROPERTIES];
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

    public function testTheModuleAsksForIt(): void
    {
        $this->assertSame(88, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(88, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertNotSame('', $this->update->down_does_not_restore);
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

        $this->assertTrue($this->update->up(), 'a second run');
        $this->assertSame($after, $this->domains());
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
    }

    /** down() leaves the domains as up() made them. */
    public function testDownChangesNothing(): void
    {
        $this->scratchTables();

        $this->assertTrue($this->update->up());
        $after = $this->domains();

        $this->assertTrue($this->update->down());
        $this->assertSame($after, $this->domains());
    }
}
