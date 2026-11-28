<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Db::get_unbuffered_iterator() on both drivers: rows streamed in order as
 * strings, a refused statement counted, and the connection usable again
 * after a caller stops early. cmd=backup reads every table through it, on a
 * connection of its own (CoreAPI::dbFactory(false)).
 */
final class DbUnbufferedIteratorTest extends TestCase
{
    private string $table = '';

    /** @var array{0: mixed}|null db_type as it was, once setUp() got that far */
    private ?array $saved_type = null;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database');
        }

        $this->saved_type = [\OWA\Core\CoreAPI::getSetting('base', 'db_type')];
        $this->table      = 'owa_zzunbuf_' . getmypid();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query(sprintf('CREATE TABLE %s (id BIGINT NOT NULL PRIMARY KEY, v VARCHAR(10) NULL)', $this->table));
        $db->query(sprintf("INSERT INTO %s VALUES (9223372036854775807, 'top'), (1, NULL), (2, 'two')", $this->table));
    }

    /** Runs after a skip in setUp() too: it restores only what setUp() saved. */
    protected function tearDown(): void
    {
        if ($this->saved_type === null) {
            return;
        }

        \OWA\Core\CoreAPI::setSetting('base', 'db_type', $this->saved_type[0]);
        \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . $this->table);
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function drivers(): array
    {
        return [
            'PDO'    => ['mysql', \OWA\Core\Db\PdoMysql::class],
            'mysqli' => ['mysqli', \OWA\Core\Db\Mysql::class],
        ];
    }

    /**
     * @dataProvider drivers
     *
     * @covers-setting base.db_type
     */
    public function testRowsStreamInOrderAsStrings(string $type, string $class): void
    {
        $db = $this->connection($type, $class);

        $rows = iterator_to_array($db->get_unbuffered_iterator(sprintf('SELECT id, v FROM %s ORDER BY id', $this->table)), false);

        $this->assertSame([
            ['id' => '1', 'v' => null],
            ['id' => '2', 'v' => 'two'],
            ['id' => '9223372036854775807', 'v' => 'top'],
        ], $rows);

        $db->close();
    }

    /** @dataProvider drivers */
    public function testARefusedStatementIsCounted(string $type, string $class): void
    {
        $db     = $this->connection($type, $class);
        $errors = $db->queryErrorCount();

        $this->assertSame([], iterator_to_array($db->get_unbuffered_iterator('SELECT nope FROM ' . $this->table)));
        $this->assertSame($errors + 1, $db->queryErrorCount());

        $db->close();
    }

    /** @dataProvider drivers */
    public function testTheConnectionIsUsableAfterAnEarlyStop(string $type, string $class): void
    {
        $db = $this->connection($type, $class);

        foreach ($db->get_unbuffered_iterator(sprintf('SELECT id FROM %s ORDER BY id', $this->table)) as $row) {
            break;
        }

        $this->assertSame('1', (string) $db->get_row('SELECT 1 AS one')['one']);

        $db->close();
    }

    private function connection(string $type, string $class): \OWA\Core\Db
    {
        \OWA\Core\CoreAPI::setSetting('base', 'db_type', $type);
        $db = \OWA\Core\CoreAPI::dbFactory(false);

        $this->assertInstanceOf($class, $db);
        $this->assertNotSame(\OWA\Core\CoreAPI::dbSingleton(), $db, 'a connection of its own');

        return $db;
    }
}
