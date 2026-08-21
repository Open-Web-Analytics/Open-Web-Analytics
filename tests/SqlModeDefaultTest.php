<?php

use PHPUnit\Framework\TestCase;

/**
 * Strict SQL mode is the default, and it is overridable.
 *
 * OWA sent `SET SESSION sql_mode=''` on every connection for years, disabling
 * strict mode and turning bad writes into wrong data instead of errors: values
 * too long for their column were truncated, and non-numeric values written to
 * integer columns became 0.
 *
 * That produced real damage. Rows were found on two live installs whose
 * yyyymmdd -- the fact-table partition key, and the column every date-range
 * report filters on -- had been coerced to 0, making them invisible to reporting
 * and putting them in the catch-all partition.
 *
 * This pins both halves of the fix, because each fails differently:
 *
 *   - if the default silently reverted to '', the coercions would come back and
 *     nothing else in the suite would notice;
 *   - if the override stopped working, an install that hits a genuine problem in
 *     third-party code would have no way out but a patch.
 */
final class SqlModeDefaultTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** Reads the protected dialect method the drivers consult on connect. */
    private function modeFor(string $driverClass)
    {
        $db = new $driverClass('h', '3306', 'n', 'u', 'p', true, false);

        $m = new \ReflectionMethod($db, 'sessionSqlMode');
        $m->setAccessible(true);

        return $m->invoke($db);
    }

    public static function driverProvider(): array
    {
        return [
            'mysqli' => [\OWA\Core\Db\Mysql::class],
            'pdo'    => [\OWA\Core\Db\PdoMysql::class],
        ];
    }

    /**
     * @dataProvider driverProvider
     */
    public function testStrictIsTheDefault(string $driverClass): void
    {
        // No OWA_DB_SQL_MODE constant and no env var in a normal test run.
        if (getenv('OWA_DB_SQL_MODE') !== false || defined('OWA_DB_SQL_MODE')) {
            $this->markTestSkipped('sql_mode is overridden in this environment.');
        }

        $mode = $this->modeFor($driverClass);

        $this->assertNotSame('', $mode,
            "the empty sql_mode disables strict mode -- that is the behaviour this release removed");
        $this->assertStringContainsString('STRICT', (string) $mode,
            'the default session sql_mode must be strict');
    }

    /**
     * Both drivers must agree. They share the dialect, but a future driver could
     * answer this itself, and a disagreement would mean the same install
     * coerced or refused depending on which extension happened to be present.
     */
    public function testBothDriversAgreeOnTheMode(): void
    {
        $this->assertSame(
            $this->modeFor(\OWA\Core\Db\Mysql::class),
            $this->modeFor(\OWA\Core\Db\PdoMysql::class),
            'the drivers disagree about sql_mode'
        );
    }

    /**
     * The way back, documented in UPGRADING.md. Exercised through the env
     * channel because a constant cannot be undefined once set.
     */
    public function testTheModeCanBeOverridden(): void
    {
        if (defined('OWA_DB_SQL_MODE')) {
            $this->markTestSkipped('OWA_DB_SQL_MODE is already defined.');
        }

        $original = getenv('OWA_DB_SQL_MODE');

        try {
            putenv('OWA_DB_SQL_MODE=');
            $this->assertSame('', $this->modeFor(\OWA\Core\Db\PdoMysql::class),
                'an install must be able to restore the permissive behaviour');

            putenv('OWA_DB_SQL_MODE=STRICT_TRANS_TABLES');
            $this->assertSame('STRICT_TRANS_TABLES', $this->modeFor(\OWA\Core\Db\PdoMysql::class));

        } finally {
            if ($original === false) {
                putenv('OWA_DB_SQL_MODE');
            } else {
                putenv('OWA_DB_SQL_MODE=' . $original);
            }
        }
    }
}
