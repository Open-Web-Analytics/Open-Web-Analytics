<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update094;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update094: raw, each cube and the domstream tables re-keyed on (yyyymmdd,
 * id), raw's site_date widened to (site_id, yyyymmdd, created_at); down() puts
 * back exactly the old shape. Against scratch tables holding only the columns
 * the keys name.
 *
 * And the reason for site_date: isCurrent()'s statement, as Cube\Builder
 * writes it, reads raw through site_date alone.
 */
final class Update094Test extends TestCase
{
    const RAW  = 'owa_event_raw_update094_test';
    const CUBE = 'owa_event_update094_test';

    const CHUNK   = 'owa_domstream_chunk_update094_test';
    const PAYLOAD = 'owa_domstream_payload_update094_test';

    /** A raw table created from the entity, for the plan. */
    const ENTITY_RAW = 'zz_update094_raw';

    const PARTITIONS = ' PARTITION BY RANGE (yyyymmdd) (PARTITION p20250101 VALUES LESS THAN (20260101),'
                     . ' PARTITION p20260101 VALUES LESS THAN (20270101), PARTITION pmax VALUES LESS THAN MAXVALUE)';

    private Update094 $update;

    protected function setUp(): void
    {
        $this->update = new class extends Update094 {
            protected function cubeTables() { return [Update094Test::CUBE]; }
        };
        // Every table it would find by name, scratch: the install's own are never touched.
        $this->update->tables = ['base.event_raw' => self::RAW,
            'domstream_chunk' => self::CHUNK, 'domstream_payload' => self::PAYLOAD];

        if (!owa_test_db_available()) {
            return;
        }

        $this->dropScratch();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->dropScratch();
        }
    }

    private function dropScratch(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s, %s, %s, %s, owa_%s',
            self::RAW, self::CUBE, self::CHUNK, self::PAYLOAD, self::ENTITY_RAW));
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters scratch tables');
        }
    }

    /** Raw, a cube and the domstream tables as schema 93 created them, with a few rows in two partitions. */
    private function createOldShape(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, site_id VARCHAR(64) NOT NULL, event_type VARCHAR(64) NOT NULL,'
          . ' created_at BIGINT NULL, yyyymmdd INT NOT NULL, PRIMARY KEY (id, yyyymmdd),'
          . ' INDEX site_date (site_id, yyyymmdd), INDEX site_type_date (site_id, event_type, yyyymmdd)) %s%s',
            self::RAW, $db->tableOptions(), self::PARTITIONS)));

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, site_id VARCHAR(64) NOT NULL, event_type VARCHAR(64) NOT NULL,'
          . ' yyyymmdd INT NOT NULL, PRIMARY KEY (id, yyyymmdd),'
          . ' INDEX site_date (site_id, yyyymmdd), INDEX site_type_date (site_id, event_type, yyyymmdd)) %s%s',
            self::CUBE, $db->tableOptions(), self::PARTITIONS)));

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, site_id VARCHAR(64) NOT NULL,'
          . ' recording_id BIGINT NOT NULL, PRIMARY KEY (id, yyyymmdd), INDEX site_recording (site_id, recording_id)) %s%s',
            self::CHUNK, $db->tableOptions(), self::PARTITIONS)));

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, yyyymmdd INT NOT NULL, payload BLOB NULL,'
          . ' PRIMARY KEY (id, yyyymmdd)) %s%s',
            self::PAYLOAD, $db->tableOptions(), self::PARTITIONS)));

        $db->query(sprintf("INSERT INTO %s VALUES (5, 20251230, 'owatest', 7), (4, 20260103, 'owatest', 7)", self::CHUNK));
        $db->query(sprintf("INSERT INTO %s VALUES (5, 20251230, 'a'), (4, 20260103, 'b')", self::PAYLOAD));

        $db->query(sprintf("INSERT INTO %s VALUES (3, 'owatest', 'page_view', 10, 20251231),"
            . " (1, 'owatest', 'page_view', 11, 20260102), (2, 'owatest', 'click', NULL, 20260101)", self::RAW));
        $db->query(sprintf("INSERT INTO %s VALUES (3, 'owatest', 'page_view', 20251231),"
            . " (1, 'owatest', 'page_view', 20260102)", self::CUBE));
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

    /** @return list<string> every row, with its partition, in a fixed order */
    private function rows(string $table): array
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $out = [];

        foreach ($db->listPartitions($table) as $p) {
            foreach ((array) $db->get_results(sprintf('SELECT * FROM %s PARTITION (%s) ORDER BY id', $table, $p['name'])) as $row) {
                $out[] = $p['name'] . ':' . implode(',', array_map('strval', $row));
            }
        }

        return $out;
    }

    public function testTheSchemaNumber(): void
    {
        $this->assertSame(94, $this->update->schema_version);
        $this->assertTrue($this->update->isCliModeRequired(), 'it rebuilds raw');
        $this->assertGreaterThanOrEqual(94,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
    }

    /** @return array<string, list<string>> table => its rows */
    private function allRows(): array
    {
        $out = [];

        foreach ([self::RAW, self::CUBE, self::CHUNK, self::PAYLOAD] as $table) {
            $out[$table] = $this->rows($table);
        }

        return $out;
    }

    public function testUpRekeysEveryTableAndDownPutsTheOldShapeBack(): void
    {
        $this->requireDb();
        $this->createOldShape();

        $raw     = $this->indexes(self::RAW);
        $cube    = $this->indexes(self::CUBE);
        $chunk   = $this->indexes(self::CHUNK);
        $payload = $this->indexes(self::PAYLOAD);
        $rows    = $this->allRows();

        $this->assertCount(9, array_merge(...array_values($rows)), 'the fixture rows');

        $this->assertSame('id,yyyymmdd', $raw['PRIMARY']);

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up(), 'a second run changes nothing');

        $this->assertSame(['PRIMARY' => 'yyyymmdd,id', 'site_date' => 'site_id,yyyymmdd,created_at',
            'site_type_date' => 'site_id,event_type,yyyymmdd'], $this->indexes(self::RAW));
        $this->assertSame(['PRIMARY' => 'yyyymmdd,id', 'site_date' => 'site_id,yyyymmdd',
            'site_type_date' => 'site_id,event_type,yyyymmdd'], $this->indexes(self::CUBE));
        $this->assertSame(['PRIMARY' => 'yyyymmdd,id', 'site_recording' => 'site_id,recording_id'],
            $this->indexes(self::CHUNK));
        $this->assertSame(['PRIMARY' => 'yyyymmdd,id'], $this->indexes(self::PAYLOAD));
        $this->assertSame($rows, $this->allRows(), 'every row, in its partition');

        $this->assertTrue($this->update->down());
        $this->assertTrue($this->update->down(), 'a second run changes nothing');

        $this->assertSame($raw, $this->indexes(self::RAW));
        $this->assertSame($cube, $this->indexes(self::CUBE));
        $this->assertSame($chunk, $this->indexes(self::CHUNK));
        $this->assertSame($payload, $this->indexes(self::PAYLOAD));
        $this->assertSame($rows, $this->allRows());
    }

    /** A table created from the entity has the new shape already, and up() leaves it. */
    public function testATableCreatedFromTheEntityIsLeftAsItIs(): void
    {
        $this->requireDb();

        $entity = $this->entityRaw();
        $before = $this->indexes($entity->getTableName());

        $this->assertSame('yyyymmdd,id', $before['PRIMARY']);
        $this->assertSame('site_id,yyyymmdd,created_at', $before['site_date']);

        $this->update->tables['base.event_raw'] = $entity->getTableName();

        $this->assertTrue($this->update->up());
        $this->assertSame($before, $this->indexes($entity->getTableName()));
    }

    private function entityRaw(): \OWA\Core\Entity
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $entity->setTableName(self::ENTITY_RAW);

        $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->createTable($entity));

        return $entity;
    }

    /**
     * The late-row test of isCurrent() -- a row of the span that reached raw
     * after the build -- reads site_date and no rows, for a daily partition:
     * the cube's lead, which a routine rebuild checks on every run.
     */
    public function testIsCurrentReadsRawThroughSiteDateAlone(): void
    {
        $this->requireDb();

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->entityRaw()->getTableName();
        $start = (int) date('Ymd', strtotime('-1 day'));
        $end   = (int) date('Ymd');

        // Enough rows, over enough sites, days and event types, that a plan is a choice.
        $values = [];

        for ($n = 0; $n < 3000; $n++) {
            $values[] = sprintf("(%d, '%s', 'owatest_%d', %d, %d, %d, %d, %d)",
                $n + 1, $n % 3 ? 'page_view' : 'click', $n % 3, $n % 97, $n % 211,
                1700000000000000 + $n, 1700000000000000 + $n, (int) date('Ymd', strtotime('-' . ($n % 28) . ' day')));
        }

        foreach (array_chunk($values, 500) as $chunk) {
            $this->assertNotFalse($db->query(sprintf(
                'INSERT INTO %s (id, event_type, site_id, visitor_id, session_id, ts, created_at, yyyymmdd) VALUES %s',
                $table, implode(', ', $chunk))));
        }

        $db->query('ANALYZE TABLE ' . $table);

        $spy = new class extends \OWA\Core\Db\Mysql {
            /** @var list<string> */
            public array $sql = [];
            public function __construct() {}
            function get_row($sql, array $params = []) { $this->sql[] = $sql; return ['current' => 0]; }
            public function prepare($string) { return addslashes((string) $string); }
        };

        $builder = new class($spy, $table) extends \OWA\Module\Base\Classes\Cube\Builder {
            public function __construct($db, $raw) { $this->db = $db; $this->tables['raw'] = $raw; $this->site_ids = ['owatest_1']; }
            public function builtAt(array $span) { return 1700000000002000; }
        };

        $builder->isCurrent(['name' => 'p' . $start, 'start' => $start, 'less_than' => $end]);

        $this->assertCount(1, $spy->sql);

        $plan = (array) $db->get_results('EXPLAIN ' . $spy->sql[0]);

        // The first subquery: a row of the span that arrived after the build.
        $late = null;

        foreach ($plan as $row) {
            if ((string) $row['id'] === '2') {
                $late = $row;
            }
        }

        $this->assertSame('r', $late['table'] ?? null, json_encode($plan));
        $this->assertSame('site_date', $late['key'], json_encode($plan));
        $this->assertMatchesRegularExpression('/(^|; )Using index($|;)/', (string) $late['Extra'],
            'from the index, reading no rows: ' . json_encode($plan));
    }
}
