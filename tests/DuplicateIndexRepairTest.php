<?php

use PHPUnit\Framework\TestCase;

/**
 * Update013 removes indexes that duplicate another exactly, and nothing else.
 *
 * The duplicates exist because addIndex() ran unnamed, so MySQL assigned the
 * name and repeated calls produced site_id, site_id_2, site_id_3 instead of
 * failing. The copies can never be chosen over the original; they only cost
 * space and are written on every INSERT.
 *
 * The risk in a repair like this is removing one too many, so the assertions
 * below are mostly about what must survive: one index per column list, PRIMARY
 * untouched, a unique index not mistaken for a copy of a non-unique one, and
 * nothing outside the 'owa_' prefix considered at all.
 */
final class DuplicateIndexRepairTest extends TestCase
{
    /** @var string[] scratch tables, dropped in tearDown */
    private $tables = array();

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping index repair test.');
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->tables as $t) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $t));
        }

        $this->tables = array();
    }

    /** A scratch table carrying the given prefix. */
    private function makeTable($prefix = 'owa_test_dup_')
    {
        $name = $prefix . bin2hex(random_bytes(4));
        $this->tables[] = $name;

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, site_id INT, yyyymmdd INT, guid BIGINT, PRIMARY KEY (id))',
            $name
        ));

        return $name;
    }

    /** Index names present on a table, excluding PRIMARY. */
    private function indexNames($table)
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $rows = $db->get_results(sprintf(
            "SELECT DISTINCT INDEX_NAME AS i FROM information_schema.STATISTICS "
          . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME <> 'PRIMARY' "
          . "ORDER BY INDEX_NAME",
            $table
        ));

        $names = array();

        foreach ((array) $rows as $r) {
            $names[] = $r['i'];
        }

        return $names;
    }

    /** Reproduce the historic state, then repair it. */
    public function testDuplicatesAreRemovedAndOneSurvives()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        // Exactly what the old unnamed statement produced three times over.
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (yyyymmdd)', $t));
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (yyyymmdd)', $t));
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (yyyymmdd)', $t));

        $this->assertCount(3, $this->indexNames($t), 'setup should have produced three copies');

        $dupes = $db->getDuplicateIndexes();

        $mine = array_values(array_filter($dupes, function ($d) use ($t) {
            return $d['t'] === $t;
        }));

        $this->assertCount(2, $mine, 'two of the three copies are removable');

        \OWA\Module\Base\Update\Update013::repair(\OWA\Core\CoreAPI::dbSingleton(), $t);

        $this->assertCount(1, $this->indexNames($t), 'exactly one index must survive');
    }

    /** A clean table must be left completely alone. */
    public function testNoDuplicatesIsANoOp()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->query(sprintf('ALTER TABLE %s ADD INDEX (yyyymmdd)', $t));
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (site_id)', $t));

        $before = $this->indexNames($t);

        \OWA\Module\Base\Update\Update013::repair(\OWA\Core\CoreAPI::dbSingleton(), $t);

        $this->assertSame($before, $this->indexNames($t), 'distinct indexes must be untouched');
    }

    /** PRIMARY is never a candidate, however the table is indexed. */
    public function testPrimaryKeySurvives()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->query(sprintf('ALTER TABLE %s ADD INDEX (id)', $t));
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (id)', $t));

        \OWA\Module\Base\Update\Update013::repair(\OWA\Core\CoreAPI::dbSingleton(), $t);

        $row = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS "
          . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = 'PRIMARY'",
            $t
        ));

        $this->assertSame(1, (int) $row['n'], 'PRIMARY must still be there');
    }

    /** A unique index is not a copy of a non-unique one over the same column. */
    public function testUniqueIndexIsNotTreatedAsADuplicate()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable();

        $db->query(sprintf('ALTER TABLE %s ADD INDEX (guid)', $t));
        $db->query(sprintf('ALTER TABLE %s ADD UNIQUE INDEX uniq_guid (guid)', $t));

        \OWA\Module\Base\Update\Update013::repair(\OWA\Core\CoreAPI::dbSingleton(), $t);

        $this->assertContains('uniq_guid', $this->indexNames($t), 'the unique index must survive');
        $this->assertCount(2, $this->indexNames($t), 'neither should be removed');
    }

    /** Tables outside the owa_ prefix are not OWA's to touch. */
    public function testTablesOutsideThePrefixAreIgnored()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeTable('wp_notowa_');

        $db->query(sprintf('ALTER TABLE %s ADD INDEX (yyyymmdd)', $t));
        $db->query(sprintf('ALTER TABLE %s ADD INDEX (yyyymmdd)', $t));

        \OWA\Module\Base\Update\Update013::repair(\OWA\Core\CoreAPI::dbSingleton(), $t);

        $this->assertCount(2, $this->indexNames($t), 'a foreign table must be left alone');
    }
}
