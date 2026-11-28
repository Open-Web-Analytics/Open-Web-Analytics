<?php

use OWA\Module\Base\Classes\ConfigSecrets;
use OWA\Module\Base\Update\Update106;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update106: owa-config.php gains a generated OWA_SECRET where none is
 * defined, and down() takes out only the lines it added.
 *
 * Against a config file in a scratch directory and a scratch copy of the
 * settings table, so neither the install's owa-config.php nor its rows are
 * read or changed.
 */
final class Update106Test extends TestCase
{
    const TABLE = 'owa_setting_update106_test';

    const SOURCE = "<?php\n\ndefine('OWA_DB_TYPE', 'mysql');\ndefine('OWA_PUBLIC_URL', 'https://example.test/owa/');\n";

    const VALUE = 'update106-test-value-0123456789abcdef-0123456789abcdef-012345678';

    private Update106 $update;

    private string $dir = '';

    private string $path = '';

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/owa-update106-test-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/owa-config.php';
        mkdir($this->dir, 0700);

        $this->update = new Update106();
        $this->update->config_file    = $this->path;
        $this->update->secret_defined = false;
        $this->update->random         = static fn () => self::VALUE;

        if (!owa_test_db_available()) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE,
            \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName()));

        $this->update->tables = ['base.setting' => self::TABLE];
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }

        @chmod($this->dir, 0700);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('keeps its record in the settings table');
        }
    }

    private function config(string $source, int $mode = 0640): void
    {
        file_put_contents($this->path, $source);
        chmod($this->path, $mode);
    }

    /** @return array<string, mixed>|null the record's row */
    private function record(): ?array
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf("SELECT * FROM %s WHERE module = 'base' AND name = ?", self::TABLE),
            [Update106::BEFORE]);

        return $row ? (array) $row : null;
    }

    public function testTheSchemaNumberAndTheCommandLineOnly(): void
    {
        $this->assertSame(106, $this->update->schema_version);
        $this->assertTrue($this->update->isCliModeRequired(), 'it writes owa-config.php');
        $this->assertSame(106, \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);
        $this->assertSame(\OWA\Core\ServerRole::SECRET_SCHEMA_VERSION, $this->update->schema_version);
    }

    /** A defined OWA_SECRET: the file is not read or written, and no record is kept. */
    public function testADefinedSecretChangesNothing(): void
    {
        $this->config(self::SOURCE . "define('OWA_SECRET', 'one-of-its-own-0123456789abcdef0123');\n");
        $before = (string) file_get_contents($this->path);
        $this->update->secret_defined = true;

        $this->assertTrue($this->update->up());

        $this->assertSame($before, file_get_contents($this->path));

        if (owa_test_db_available()) {
            $this->assertNull($this->record());
            $this->assertTrue($this->update->down());
            $this->assertSame($before, file_get_contents($this->path), 'down() with no record leaves it');
        }
    }

    public function testAMissingSecretIsAddedAboveTheClosingTag(): void
    {
        $this->requireDb();
        $this->config(self::SOURCE . "?>\n", 0640);

        $this->assertTrue($this->update->up());

        $this->assertSame(self::SOURCE . ConfigSecrets::instanceSecretLines(self::VALUE) . "?>\n", file_get_contents($this->path));
        clearstatcache();
        $this->assertSame('0640', sprintf('%04o', fileperms($this->path) & 07777), 'the mode kept');
        $this->assertSame(['owa-config.php'], array_values(array_diff(scandir($this->dir), ['.', '..'])), 'no temp file left');

        $record = $this->record();
        $this->assertNotNull($record);
        $this->assertSame(['lines' => hash('sha256', ConfigSecrets::instanceSecretLines(self::VALUE))],
            unserialize((string) $record['value']));
        $this->assertStringNotContainsString(self::VALUE, (string) $record['value'], 'the value is not kept');
    }

    public function testDownTakesOutTheLinesItAdded(): void
    {
        $this->requireDb();
        $this->config(self::SOURCE);

        $this->assertTrue($this->update->up());
        $this->assertNotSame(self::SOURCE, file_get_contents($this->path));

        $this->assertTrue($this->update->down());

        $this->assertSame(self::SOURCE, file_get_contents($this->path));
        $this->assertNull($this->record());
        $this->assertTrue($this->update->down(), 'run again: nothing to do');
    }

    /** Changed by hand after the update: not what it added, so it stays. */
    public function testDownLeavesAValueChangedSince(): void
    {
        $this->requireDb();
        $this->config(self::SOURCE);
        $this->assertTrue($this->update->up());

        $edited = str_replace(self::VALUE, 'changed-by-hand-0123456789abcdef0123', (string) file_get_contents($this->path));
        file_put_contents($this->path, $edited);

        $this->assertTrue($this->update->down());

        $this->assertSame($edited, file_get_contents($this->path));
        $this->assertNull($this->record());
    }

    /** A file that cannot be replaced fails the update with the line to add, and no value. */
    public function testAnUnwritableFileFailsWithTheLineToAdd(): void
    {
        $this->requireDb();

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes to a read-only directory.');
        }

        $this->config(self::SOURCE);
        $error = new class {
            /** @var list<string> */
            public $logged = [];

            public function notice($message)
            {
                $this->logged[] = (string) $message;
            }

            public function __call($name, $args)
            {
                return null;
            }
        };
        $this->update->e = $error;
        chmod($this->dir, 0500);

        $ok = $this->update->up();

        chmod($this->dir, 0700);
        $this->assertFalse($ok);
        $this->assertSame(self::SOURCE, file_get_contents($this->path));
        $said = implode("\n", $error->logged);
        $this->assertStringContainsString("define('OWA_SECRET', 'RANDOM');", $said);
        $this->assertStringNotContainsString(self::VALUE, $said);
    }
}
