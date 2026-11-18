<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every join constant the dialect defines has to be a join the database accepts.
 *
 * "LEFT INNER JOIN" and "RIGHT INNER JOIN" are not SQL -- a join is inner or
 * outer, not both -- and nothing downstream distinguishes a rejected statement
 * from an empty result: Db::getOneRow() answers null for both, so the overlay
 * launcher's urlForPath() would read one as "no document matches this path"
 * and draw an empty heatmap.
 *
 * So the test is not "is the constant spelled correctly". It is "does the
 * server accept a statement built with it", and it extends to any join
 * constant added later.
 */
final class SqlJoinConstantsTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function joinConstants(): array
    {
        $cases = [];

        foreach ( get_defined_constants() as $name => $value ) {

            if ( strpos( $name, 'OWA_SQL_JOIN' ) === 0 ) {
                $cases[ $name ] = [ $name, (string) $value ];
            }
        }

        return $cases;
    }

    public function testTheDialectDefinesAtLeastOneJoin(): void
    {
        $this->assertNotEmpty( self::joinConstants(),
            'no OWA_SQL_JOIN* constants are defined, so the cases below would '
            . 'assert nothing' );
    }

    /**
     * @dataProvider joinConstants
     */
    public function testTheDatabaseAcceptsTheJoin( string $name, string $clause ): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the server is what decides whether a join is valid' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        // Self-join on a table every installation has, so the statement exercises
        // the join clause and nothing else. LIMIT 0 keeps it from reading rows.
        $sql = sprintf(
            'SELECT a.id FROM owa_site AS a %s owa_site AS b ON a.id = b.id LIMIT 0',
            $clause );

        $this->assertNotFalse( $db->query( $sql ),
            "$name expands to \"$clause\", which the database refused. A query "
            . 'built with it returns null, which callers read as "no rows".' );
    }

    /**
     * The two non-SQL joins are not defined: a join is inner or outer, so there
     * is nothing for them to mean.
     */
    public function testTheJoinsThatWereNotSqlAreNotDefined(): void
    {
        $this->assertFalse( defined( 'OWA_SQL_JOIN_LEFT_INNER' ) );
        $this->assertFalse( defined( 'OWA_SQL_JOIN_RIGHT_INNER' ) );
    }
}
