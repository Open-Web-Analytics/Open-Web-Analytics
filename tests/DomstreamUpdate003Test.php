<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Domstream\Update\Update003;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update003: the chunk and payload tables re-keyed on (yyyymmdd, id), and
 * down() puts back (id, yyyymmdd). Against scratch tables holding only the
 * columns the key names; the install's own are never touched.
 */
final class DomstreamUpdate003Test extends TestCase
{
    const CHUNK   = 'owa_domstream_chunk_update003_test';
    const PAYLOAD = 'owa_domstream_payload_update003_test';

    const PARTITIONS = ' PARTITION BY RANGE (yyyymmdd) (PARTITION p20250101 VALUES LESS THAN (20260101),'
                     . ' PARTITION p20260101 VALUES LESS THAN (20270101), PARTITION pmax VALUES LESS THAN MAXVALUE)';

    private Update003 $update;

    protected function setUp(): void
    {
        $this->update = new Update003();
        $this->update->tables = ['domstream.domstream_chunk' => self::CHUNK, 'domstream.domstream_payload' => self::PAYLOAD];

        if (owa_test_db_available()) {
            $this->dropScratch();
        }
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->dropScratch();
        }
    }

    private function dropScratch(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s, %s', self::CHUNK, self::PAYLOAD));
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters scratch tables');
        }
    }

    /** Both tables as schema 2 created them, with rows in two partitions. */
    private function createOldShape(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, site_id VARCHAR(64) NOT NULL,'
          . ' recording_id BIGINT NOT NULL, PRIMARY KEY (id, yyyymmdd), INDEX site_recording (site_id, recording_id)) %s%s',
            self::CHUNK, $db->tableOptions(), self::PARTITIONS)));

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, payload BLOB NULL, PRIMARY KEY (id, yyyymmdd)) %s%s',
            self::PAYLOAD, $db->tableOptions(), self::PARTITIONS)));

        $db->query(sprintf("INSERT INTO %s VALUES (5, 20251230, 'owatest', 7), (4, 20260103, 'owatest', 7)", self::CHUNK));
        $db->query(sprintf("INSERT INTO %s VALUES (5, 20251230, 'a'), (4, 20260103, 'b')", self::PAYLOAD));
    }

    /** @return array<string, string> index name => its columns in key order */
    private function indexes(string $table): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
                'SELECT INDEX_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols'
              . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
              . ' GROUP BY INDEX_NAME', [$table]) as $row) {
            $out[$row['name']] = $row['cols'];
        }

        ksort($out);

        return $out;
    }

    /** @return list<string> every row of both tables, with its partition */
    private function rows(): array
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $out = [];

        foreach ([self::CHUNK, self::PAYLOAD] as $table) {
            foreach ($db->listPartitions($table) as $p) {
                foreach ((array) $db->get_results(sprintf('SELECT * FROM %s PARTITION (%s) ORDER BY id', $table, $p['name'])) as $row) {
                    $out[] = $table . ':' . $p['name'] . ':' . implode(',', array_map('strval', $row));
                }
            }
        }

        return $out;
    }

    public function testTheModuleRequiresThisSchema(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $maps = $s->maps;

        try {
            $module = new \OWA\Module\Domstream\Module();
        } finally {
            $s->maps = $maps;
        }

        $this->assertSame(3, $this->update->schema_version);
        $this->assertSame($module->required_schema_version, $this->update->schema_version);
        $this->assertTrue($this->update->isCliModeRequired(), 'it rebuilds both tables');
    }

    public function testUpRekeysBothAndDownPutsTheOldKeyBack(): void
    {
        $this->requireDb();
        $this->createOldShape();

        $chunk   = $this->indexes(self::CHUNK);
        $payload = $this->indexes(self::PAYLOAD);
        $rows    = $this->rows();

        $this->assertCount(4, $rows, 'the fixture rows');

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'a second run changes nothing');

        $this->assertSame(['PRIMARY' => 'yyyymmdd,id', 'site_recording' => 'site_id,recording_id'], $this->indexes(self::CHUNK));
        $this->assertSame(['PRIMARY' => 'yyyymmdd,id'], $this->indexes(self::PAYLOAD));
        $this->assertSame($rows, $this->rows(), 'every row, in its partition');

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'a second run changes nothing');

        $this->assertSame($chunk, $this->indexes(self::CHUNK));
        $this->assertSame($payload, $this->indexes(self::PAYLOAD));
        $this->assertSame($rows, $this->rows());
    }

    /** Tables that do not exist are left alone, both ways. */
    public function testWithoutTheTablesItDoesNothing(): void
    {
        $this->requireDb();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());
        $this->assertFalse((bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists(self::CHUNK));
    }

    /** Tables created from the entities have the new key already, and up() leaves them. */
    public function testTablesCreatedFromTheEntitiesAreLeftAsTheyAre(): void
    {
        $this->requireDb();

        foreach (['domstream.domstream_chunk' => self::CHUNK, 'domstream.domstream_payload' => self::PAYLOAD] as $name => $table) {
            $entity = \OWA\Core\CoreAPI::entityFactory($name);
            $entity->setTableName(substr($table, strlen('owa_')));

            $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->createTable($entity));
        }

        $before = [$this->indexes(self::CHUNK), $this->indexes(self::PAYLOAD)];

        $this->assertSame('yyyymmdd,id', $before[0]['PRIMARY']);
        $this->assertSame('yyyymmdd,id', $before[1]['PRIMARY']);

        $this->assertTrue($this->update->up());
        $this->assertSame($before, [$this->indexes(self::CHUNK), $this->indexes(self::PAYLOAD)]);
    }
}
