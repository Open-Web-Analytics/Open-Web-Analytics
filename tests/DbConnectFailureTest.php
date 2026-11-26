<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A refused database connection must degrade, not kill the request.
 *
 * The database refusing connections for a few seconds must not kill every page
 * render and tracking request in that window with
 *
 *   PHP Warning:  mysqli_real_connect(): (HY000/2002): Connection refused
 *   PHP Fatal error: Uncaught Error: mysqli object is not fully initialized
 *
 * but log "Could not connect to database" and move on. Three things are
 * tested below, on a driver pointed at a port nothing listens on:
 *
 *  1. mysqli_init() returns an object whether or not the subsequent connect
 *     succeeds, so `! $this->connection` is not the test of failure.
 *  2. mysqli_real_connect()'s return value is the only reliable signal.
 *  3. A failed handle must not reach mysqli_set_charset(), which in PHP 8
 *     raises an Error rather than an Exception, so query()'s
 *     `catch (\Exception)` cannot catch it.
 */
final class DbConnectFailureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @var list<string> alerts the driver logged */
    private array $alerts = [];

    /** @var list<string> PHP warnings raised while the driver ran */
    private array $warnings = [];

    /** A mysqli driver pointed at a port nothing listens on, logging into $this->alerts. */
    private function refused(): \OWA\Core\Db\Mysql
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli is not loaded');
        }

        $db = new \OWA\Core\Db\Mysql('127.0.0.1', 1, 'owa_none', 'nobody', 'nothing');

        $alerts = &$this->alerts;
        $db->e  = new class($alerts) {
            private $alerts;
            public function __construct(array &$alerts) { $this->alerts = &$alerts; }
            public function alert($message) { $this->alerts[] = (string) $message; }
            public function __call($name, $args) { return null; }
        };

        return $db;
    }

    /**
     * Runs $fn, collecting PHP warnings rather than failing on them: the
     * refused connect raises mysqli_real_connect()'s own.
     */
    private function collectingWarnings(callable $fn)
    {
        set_error_handler(function (int $no, string $msg) {
            $this->warnings[] = $msg;
            return true;
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A refused connect answers false and leaves no handle behind.
     *
     * mysqli_init() returns an object whether or not the connect succeeds, so
     * the handle is truthy either way; and any mysqli call on a handle that
     * never connected raises an Error, which would end the test here.
     */
    public function testARefusedConnectAnswersFalseAndReleasesTheHandle(): void
    {
        $db = $this->refused();

        $this->assertFalse($this->collectingWarnings(fn () => $db->connect()));

        $this->assertNull($db->connection,
            'the dead handle must be released so later code cannot mistake it for a connection');
        $this->assertFalse($db->connection_status, 'and the status must say so');
        $this->assertSame(['Could not connect to database.'], $this->alerts);

        foreach ($this->warnings as $warning) {
            $this->assertStringStartsWith('mysqli_real_connect()', $warning,
                'a handle that never connected must not be handed to any other mysqli call');
        }
    }

    /**
     * query() must not run against a connection it failed to obtain, and the
     * accessors above it degrade on the falsy result.
     */
    public function testQueriesDegradeWhenTheConnectionCouldNotBeObtained(): void
    {
        $db = $this->refused();

        $this->collectingWarnings(function () use ($db) {
            $this->assertFalse($db->query('SELECT 1'));
            $this->assertEmpty($db->get_results('SELECT 1'));
            $this->assertNull($db->get_row('SELECT 1'));
        });
    }

    /**
     * A failure is sticky within the connection object.
     *
     * Releasing the dead handle makes `! $this->connection` true again, so
     * without the latch every query re-dials a database that is down: one
     * warning and one alert per query instead of one per request.
     */
    public function testAFailedConnectIsNotRetriedOnEveryQuery(): void
    {
        $db = $this->refused();

        $this->collectingWarnings(fn () => $db->connect());
        $warned = count($this->warnings);

        $this->collectingWarnings(function () use ($db) {
            $db->query('SELECT 1');
            $db->get_row('SELECT 1');
            $db->connect();
        });

        $this->assertSame(['Could not connect to database.'], $this->alerts, 'connect() re-dialled after a failure');
        $this->assertCount($warned, $this->warnings, 'connect() re-dialled after a failure');

        // ...and an explicit close clears it, so a deliberate reconnect works.
        $db->close();
        $this->collectingWarnings(fn () => $db->connect());

        $this->assertCount(2, $this->alerts, 'close() must clear the latch, so an explicit reconnect is still possible');
    }

    /**
     * The test suite decides whether a database is available by probing with a
     * real round-trip, and 100+ tests skip on the result. A failed probe gets
     * a null row. Pin the property the probe actually relies on -- that a failed probe returns
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

        $this->assertStringContainsString('$ok  = is_array($row)', $bootstrap,
            'and the verdict must come from the round-trip result, not from connection_status, '
          . 'which mysqli_init() makes unreliable');
    }
}
