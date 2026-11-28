<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Entity\Property;
use OWA\Module\Base\Update\Update103;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/CapturesLog.php';

/**
 * Update103: owa_property gains live_host, the host of a live web Property's
 * domain and NULL otherwise, with a UNIQUE index; up() refuses while two live
 * Properties share a host, and down() drops both.
 *
 * Against a scratch copy of owa_property, taken back to the shape before the
 * update.
 */
final class Update103Test extends TestCase
{
    use CapturesLog;

    const TABLE = 'owa_property_update103_test';

    private Update103 $update;

    protected function setUp(): void
    {
        $this->update = new Update103();

        if (!owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE,
            \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName()));

        $this->update->tables = ['base.property' => self::TABLE];
        $this->update->down();
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
            $this->markTestSkipped('alters a scratch table');
        }
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row): void
    {
        $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->query(sprintf('INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE, implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))),
            array_values($row)));
    }

    /** @return array<string, string|null> id => live_host */
    private function liveHosts(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                sprintf('SELECT id, live_host FROM %s ORDER BY id', self::TABLE)) as $row) {
            $out[(string) $row['id']] = $row['live_host'] === null ? null : (string) $row['live_host'];
        }

        return $out;
    }

    /** @return array<string, array{columns: string, unique: bool}> */
    private function indexes(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                'SELECT INDEX_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, MIN(NON_UNIQUE) AS nu'
              . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
              . ' GROUP BY INDEX_NAME', [self::TABLE]) as $row) {
            $out[$row['name']] = ['columns' => $row['cols'], 'unique' => (int) $row['nu'] === 0];
        }

        return $out;
    }

    private function hasColumn(): bool
    {
        return in_array('live_host', \OWA\Core\CoreAPI::dbSingleton()->listColumns(self::TABLE), true);
    }

    public function testTheSchemaNumber(): void
    {
        $this->assertSame(103, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(103, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** A fresh install has what the update adds. */
    public function testTheEntityDeclaresTheColumnAndItsIndex(): void
    {
        $property = \OWA\Core\CoreAPI::entityFactory('base.property');

        $this->assertContains('live_host', $property->getColumns());
        $this->assertSame(['live_host'], $property->getUniqueIndexes()[Property::LIVE_HOST_INDEX] ?? null);
    }

    /** The host as OWA compares hosts, only while the Property is a live web one. */
    public function testWhatLiveHostHolds(): void
    {
        $this->assertSame('example.com', Update103::liveHostOf(['domain' => 'https://WWW.Example.com:8080/path']));
        $this->assertSame('example.com', Update103::liveHostOf(['domain' => 'example.com', 'archived_date' => 0]),
            'a restored Property is live');
        $this->assertSame('example.com', Update103::liveHostOf(['domain' => 'example.com', 'property_type' => 'web']));
        $this->assertNull(Update103::liveHostOf(['domain' => 'example.com', 'archived_date' => 1764000000]));
        $this->assertNull(Update103::liveHostOf(['domain' => 'example.com', 'property_type' => 'app']));
        $this->assertNull(Update103::liveHostOf(['domain' => '']));
    }

    public function testUpDownAndAgain(): void
    {
        $this->requireDb();

        $this->insert(['id' => 1, 'name' => 'Live', 'domain' => 'www.a.example']);
        $this->insert(['id' => 2, 'name' => 'Archived', 'domain' => 'a.example', 'archived_date' => 1764000000]);
        $this->insert(['id' => 3, 'name' => 'Restored', 'domain' => 'B.example:8080', 'archived_date' => 0]);
        $this->insert(['id' => 4, 'name' => 'App', 'domain' => 'c.example', 'property_type' => 'app']);

        $this->assertFalse($this->hasColumn());

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'up is idempotent');

        $this->assertSame(['1' => 'a.example', '2' => null, '3' => 'b.example', '4' => null], $this->liveHosts());
        $this->assertSame(['columns' => 'live_host', 'unique' => true], $this->indexes()[Property::LIVE_HOST_INDEX] ?? null);

        $refused = \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, name, domain, live_host) VALUES (5, 'Second', 'a.example', 'a.example')", self::TABLE));

        $this->assertFalse((bool) $refused, 'the index refuses a second live row with the host');

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'down is idempotent');
        $this->assertFalse($this->hasColumn());
        $this->assertArrayNotHasKey(Property::LIVE_HOST_INDEX, $this->indexes());

        $this->assertTrue($this->update->up());
        $this->assertSame(['1' => 'a.example', '2' => null, '3' => 'b.example', '4' => null], $this->liveHosts());
    }

    /** Two live Properties with one host: nothing changes, and each is named. */
    public function testUpRefusesLiveDuplicates(): void
    {
        $this->requireDb();

        $this->insert(['id' => 1, 'name' => 'First', 'domain' => 'dup.example']);
        $this->insert(['id' => 2, 'name' => 'Second', 'domain' => 'https://WWW.dup.example/']);
        $this->insert(['id' => 3, 'name' => 'Third', 'domain' => 'dup.example', 'archived_date' => 1764000000]);

        $log = $this->logDuring(function () use (&$result) {
            $result = $this->update->up();
        });

        $this->assertFalse($result);
        $this->assertFalse($this->hasColumn(), 'nothing was added');
        $this->assertStringContainsString('Live Properties 1 (First), 2 (Second) all have the domain dup.example.', $log);
        $this->assertStringNotContainsString('3 (Third)', $log, 'an archived Property is no duplicate');
        $this->assertStringContainsString('cmd=property id=<property id> --archive', $log);
    }

    public function testArchivedDuplicatesAreAllowed(): void
    {
        $this->requireDb();

        $this->insert(['id' => 1, 'name' => 'Live', 'domain' => 'dup.example']);
        $this->insert(['id' => 2, 'name' => 'Old', 'domain' => 'dup.example', 'archived_date' => 1764000000]);
        $this->insert(['id' => 3, 'name' => 'Older', 'domain' => 'www.dup.example', 'archived_date' => 1763000000]);

        $this->assertTrue($this->update->up());
        $this->assertSame(['1' => 'dup.example', '2' => null, '3' => null], $this->liveHosts());
    }
}
