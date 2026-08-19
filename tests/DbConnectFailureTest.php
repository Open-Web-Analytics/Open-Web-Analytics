<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A refused database connection must degrade, not kill the request.
 *
 * This was observed in production: the database refused connections for a few
 * seconds, and every page render and tracking request in that window died with
 *
 *   PHP Warning:  mysqli_real_connect(): (HY000/2002): Connection refused
 *   PHP Fatal error: Uncaught Error: mysqli object is not fully initialized
 *
 * rather than logging "Could not connect to database" and moving on -- which is
 * what connect() visibly intends to do, and could never do.
 *
 * Three separate things conspired, and each is pinned below:
 *
 *  1. mysqli_init() returns an object whether or not the subsequent connect
 *     succeeds, so `if ( ! $this->connection )` was never true and the failure
 *     branch was unreachable.
 *  2. mysqli_real_connect()'s return value -- the only reliable signal -- was
 *     discarded.
 *  3. The handle was then used immediately by mysqli_set_charset(), which in
 *     PHP 8 raises an Error rather than an Exception, so query()'s
 *     `catch (\Exception)` could not have caught it either.
 */
final class DbConnectFailureTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Core/Db/Mysql.php');
    }

    private function method(string $name): string
    {
        $src   = $this->source();
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . '() must exist');

        $body = substr($src, $start);
        $end  = strpos($body, "\n    }\n");

        $this->assertNotFalse($end, $name . '() must be a complete method');

        return substr($body, 0, $end + 6);
    }

    /**
     * The connect result decides, not the truthiness of the handle.
     */
    public function testTheConnectResultIsWhatDecidesSuccess(): void
    {
        $connect = $this->method('connect');

        $this->assertMatchesRegularExpression(
            '/\$\w+\s*=\s*mysqli_real_connect\(/',
            $connect,
            'the return value of mysqli_real_connect() must be captured, since the handle is '
          . 'truthy either way'
        );
    }

    /**
     * Nothing may touch the handle between a failed connect and the return.
     *
     * This is the assertion that actually prevents the fatal: charset selection
     * on an unconnected handle is what raised, and any future statement added
     * in that gap would raise the same way.
     */
    public function testAFailedConnectTouchesTheHandleNoFurther(): void
    {
        $connect = $this->method('connect');

        $call  = strpos($connect, 'mysqli_real_connect(');
        $guard = strpos($connect, 'if ( ! $connected )');

        $this->assertNotFalse($guard, 'the failure must be handled explicitly');
        $this->assertGreaterThan($call, $guard, 'and handled after the attempt');

        // Between the guard and its return, the only permitted operations are
        // releasing the handle, recording the status, and reporting.
        $branch = substr($connect, $guard, strpos($connect, 'return false;', $guard) - $guard);

        $this->assertStringNotContainsString('mysqli_', $branch,
            'a handle that never connected must not be handed to any mysqli call');

        $this->assertStringContainsString('$this->connection = null', $branch,
            'the dead handle must be released so later code cannot mistake it for a connection');

        $this->assertStringContainsString('$this->connection_status = false', $branch,
            'and the status must say so');
    }

    /**
     * query() must not run against a connection it failed to obtain.
     *
     * Releasing the handle in connect() moves the failure rather than fixing it
     * unless query() stops as well: every mysqli_* call in query() would then
     * be handed null.
     */
    public function testQueryStopsWhenTheConnectionCouldNotBeObtained(): void
    {
        $query = $this->method('query');

        $this->assertMatchesRegularExpression(
            '/\$\w+\s*=\s*\$this->connect\(\)/',
            $query,
            'query() must observe whether its reconnect succeeded'
        );

        $reconnect = strpos($query, '$this->connect()');
        $first_use = strpos($query, 'mysqli_query(');

        $this->assertNotFalse($first_use);

        $guard = strpos($query, 'if ( ! $connected )', $reconnect);

        $this->assertNotFalse($guard, 'a failed reconnect must be handled');
        $this->assertLessThan($first_use, $guard,
            'and handled before the connection is used');
    }

    /**
     * The accessors above query() already degrade on a falsy result, which is
     * what makes returning false safe rather than merely quieter. Pinned
     * because the fix depends on it: if either started assuming a result
     * object, a connection failure would fatal again one layer up.
     */
    public function testTheAccessorsAboveQueryTolerateAFalsyResult(): void
    {
        $this->assertStringContainsString('if (!$this->new_result) {', $this->method('get_results'),
            'get_results() must return early when the query produced nothing');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$result\s*\|\|/',
            $this->method('get_row'),
            'get_row() must return early when the query produced nothing'
        );
    }

    /**
     * The test suite decides whether a database is available by probing with a
     * real round-trip, and 100+ tests skip on the result. Before this fix the
     * probe worked by catching the fatal; it now gets a null row instead. Pin
     * the property the probe actually relies on -- that a failed probe returns
     * rather than escapes -- so a future change here cannot silently turn every
     * DB-dependent test into an error on a runner with no database.
     */
    public function testTheDatabaseProbeReportsRatherThanRaises(): void
    {
        $bootstrap = (string) file_get_contents(__DIR__ . '/bootstrap_owa.php');

        $this->assertStringContainsString('function owa_test_db_available', $bootstrap);

        $this->assertStringContainsString('catch (\Throwable $e)', $bootstrap,
            'the probe must remain total: it is how tests detect the absence of a database. '
          . 'Before this fix a failed probe raised an Error and was caught here; it now returns '
          . 'a null row instead, and both paths must keep producing a verdict rather than escaping');

        $this->assertStringContainsString('return is_array($row)', $bootstrap,
            'and the verdict must come from the round-trip result, not from connection_status, '
          . 'which mysqli_init() makes unreliable');
    }
}
