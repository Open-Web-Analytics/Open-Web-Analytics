<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A refused BOUND statement is logged, and classified, on both drivers.
 *
 * Every entity create() and update() binds its values. On the mysqli driver
 * the error of a prepared statement lives on the statement, not the
 * connection, and executeBound() closed the statement without reading it, so
 * query()'s connection check saw nothing: a refused bound statement was not
 * logged at all, and lastQueryWasConstraintViolation() could not tell a lost
 * insert race from a fault. PDO raises and was unaffected.
 *
 * Each driver is built from the installation's credentials, so both are
 * exercised whichever one this environment is configured with.
 */
final class DbBoundRefusalTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function drivers(): array
    {
        return [
            'mysqli'    => [\OWA\Core\Db\Mysql::class],
            'pdo_mysql' => [\OWA\Core\Db\PdoMysql::class],
        ];
    }

    private function driver(string $class)
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This asks the database server.');
        }

        if ($class === \OWA\Core\Db\Mysql::class && !function_exists('mysqli_connect')) {
            $this->markTestSkipped('mysqli is not installed.');
        }

        if ($class === \OWA\Core\Db\PdoMysql::class && !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }

        $s  = fn (string $k) => \OWA\Core\CoreAPI::getSetting('base', $k);
        $db = new $class($s('db_host'), $s('db_port'), $s('db_name'), $s('db_user'), $s('db_password'));

        $this->assertNotFalse($db->connect(), "$class could not connect");

        $db->query('CREATE TEMPORARY TABLE bound_refusal_probe (id INT PRIMARY KEY)');

        ( new ReflectionProperty( \OWA\Core\Db::class, 'query_error_count' ) )->setValue( null, 0 );

        return $db;
    }

    /** Records each log call and its level, standing in for the driver's logger. */
    private function spy()
    {
        return new class {

            /** @var list<array{0: string, 1: string}> */
            public $messages = [];

            public function debug( $m )   { $this->messages[] = [ 'debug', $m ]; }
            public function info( $m )    { $this->messages[] = [ 'info', $m ]; }
            public function notice( $m )  { $this->messages[] = [ 'notice', $m ]; }
            public function warning( $m ) { $this->messages[] = [ 'warning', $m ]; }
            public function err( $m )     { $this->messages[] = [ 'error', $m ]; }
            public function crit( $m )    { $this->messages[] = [ 'critical', $m ]; }

            /** @return list<string> */
            public function levels() { return array_column( $this->messages, 0 ); }
        };
    }

    /** @dataProvider drivers */
    public function testABoundDuplicateIsLoggedAsTheExpectedRace(string $class): void
    {
        $db = $this->driver($class);
        $this->assertNotFalse($db->query('INSERT INTO bound_refusal_probe (id) VALUES (?)', [1]));

        $spy = $this->spy();
        $original = $db->e;
        $db->e = $spy;

        try {
            $result = $db->query('INSERT INTO bound_refusal_probe (id) VALUES (?)', [1]);
        } finally {
            $db->e = $original;
        }

        $this->assertFalse($result);
        $this->assertTrue($db->lastQueryWasConstraintViolation());
        $this->assertContains('notice', $spy->levels(), 'logged, so a climbing duplicate rate is visible');
        $this->assertNotContains('error', $spy->levels(), 'and not as a fault');
    }

    /** @dataProvider drivers */
    public function testABoundFaultIsLoggedAsOne(string $class): void
    {
        $db = $this->driver($class);

        $spy = $this->spy();
        $original = $db->e;
        $db->e = $spy;

        try {
            $result = $db->query('INSERT INTO bound_refusal_no_such_table (id) VALUES (?)', [1]);
        } finally {
            $db->e = $original;
        }

        $this->assertFalse($result);
        $this->assertFalse($db->lastQueryWasConstraintViolation());
        $this->assertContains('error', $spy->levels());
        $this->assertSame(1, count(array_filter($spy->levels(), fn ($l) => $l === 'error')), 'logged once, not twice');
    }
}
