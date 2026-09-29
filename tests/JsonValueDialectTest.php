<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The typed JSON_VALUE forms give the same answers on MySQL and MariaDB.
 *
 * They do not use RETURNING, which MariaDB's JSON_VALUE lacks: each reads the
 * value as text, trimmed, and converts it only when it is a number of that type
 * (Core\Db\MysqlDialect). This runs them against the server the suite is
 * connected to, so CI's MySQL and MariaDB jobs assert the same table.
 *
 * NULL for anything that will not convert, never an error: the cube build runs
 * these inside INSERT ... SELECT under STRICT_ALL_TABLES, where one bad value
 * raising an error would fail the whole partition.
 */
final class JsonValueDialectTest extends TestCase
{
    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This asks the database server.');
        }
    }

    /** @return array stored JSON => [signed, unsigned, double] */
    public static function values(): array
    {
        return [
            'an integer'                  => ['42', 42, 42, 42.0],
            'an integer as a string'      => ['"42"', 42, 42, 42.0],
            'a negative integer'          => ['-7', -7, null, -7.0],
            'a decimal'                   => ['3.5', null, null, 3.5],
            'a decimal as a string'       => ['"3.5"', null, null, 3.5],
            'an exponent'                 => ['1e3', null, null, 1000.0],
            'leading zeros'               => ['"007"', 7, 7, 7.0],
            'surrounding spaces'          => ['" 42 "', 42, 42, 42.0],
            'true'                        => ['true', 1, 1, 1.0],
            'false'                       => ['false', 0, 0, 0.0],
            'JSON null'                   => ['null', null, null, null],
            'text'                        => ['"abc"', null, null, null],
            'an empty string'             => ['""', null, null, null],
            'too long for a BIGINT'       => ['99999999999999999999', null, null, 1.0e20],
            'an object'                   => ['{"x": 1}', null, null, null],
            'an array'                    => ['[1]', null, null, null],
        ];
    }

    private function read(string $constant, string $json)
    {
        $db  = owa_coreAPI::dbSingleton();
        $doc = "'" . $db->prepare('{"a": ' . $json . '}') . "'";

        $row = $db->get_row('SELECT ' . sprintf(constant($constant), $doc, '$.a') . ' AS v');

        $this->assertIsArray($row, "$constant raised an error: " . $db->lastQueryError());

        return $row['v'];
    }

    /** @dataProvider values */
    public function testEachTypedFormReadsTheValue(string $json, $signed, $unsigned, $double): void
    {
        $got = $this->read('OWA_SQL_JSON_VALUE_SIGNED', $json);
        $this->assertSame($signed, $got === null ? null : (int) $got, 'SIGNED');

        $got = $this->read('OWA_SQL_JSON_VALUE_UNSIGNED', $json);
        $this->assertSame($unsigned, $got === null ? null : (int) $got, 'UNSIGNED');

        $got = $this->read('OWA_SQL_JSON_VALUE_DOUBLE', $json);
        $this->assertSame($double, $got === null ? null : (float) $got, 'DOUBLE');
    }

    public function testAMissingKeyIsNull(): void
    {
        $db  = owa_coreAPI::dbSingleton();
        $row = $db->get_row('SELECT ' . sprintf(OWA_SQL_JSON_VALUE_SIGNED, "'{\"b\": 1}'", '$.a') . ' AS v');

        $this->assertNull($row['v']);
    }

    /** No form may use RETURNING, which MariaDB's JSON_VALUE does not have. */
    public function testNoFormUsesReturning(): void
    {
        foreach (['OWA_SQL_JSON_VALUE', 'OWA_SQL_JSON_VALUE_SIGNED',
                  'OWA_SQL_JSON_VALUE_UNSIGNED', 'OWA_SQL_JSON_VALUE_DOUBLE'] as $constant) {
            $this->assertStringNotContainsString('RETURNING', constant($constant), $constant);
        }
    }
}
