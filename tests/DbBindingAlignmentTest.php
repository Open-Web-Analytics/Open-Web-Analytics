<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\Db\Pdo as OwaPdo;

/**
 * Values must reach the columns they were built for.
 *
 * A misplaced binding is the worst shape of database bug this codebase can
 * have, because it is usually silent. PDO binds whatever it is handed and
 * MySQL reports a type error against whichever column happens to line up --
 * "Incorrect integer value: 'ludhiana' for column 'is_browser'" is what that
 * looks like in a log, with nothing to indicate the values were offset rather
 * than the data bad. An offset that puts a string in another string column
 * raises nothing at all and writes the wrong value.
 *
 * Two guards, because they fail differently:
 *
 *   - binding by a running position rather than by the array key, so a gap in
 *     the bindings array cannot shift every parameter after it;
 *   - refusing to execute when placeholders and values disagree in number,
 *     which turns a silent misalignment into a loud one.
 */
final class DbBindingAlignmentTest extends TestCase
{
    public function testPlaceholdersAreCounted(): void
    {
        $this->assertSame( 2, OwaPdo::countPlaceholders( 'INSERT into t (a, b) VALUES (?, ?)' ) );
        $this->assertSame( 2, OwaPdo::countPlaceholders( 'SELECT * FROM t WHERE a = ? AND b = ?' ) );
        $this->assertSame( 0, OwaPdo::countPlaceholders( 'SELECT 1' ) );
    }

    public function testAQuestionMarkInsideAStringLiteralIsNotAPlaceholder(): void
    {
        /*
         * A miscount here would REFUSE a legitimate query, which is worse than
         * the problem being guarded against -- so the counter has to understand
         * quoting even though this driver rarely emits quoted text.
         */
        $this->assertSame(
            1, OwaPdo::countPlaceholders( 'SELECT * FROM t WHERE a = "why?" AND b = ?' ) );

        $this->assertSame(
            0, OwaPdo::countPlaceholders( "SELECT * FROM t WHERE a = 'what?'" ) );

        $this->assertSame(
            1, OwaPdo::countPlaceholders( "SELECT * FROM t WHERE a = 'it\\'s ok?' AND b = ?" ) );
    }

    public function testTheDriverBindsByPositionNotByArrayKey(): void
    {
        /*
         * The fragility this replaces: bindValue( $i + 1 ) on the array KEY is
         * correct only while the bindings array is contiguous and zero-based.
         * Nothing enforced that, so a single unset() or array_filter() upstream
         * would have shifted every parameter after the gap -- silently, and
         * exactly like the failure this test is named for.
         *
         * Asserted against the source, because the binding happens inside a
         * try block against a live connection and cannot be observed otherwise.
         */
        $source = file_get_contents( OWA_DIR . 'Core/Db/Pdo.php' );

        $this->assertMatchesRegularExpression(
            '/\$statement->bindValue\(\s*\$position/', $source,
            'The driver must bind to a running position. Binding to the array key is correct '
            . 'only while the bindings array is contiguous, and a gap would put every later '
            . 'value in the wrong column.' );

        $this->assertDoesNotMatchRegularExpression(
            '/\$statement->bindValue\(\s*\$\w+\s*\+\s*1/', $source,
            'The driver is binding by array key again.' );
    }

    public function testEveryRealQueryStillRuns(): void
    {
        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        /*
         * The guard's own risk: if countPlaceholders() ever disagreed with what
         * the driver emits, working queries would start being refused. This
         * exercises the ordinary paths -- a select with a where clause, and one
         * with none -- against the real connection.
         */
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $row = $db->get_row( 'SELECT 1 AS ok' );

        $this->assertSame( 1, (int) ( $row['ok'] ?? 0 ), 'A query with no bindings must run.' );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $site->load( 'definitely-not-a-real-domain.invalid', 'domain' );

        $this->assertNotTrue(
            $site->wasPersisted(),
            'A bound select must run and find nothing, rather than being refused.' );
    }
}
