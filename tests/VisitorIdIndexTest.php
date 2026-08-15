<?php

use PHPUnit\Framework\TestCase;

/**
 * visitor_id must be indexed on the fact tables.
 *
 * The column is declared on FactTable and is filtered on -- a visitor-scoped
 * report reaches where('visitor_id', ...) in the REST controller -- but it never
 * had an index, unlike session_id and site_id declared beside it. Each such
 * query was a full scan of the largest tables in the schema.
 *
 * Two halves are covered here, because they reach installations by different
 * routes: FactTable::setIndex() covers tables built by a new installation, and
 * Update014 covers tables that already exist. A fresh install never runs the
 * updates -- it writes the required schema version directly -- so neither half
 * substitutes for the other.
 */
final class VisitorIdIndexTest extends TestCase
{
    /** @var string[] */
    private $tables = array();

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping visitor_id index test.');
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

    private function makeFactLikeTable($prefix = 'owa_test_vis_')
    {
        $name = $prefix . bin2hex(random_bytes(4));
        $this->tables[] = $name;

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, visitor_id BIGINT, session_id BIGINT, PRIMARY KEY (id))',
            $name
        ));

        return $name;
    }

    /**
     * The declaration that new installations are built from. Asserted on the
     * column object rather than the source text, so it holds regardless of how
     * the constructor is written.
     */
    public function testFactTableDeclaresVisitorIdAsIndexed()
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.request');

        $col = $entity->getProperty('visitor_id');

        $this->assertNotNull($col, 'fact tables should declare visitor_id');
        $this->assertTrue((bool) $col->get('index'), 'visitor_id should be declared as an index');
    }

    /** session_id has always been indexed; this guards against losing it. */
    public function testSessionIdRemainsIndexed()
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.request');

        $this->assertTrue((bool) $entity->getProperty('session_id')->get('index'));
    }

    /** The migration, for tables that already exist without the index. */
    public function testUpdateIndexesAnExistingTable()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeFactLikeTable();

        $this->assertFalse($db->indexExists($t, 'visitor_id'), 'setup should start unindexed');

        $this->assertContains($t, $db->getTablesWithColumn('visitor_id'), 'the table should be discovered');

        $db->addIndex($t, 'visitor_id');

        $this->assertTrue($db->indexExists($t, 'visitor_id'), 'the column should now be indexed');
    }

    /** Running it twice must not produce a second copy -- addIndex is idempotent. */
    public function testIndexingIsIdempotent()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeFactLikeTable();

        $db->addIndex($t, 'visitor_id');
        $db->addIndex($t, 'visitor_id');

        $row = $db->get_row(sprintf(
            "SELECT COUNT(*) AS n FROM ( SELECT INDEX_NAME FROM information_schema.STATISTICS "
          . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' GROUP BY INDEX_NAME "
          . "HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'visitor_id' ) x",
            $t
        ));

        $this->assertSame(1, (int) $row['n'], 'only one visitor_id index should exist');
    }

    /** Tables outside the owa_ prefix are not OWA's to alter. */
    public function testForeignTablesAreNotDiscovered()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $t = $this->makeFactLikeTable('wp_notowa_');

        $this->assertNotContains($t, $db->getTablesWithColumn('visitor_id'));
    }
}
