<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * getAffectedRows() reports the statement that just ran, on both drivers.
 *
 * The mysqli driver keeps a bound statement's count until someone reads it,
 * because the statement handle is closed straight away. Nothing cleared it
 * when the next statement ran, so an unbound DELETE after a bound INSERT
 * nobody asked about answered the INSERT's 1 -- which is how the visitor-store
 * sweep undercounted a batch in CI's mysqli job and not in the PDO ones.
 *
 * Each driver is built here from the installation's own credentials, so both
 * are exercised whichever one this environment is configured with.
 */
final class DbAffectedRowsTest extends TestCase
{
    /** @return array driver class => [class] */
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

        $db->query('CREATE TEMPORARY TABLE affected_probe (id INT PRIMARY KEY)');

        return $db;
    }

    /** @dataProvider drivers */
    public function testAnUnboundWriteAfterAnUnreadBoundOneReportsItsOwnCount(string $class): void
    {
        $db = $this->driver($class);

        // Bound, and its count deliberately never read.
        $this->assertNotFalse($db->query('INSERT INTO affected_probe (id) VALUES (?)', [1]));

        $this->assertNotFalse($db->query('INSERT INTO affected_probe (id) VALUES (2), (3), (4)'));
        $this->assertSame(3, (int) $db->getAffectedRows(), 'the unbound INSERT, not the bound one before it');

        $this->assertNotFalse($db->query('DELETE FROM affected_probe WHERE id IN (2, 3)'));
        $this->assertSame(2, (int) $db->getAffectedRows());
    }

    /** @dataProvider drivers */
    public function testABoundWriteReportsItsOwnCount(string $class): void
    {
        $db = $this->driver($class);

        $this->assertNotFalse($db->query('INSERT INTO affected_probe (id) VALUES (1), (2), (3)'));
        $this->assertNotFalse($db->query('DELETE FROM affected_probe WHERE id > ?', [1]));
        $this->assertSame(2, (int) $db->getAffectedRows());
    }
}
