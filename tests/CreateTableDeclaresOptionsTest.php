<?php

use PHPUnit\Framework\TestCase;

/**
 * Every CREATE TABLE names its engine, character set and row format through
 * Db::tableOptions(). A table that names none takes the database's defaults,
 * and on an existing install that can be latin1: the cube's computed table did,
 * and a search term outside Latin-1 then failed the whole build.
 *
 * Reads the source: a CREATE TABLE in a string literal (comments excluded)
 * puts its file on the list, and the file must then call tableOptions().
 */
class CreateTableDeclaresOptionsTest extends TestCase
{
    /**
     * Where a CREATE TABLE literal is not a table being declared.
     * MysqlDialect: OWA_SQL_CREATE_TABLE's last %s is createTable()'s options.
     * Db: createTableLike() rewrites a SHOW CREATE TABLE of an existing table.
     */
    const EXEMPT = ['Core/Db/MysqlDialect.php', 'Core/Db.php'];

    /** @return array relative path => true, for each file holding a CREATE TABLE literal */
    private function filesCreatingTables(): array
    {
        $root  = dirname(__DIR__) . '/';
        $found = [];

        foreach (['Core', 'modules'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = substr($file->getPathname(), strlen($root));

                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $t) {
                    if (is_array($t)
                        && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                        // Upper case and followed by a table: SQL, not a
                        // 'Create table %s failed' notice.
                        && preg_match('/\bCREATE\s+(TEMPORARY\s+)?TABLE\s+(IF NOT EXISTS\s+)?[`%\w]/', $t[1])
                        && !preg_match('/\bSHOW\s+CREATE\b|\bLIKE\b/', $t[1])) {
                        $found[$path] = true;
                        break;
                    }
                }
            }
        }

        ksort($found);

        return $found;
    }

    public function testEveryCreateTableDeclaresItsOptions(): void
    {
        $root = dirname(__DIR__) . '/';
        $bare = [];

        foreach (array_keys($this->filesCreatingTables()) as $path) {
            if (in_array($path, self::EXEMPT, true)) {
                continue;
            }

            if (strpos((string) file_get_contents($root . $path), 'tableOptions(') === false) {
                $bare[] = $path;
            }
        }

        $this->assertSame([], $bare, 'CREATE TABLE without Db::tableOptions(): the table takes the database default charset');
    }

    /** The scan finds the two callers it was written for; if it found none it would pass vacuously. */
    public function testTheScanSeesTheKnownCreators(): void
    {
        $found = $this->filesCreatingTables();

        $this->assertArrayHasKey('modules/Base/Classes/Cube/Builder.php', $found);
        $this->assertArrayHasKey('modules/Base/Update/Update068.php', $found);
    }

    public function testTheOptionsNameEngineCharsetAndRowFormat(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        $db = owa_test_db_available() ? \OWA\Core\CoreAPI::dbSingleton() : null;

        if (!$db) {
            $this->markTestSkipped('needs the driver for its constants');
        }

        $options = $db->tableOptions();

        $this->assertMatchesRegularExpression('/ENGINE\s*=\s*InnoDB/i', $options);
        $this->assertStringContainsString('CHARACTER SET = ' . OWA_DTD_CHARACTER_ENCODING_UTF8, $options);
        $this->assertMatchesRegularExpression('/ROW_FORMAT\s*=\s*DYNAMIC/i', $options);
    }
}
