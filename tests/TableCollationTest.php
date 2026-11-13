<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A utf8 table is declared utf8_general_ci, the collation every existing OWA
 * table has, rather than taking the server's default -- which MariaDB 11.5
 * changed to utf8mb3_uca1400_ai_ci. A table in another character set keeps the
 * server's default.
 *
 * No existing table is altered: only tables created from now on name it.
 */
final class TableCollationTest extends TestCase
{
    /** A MySQL driver without a connection: tableOptions() reads only the dialect's constants. */
    private static function driver(): \OWA\Core\Db\Mysql
    {
        return (new ReflectionClass(\OWA\Core\Db\Mysql::class))->newInstanceWithoutConstructor();
    }

    public function testAUtf8TableNamesGeneralCi(): void
    {
        $options = self::driver()->tableOptions();

        $this->assertMatchesRegularExpression(
            '/CHARACTER SET = utf8 COLLATE = utf8_general_ci( |$)/', $options);
    }

    public function testAnEntityTableIsDeclaredTheSameWay(): void
    {
        $options = self::driver()->tableOptions(['table_type' => 'disk', 'character_encoding' => 'utf8']);

        $this->assertStringContainsString('COLLATE = utf8_general_ci', $options);
    }

    public function testAnotherCharacterSetKeepsTheServerDefault(): void
    {
        $options = self::driver()->tableOptions(['character_encoding' => 'utf8mb4']);

        $this->assertStringContainsString('CHARACTER SET = utf8mb4', $options);
        $this->assertStringNotContainsString('COLLATE', $options);
    }

    public function testANamedCollationIsUsedAsGiven(): void
    {
        $options = self::driver()->tableOptions(['character_encoding' => 'utf8mb4', 'collation' => 'utf8mb4_bin']);

        $this->assertStringContainsString('CHARACTER SET = utf8mb4 COLLATE = utf8mb4_bin', $options);
    }

    public function testANewDatabaseIsCreatedWithTheSameCollation(): void
    {
        $db = new class extends \OWA\Core\Db\Mysql {
            public array $sql = [];
            public function __construct() {}
            function query($sql, array $params = array()) { $this->sql[] = $sql; return true; }
        };

        $this->assertTrue($db->createDatabase('owa_scratch'));
        $this->assertSame(['CREATE DATABASE `owa_scratch` CHARACTER SET utf8 COLLATE utf8_general_ci'], $db->sql);
    }

    /** What the server makes of it, on whichever MySQL or MariaDB the suite runs against. */
    public function testTheServerCreatesTheTableWithGeneralCi(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database');
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = 'owa_zz_collation_' . getmypid();

        $this->assertNotFalse($db->query(sprintf(
            'CREATE TABLE %s (v VARCHAR(10)) %s', $table, $db->tableOptions())));

        try {
            $row = $db->get_row(sprintf(
                "SELECT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'",
                $table));

            $this->assertMatchesRegularExpression('/^utf8(mb3)?_general_ci$/', (string) ($row['c'] ?? ''));
        } finally {
            $db->query(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
}
