<?php

use PHPUnit\Framework\TestCase;

/**
 * Adding an INDEXED column to a table that already exists.
 *
 * THE BUG THIS EXISTS FOR, ALSO FOUND IN PRODUCTION
 *
 * DbColumn::getDefinition() used to append `, INDEX (col)` to the column's own
 * definition. Inside a CREATE TABLE's parenthesised column list that is legal
 * and does the right thing. Everywhere else it is a syntax error, and every
 * other caller of getDefinition() is an ALTER:
 *
 *     ALTER TABLE owa_custom_report ADD report_type VARCHAR(255), INDEX (report_type)
 *     ERROR 1064: ... near '(report_type)'
 *
 * So Entity::addColumn() had never once worked for an indexed column -- not a
 * regression, a hole that had always been there. It stayed invisible because a
 * fresh install CREATEs every table from the current entity definition, index
 * clauses and all, so the ALTER path is only reached by an UPGRADE of an older
 * install. That is where it surfaced: a live site at schema 25 stopped dead on
 * Update026 with the statement above.
 *
 * The fix is that a column definition describes a column. Table-level clauses
 * are written by whoever is composing the statement -- inline by createTable(),
 * as a separate ALTER by addColumn().
 *
 * These tests are in two halves on purpose. The first needs no database, so it
 * runs in CI's configless job where the ALTER can never be attempted; the
 * second actually issues the statement, because a scan of the definition string
 * would not have caught a driver composing the clause back in.
 */
final class AddIndexedColumnTest extends TestCase
{
    /**
     * A column definition contains only the column.
     *
     * Deliberately built with nothing but a data type and the index flag, so
     * the assertion needs none of the OWA_DTD_* constants -- those arrive with
     * a database driver, and this half of the test is here to run without one.
     */
    public function testAnIndexedColumnsDefinitionCarriesNoIndexClause(): void
    {
        $column = new \OWA\Module\Base\Classes\DbColumn( 'report_type', 'VARCHAR(255)' );
        $column->setIndex();

        $this->assertSame( 'VARCHAR(255)', $column->getDefinition(),
            'The index clause is back in the column definition. It makes '
            . '"ALTER TABLE t ADD col VARCHAR(255), INDEX (col)", which MySQL rejects, '
            . 'so every upgrade that adds an indexed column dies on the statement.' );

        $this->assertTrue( $column->isIndexed(),
            'the flag itself has to survive -- createTable() and addColumn() both ask' );
    }

    /** And a column that asked for no index says so. */
    public function testAnUnindexedColumnIsNotIndexed(): void
    {
        $column = new \OWA\Module\Base\Classes\DbColumn( 'name', 'VARCHAR(255)' );

        $this->assertFalse( $column->isIndexed() );
        $this->assertSame( 'VARCHAR(255)', $column->getDefinition() );
    }

    /**
     * THE STATEMENT, ISSUED.
     *
     * A scratch table built from the real CustomReport entity, minus the
     * indexed column, then asked to add it back -- which is precisely the shape
     * of an upgrade reaching an older install's table.
     *
     * The index is checked as well as the column, because dropping the clause
     * from the definition without adding it back somewhere else would make this
     * statement valid and quietly lose the index on every upgraded install.
     */
    public function testAddingAnIndexedColumnToAnExistingTable(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'the statement has to be issued to a database to be refused by one' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = $this->scratchCustomReport();
        $table  = $entity->getTableName();

        try {

            $db->createTable( $entity );

            // Back to what an older install's table looks like.
            $db->query( "ALTER TABLE $table DROP COLUMN report_type" );

            $this->assertEmpty( $this->columns( $db, $table, 'report_type' ),
                'the fixture did not actually remove the column' );

            $this->assertTrue( $entity->addColumn( 'report_type' ),
                'addColumn() failed for an indexed column. That is the live failure: the '
                . 'index clause was glued into the column definition, producing '
                . '"ALTER TABLE ... ADD report_type VARCHAR(255), INDEX (report_type)".' );

            $this->assertNotEmpty( $this->columns( $db, $table, 'report_type' ),
                'the column is not there' );

            $this->assertTrue( $db->indexExists( $table, 'report_type' ),
                'the column arrived without its index. The declaration asked for one, and '
                . 'an upgraded install would be the only kind missing it -- exactly the '
                . 'divergence that is never noticed until a report goes slow.' );

            // Idempotent: the guard's job is to make the second run a no-op
            // rather than an error, so a half-finished upgrade can be re-run.
            $update = new \OWA\Module\Base\Update\Update026;

            $again = new ReflectionMethod( '\OWA\Core\Update', 'addColumnIfMissing' );
            $again->setAccessible( true );

            $this->assertTrue( (bool) $again->invoke( $update, $entity, 'report_type' ),
                'adding an already-present indexed column reported failure' );

        } finally {

            $db->query( "DROP TABLE IF EXISTS $table" );
        }
    }

    /**
     * CREATE TABLE still gets its indexes.
     *
     * The clause moved out of the column definition and into createTable(). If
     * it moved out of one and not into the other, fresh installs would silently
     * stop indexing -- which no test above would notice, because they all work
     * on a table the ALTER path fixed up.
     */
    public function testCreateTableStillIndexesTheColumnsThatAskedForIt(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'needs a database' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = $this->scratchCustomReport();
        $table  = $entity->getTableName();

        try {

            $this->assertTrue( (bool) $db->createTable( $entity ),
                'the CREATE TABLE was refused' );

            $this->assertTrue( $db->indexExists( $table, 'report_type' ),
                'a freshly created table has no index on a column that declared one' );

        } finally {

            $db->query( "DROP TABLE IF EXISTS $table" );
        }
    }

    /** The real entity, pointed at a table nothing else is using. */
    private function scratchCustomReport()
    {
        $name = 'test_idxcol_' . bin2hex( random_bytes( 4 ) );

        return new class( $name ) extends \OWA\Module\Base\Entity\CustomReport {

            public function __construct( $name ) {

                parent::__construct();

                $this->setTableName( $name );
            }
        };
    }

    /** @return array the SHOW COLUMNS rows for one column, empty if absent */
    private function columns( $db, $table, $column ): array
    {
        return (array) $db->get_results( "SHOW COLUMNS FROM $table LIKE '$column'" );
    }
}
