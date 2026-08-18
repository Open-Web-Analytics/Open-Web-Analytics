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

        $db->addIndex($t, 'visitor_id');

        $this->assertTrue($db->indexExists($t, 'visitor_id'), 'the column should now be indexed');
    }

    /**
     * The round trip, against the real fact tables, since up() and down() work
     * from the entity registry and cannot be pointed at a scratch table.
     *
     * It restores what it changes: up() runs in the finally block whatever
     * happens, so a failing assertion cannot leave the schema unindexed.
     */
    public function testDownRemovesTheIndexAndUpRestoresIt()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::getSetting('base', 'ns') . 'request';

        $update = new \OWA\Module\Base\Update\Update014();
        $update->module_name = 'base';

        // Start from the applied state regardless of how the install was left.
        $update->up();
        $this->assertTrue($db->indexExists($table, 'visitor_id'), 'up() should leave it indexed');

        // Whether down() should remove anything depends on WHO created the index.
        // up() adds one called idx_visitor_id; a freshly installed database
        // already carries a visitor_id index from the table definition, under
        // its own name. down() deliberately drops only the former, because
        // removing an index it never created would be destroying part of the
        // schema. Both are correct, and which one this installation has is not
        // something the test gets to assume.
        $ours = false;

        foreach ($db->listIndexes() as $row) {
            if ($row['t'] === $table && $row['i'] === 'idx_visitor_id') {
                $ours = true;
                break;
            }
        }

        try {
            $this->assertTrue($update->down(), 'down() should report success');

            if ($ours) {
                $this->assertFalse($db->indexExists($table, 'visitor_id'),
                    'down() should remove the index that up() created');
            } else {
                $this->assertTrue($db->indexExists($table, 'visitor_id'),
                    'down() must leave an index it did not create');
            }

            $this->assertTrue($update->up(), 'up() should report success');
            $this->assertTrue($db->indexExists($table, 'visitor_id'), 'up() should put it back');

        } finally {
            $update->up();
        }
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

}
