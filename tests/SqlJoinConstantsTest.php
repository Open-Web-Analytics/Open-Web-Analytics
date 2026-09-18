<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every join constant the dialect defines has to be a join the database accepts.
 *
 * OWA_SQL_JOIN_LEFT_INNER expanded to "LEFT INNER JOIN" and
 * OWA_SQL_JOIN_RIGHT_INNER to "RIGHT INNER JOIN". Neither is SQL -- a join is
 * inner or outer, not both -- so any query built with one was rejected by the
 * server.
 *
 * It survived because nothing downstream distinguishes a rejected statement from
 * an empty result: Db::getOneRow() answers null for both. The overlay launcher's
 * urlForPath() read that as "no document matches this path" and returned '',
 * which draws an empty heatmap -- the exact symptom its own docblock warns is
 * indistinguishable from a broken one. The only visible trace was an ERROR line
 * per call in OWA's log, on a screen nobody watches.
 *
 * So the test is not "is the constant spelled correctly". It is "does the server
 * accept a statement built with it", which is the property that was actually
 * missing, and it extends to any join constant added later.
 */
final class SqlJoinConstantsTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function joinConstants(): array
    {
        $cases = array();

        foreach ( get_defined_constants() as $name => $value ) {

            if ( strpos( $name, 'OWA_SQL_JOIN' ) === 0 ) {
                $cases[ $name ] = array( $name, (string) $value );
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
     * The two that were wrong are gone rather than corrected, because there is
     * nothing to correct them to: a join is inner or outer.
     */
    public function testTheJoinsThatWereNotSqlAreNotDefined(): void
    {
        $this->assertFalse( defined( 'OWA_SQL_JOIN_LEFT_INNER' ) );
        $this->assertFalse( defined( 'OWA_SQL_JOIN_RIGHT_INNER' ) );
    }
}
