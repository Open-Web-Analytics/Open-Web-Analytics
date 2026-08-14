<?php

use PHPUnit\Framework\TestCase;

/**
 * addIndex() must not add a second copy of an index it already has.
 *
 * The statement behind it was 'ALTER TABLE %s ADD INDEX (%s)' -- unnamed, so
 * MySQL assigned the name. Repeating the call therefore succeeded and produced
 * site_id, site_id_2, site_id_3 rather than failing as a duplicate. Two update
 * scripts index overlapping tables (Update005 adds yyyymmdd, Update007 adds
 * site_id/session_id to the same set), and a re-run adds another copy again, so
 * upgraded installations accumulate exact duplicates -- paid for on every
 * INSERT, which on a tracker is the hot path.
 *
 * These run against a scratch table so nothing existing is touched.
 */
final class AddIndexIdempotentTest extends TestCase
{
    /** @var string scratch table, dropped in tearDown */
    private $table;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping index test.');
        }

        $this->table = 'owa_test_idx_' . bin2hex(random_bytes(4));

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, site_id INT, yyyymmdd INT, action_name VARCHAR(32), PRIMARY KEY (id))',
            $this->table
        ));
    }

    protected function tearDown(): void
    {
        if ($this->table) {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf('DROP TABLE IF EXISTS %s', $this->table));
        }
    }

    /** How many indexes currently cover exactly this column list. */
    private function copies($columns)
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $row = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM ( SELECT INDEX_NAME FROM information_schema.STATISTICS "
          . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' GROUP BY INDEX_NAME "
          . "HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = '%s' ) x",
            $this->table,
            $columns
        ));

        return (int) $row['n'];
    }

    /** The regression: calling twice used to leave two indexes. */
    public function testRepeatedCallsLeaveASingleIndex()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->addIndex($this->table, 'yyyymmdd');
        $this->assertSame(1, $this->copies('yyyymmdd'), 'first call should create the index');

        $db->addIndex($this->table, 'yyyymmdd');
        $db->addIndex($this->table, 'yyyymmdd');

        $this->assertSame(1, $this->copies('yyyymmdd'), 'repeat calls must not add copies');
    }

    /** Multi-column lists are matched as a set, whitespace and all. */
    public function testMultiColumnIndexIsAlsoIdempotent()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->addIndex($this->table, 'site_id, action_name');
        $db->addIndex($this->table, 'site_id,action_name');

        $this->assertSame(1, $this->copies('site_id,action_name'), 'spacing must not defeat the check');
    }

    /**
     * An index MySQL named itself is what existing installations have, so the
     * check has to recognise it by columns rather than by name.
     */
    public function testAnAutoNamedIndexIsRecognised()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        // Exactly what the old unnamed statement produced.
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (site_id)', $this->table));
        $this->assertSame(1, $this->copies('site_id'));

        $this->assertTrue($db->indexExists($this->table, 'site_id'), 'auto-named index should be seen');

        $db->addIndex($this->table, 'site_id');
        $this->assertSame(1, $this->copies('site_id'), 'must not add alongside an auto-named index');
    }

    /** A column list that is not a bare identifier reaches an ALTER, so refuse it. */
    public function testNonIdentifierColumnIsRefused()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertFalse($db->addIndex($this->table, 'yyyymmdd); DROP TABLE x; --'));
        $this->assertSame(0, $this->copies('yyyymmdd'), 'nothing should have been indexed');
    }
}
