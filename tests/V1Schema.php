<?php

/**
 * The frozen 1.14 v1 schema, loaded under a prefix of the test's own.
 *
 * Migration tests never read an installation's real v1 tables: the fixture
 * (tests/fixtures/v1_schema_1_14.sql) is created under a separate prefix,
 * filled with hand-built rows and dropped afterwards.
 */
final class V1Schema
{
    const PREFIX = 'owa_v1fx_';

    const FIXTURE = __DIR__ . '/fixtures/v1_schema_1_14.sql';

    /** @return string[] CREATE TABLE statements, prefixed */
    public static function statements(string $prefix = self::PREFIX): array
    {
        $sql = (string) file_get_contents(self::FIXTURE);
        $sql = preg_replace('/^--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim',
            explode(";\n", str_replace('{prefix}', $prefix, $sql)))));
    }

    public static function load(string $prefix = self::PREFIX): void
    {
        self::drop($prefix);

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (self::statements($prefix) as $statement) {
            $db->query($statement);
        }
    }

    public static function drop(string $prefix = self::PREFIX): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (\OWA\Module\Base\Classes\Migration\V1Tables::all() as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
        }
    }

    /** @return string[] column names, in table order */
    public static function columns(string $table, string $prefix = self::PREFIX): array
    {
        return array_map(fn ($r) => (string) ((array) $r)['Field'],
            (array) \OWA\Core\CoreAPI::dbSingleton()->get_results('SHOW COLUMNS FROM ' . $prefix . $table));
    }

    /**
     * Give the dimension foreign keys the VARCHAR type some installations
     * hold, which is the variant the migrator must also read correctly.
     */
    public static function asVarcharKeys(string $prefix = self::PREFIX): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['request', 'session'] as $table) {
            foreach (['referer_id', 'ua_id', 'host_id', 'os_id'] as $column) {
                $db->query(sprintf('ALTER TABLE %s%s MODIFY `%s` varchar(255) DEFAULT NULL', $prefix, $table, $column));
            }
        }
    }
}
