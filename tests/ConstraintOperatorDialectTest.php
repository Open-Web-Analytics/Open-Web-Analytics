<?php

use PHPUnit\Framework\TestCase;

/**
 * Operators whose SQL differs by platform must come from the dialect.
 *
 * The constraint builder translates OWA's own operator tokens -- '==', '=~',
 * '=@', 'between' -- into SQL. Some of those have one spelling everywhere and
 * some do not, and the difference is not obvious by eye:
 *
 *   BETWEEN x AND y   ANSI SQL-92. Same in MySQL, PostgreSQL, SQLite, SQL
 *                     Server and Oracle. Written literally, like = and AND.
 *   REGEXP            MySQL. PostgreSQL spells it ~. Comes from a constant.
 *   LOCATE(a, b)      MySQL. PostgreSQL has POSITION(a IN b), SQL Server
 *                     CHARINDEX. Comes from a constant.
 *
 * A dialect supplies the WHOLE expression, not the function name, because the
 * shape differs too -- POSITION takes an infix IN rather than a comma.
 */
final class ConstraintOperatorDialectTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function constraintSqlFor( $operator, $value ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->resetBindings();
        $db->selectFrom( 'owa_session' );
        $db->selectColumn( 'id' );
        $db->where( 'referer', $value, $operator );

        return $db->generateSelectQuerySql();
    }

    /**
     * The failing case if LOCATE were written literally again: this asserts the
     * emitted SQL is what the DIALECT declares, so a hard-coded expression that
     * merely happens to match MySQL still has to agree with the constant.
     */
    public function testContainsUsesTheDialectExpression() {

        $sql = $this->constraintSqlFor( '=@', 'example.com' );

        $expected_shape = sprintf( OWA_SQL_CONTAINS, '?', '`referer`' );

        $this->assertStringContainsString(
            substr( $expected_shape, 0, strpos( $expected_shape, '(' ) + 1 ),
            $sql,
            'the "contains" constraint must be built from OWA_SQL_CONTAINS'
        );
    }

    public function testNotContainsUsesTheDialectExpression() {

        $sql = $this->constraintSqlFor( '!@', 'example.com' );

        $this->assertStringContainsString(
            substr( OWA_SQL_NOT_CONTAINS, strrpos( OWA_SQL_NOT_CONTAINS, ')' ) ),
            $sql,
            'the "does not contain" constraint must be built from OWA_SQL_NOT_CONTAINS'
        );
    }

    /**
     * BETWEEN is standard SQL, so it is deliberately NOT a dialect constant --
     * the same treatment = and AND get. This records that as a decision, since
     * it looks like an inconsistency next to the constants around it.
     */
    public function testBetweenIsBuiltInlineBecauseItIsStandardSql() {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->resetBindings();
        $db->selectFrom( 'owa_session' );
        $db->selectColumn( 'id' );
        $db->where( 'yyyymmdd', [ 'start' => 20260101, 'end' => 20260131 ], 'BETWEEN' );

        $this->assertStringContainsString(
            'BETWEEN',
            $db->generateSelectQuerySql(),
            'a BETWEEN constraint must still emit BETWEEN'
        );
    }
}
