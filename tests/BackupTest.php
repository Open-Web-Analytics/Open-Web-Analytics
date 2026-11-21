<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\Backup;

/**
 * cmd=backup (Classes\Backup): which tables each scope dumps, that the file
 * loads back to the same rows, and where it may be written.
 *
 * Against fixture tables under a prefix of their own, so the installation's
 * tables are never dumped or dropped.
 */
final class BackupTest extends CliControllerTestCase
{
    /** Rows in the paged fixtures: more than one page, not a whole number of them. */
    private const ROWS = Backup::PAGE * 2 + 37;

    private string $prefix = '';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'owa_zzbk' . getmypid() . '_';
        $this->dir    = sys_get_temp_dir() . '/owa-backup-' . bin2hex(random_bytes(4));

        mkdir($this->dir, 0700);
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->dropFixtures();
        exec('rm -rf ' . escapeshellarg($this->dir));

        parent::tearDown();
    }

    /** Every entity's table is in a group on purpose: a new one fails here until it is placed. */
    public function testEveryEntityTableIsClassified(): void
    {
        $data = [];
        $application = [];

        foreach (glob(dirname(__DIR__) . '/modules/*/Entity/*.php') as $file) {
            $name = basename($file, '.php');

            // index.php guards the directory; Event is a cube, named per Property.
            if ($name === 'index' || $name === 'Event') {
                continue;
            }

            $class  = '\\OWA\\Module\\' . basename(dirname($file, 2)) . '\\Entity\\' . $name;
            $table  = substr((new $class())->getTableName(), strlen(\OWA\Core\Entity::TABLE_PREFIX));

            if (Backup::isDataTable($table)) {
                $data[] = $table;
            } else {
                $application[] = $table;
            }
        }

        sort($data);
        sort($application);

        $this->assertSame(['domstream_chunk', 'domstream_payload', 'event_raw', 'visitor_acquisition'], $data);
        $this->assertSame([
            'app_password', 'configuration', 'custom_dimension', 'custom_report', 'custom_report_favorite',
            'goal_event_definition', 'goal_event_definition_condition', 'job_lock', 'job_queue',
            'migration_day_visitor', 'migration_progress', 'migration_tally', 'notification',
            'notification_state', 'organization', 'property', 'scheduled_job', 'setting', 'site',
            'site_user', 'spam_block', 'user',
        ], $application, 'a new entity: add its table to Backup::DATA_TABLES if tracking writes it, then here');
    }

    public function testTheGroupsLeaveOutTheCubes(): void
    {
        $p = $this->prefix;

        $this->assertSame([
            'application' => [$p . 'nokey', $p . 'site'],
            'data'        => [$p . 'event_raw', $p . 'request'],
        ], $this->backup()->tables(), 'request is a 1.x tracking table; event_7 and its _rebuild are a cube');
    }

    /** Dumped, dropped and loaded back: the same rows, to the checksum. */
    public function testAllLoadsBackToTheSameRows(): void
    {
        $tables = array_merge(...array_values($this->backup()->tables()));
        $before = $this->checksums($tables);

        $done = $this->backup()->write('all', $this->dir);

        $this->assertSame(self::ROWS, $done['tables'][$this->prefix . 'event_raw']);
        $this->assertSame(self::ROWS, $done['tables'][$this->prefix . 'nokey'], 'paged by offset without a key');
        $this->assertMatchesRegularExpression('/\/owa-[0-9A-Za-z.-]+-\d{8}-\d{6}-all\.sql\.gz$/', $done['file']);
        $this->assertSame('0600', sprintf('%04o', fileperms($done['file']) & 0777));
        $this->assertSame([], glob($this->dir . '/*.part'), 'no partial file left');
        $this->assertTrue(Backup::isComplete($done['file']));

        $this->dropFixtures();
        $this->load($done['file']);

        $this->assertSame($before, $this->checksums($tables));
    }

    public function testEachScopeDumpsItsGroup(): void
    {
        $app  = gzdecode((string) file_get_contents($this->backup()->write('application', $this->dir)['file']));
        sleep(1);
        $data = gzdecode((string) file_get_contents($this->backup()->write('data', $this->dir)['file']));

        $this->assertStringContainsString('CREATE TABLE `' . $this->prefix . 'site`', $app);
        $this->assertStringNotContainsString('`' . $this->prefix . 'event_raw`', $app);
        $this->assertStringContainsString('CREATE TABLE `' . $this->prefix . 'event_raw`', $data);
        $this->assertStringNotContainsString('`' . $this->prefix . 'site`', $data);
        $this->assertStringNotContainsString('event_7', $app . $data, 'never a cube');
        $this->assertDoesNotMatchRegularExpression('/^DROP TABLE/m', $app . $data);
    }

    public function testATruncatedFileIsNotComplete(): void
    {
        $file = $this->backup()->write('application', $this->dir)['file'];
        $text = gzdecode((string) file_get_contents($file));

        file_put_contents($file, gzencode(substr($text, 0, -20)));
        $this->assertFalse(Backup::isComplete($file));

        file_put_contents($file, substr((string) gzencode($text), 0, -40));
        $this->assertFalse(Backup::isComplete($file), 'a cut gzip stream');
    }

    public function testTheDirectoryMustBeOutsideWhatIsServed(): void
    {
        $root = \OWA\Core\Env::root();

        $this->assertStringContainsString('No backup directory', (string) Backup::dirProblem('', []));
        $this->assertStringContainsString('does not exist', (string) Backup::dirProblem($this->dir . '/nope', []));
        $this->assertStringContainsString('web server can serve', (string) Backup::dirProblem($root . '/owa-data', Backup::servedDirs()));
        $this->assertStringContainsString('web server can serve', (string) Backup::dirProblem($root, Backup::servedDirs()));
        $this->assertStringContainsString('web server can serve', (string) Backup::dirProblem($this->dir, [dirname($this->dir)]),
            'a web root, as a web request has one');
        $this->assertNull(Backup::dirProblem($this->dir, Backup::servedDirs()));

        chmod($this->dir, 0500);
        $this->assertStringContainsString('not writable', (string) Backup::dirProblem($this->dir, []));
        $this->assertNull(Backup::dirProblem($this->dir, [], false), 'System Health does not ask');
        chmod($this->dir, 0700);
    }

    public function testTheCommandWritesTheFile(): void
    {
        $cli = $this->command(['scope' => 'application', 'dir' => $this->dir]);

        $this->assertSame('ok', $cli->getCliOutcome()['outcome'], $cli->getCliOutcome()['message']);
        $this->assertCount(1, glob($this->dir . '/owa-*-application.sql.gz'));
    }

    public function testTheCommandRefusesABadScopeOrDirectory(): void
    {
        $cli = $this->command(['scope' => 'everything', 'dir' => $this->dir]);
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_USAGE, \OWA\Core\Controller\Cli::$exit_code);

        \OWA\Core\Controller\Cli::$exit_code = 0;
        $cli = $this->command(['scope' => 'all', 'dir' => \OWA\Core\Env::root() . '/owa-data']);
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertStringContainsString('web server can serve', $cli->getCliOutcome()['message']);
        $this->assertSame([], glob(\OWA\Core\Env::root() . '/owa-data/owa-*.sql.gz*'));
    }

    /** It is how a backup is taken before cmd=update. */
    public function testItRunsWhileUpdatesArePending(): void
    {
        $this->assertFalse((new \OWA\Module\Base\Controller\BackupCli(['scope' => 'all']))->needsCurrentSchema());
    }

    private function backup(): Backup
    {
        return new Backup(\OWA\Core\CoreAPI::dbSingleton(), $this->prefix);
    }

    private function command(array $params): \OWA\Module\Base\Controller\BackupCli
    {
        $cli = new \OWA\Module\Base\Controller\BackupCli($params);
        $cli->prefix = $this->prefix;

        ob_start();
        try {
            $cli->action();
        } finally {
            ob_end_clean();
        }

        return $cli;
    }

    private function createFixtures(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $p  = $this->prefix;
        $mode = (string) $db->get_row('SELECT @@session.sql_mode AS m')['m'];

        $db->query("SET SESSION sql_mode = CONCAT(@@session.sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");

        try {
            $this->exec("CREATE TABLE {$p}site (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL,"
                . " settings BLOB NULL, n INT NOT NULL DEFAULT 0, twice INT AS (n * 2) VIRTUAL,"
                . " created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
            $this->exec("INSERT INTO {$p}site (id, name, settings, n, created) VALUES"
                . " (0, 'zero id', NULL, 1, '2026-01-02 03:04:05'),"
                . " (1, 'quote '' backslash \\\\ newline \\n tab \\t nul \\0 end', 0x00FF0A27225C, 2, '2026-06-01 00:00:00'),"
                . " (2, NULL, '', 3, '2026-06-01 00:00:00'),"
                . " (3, 'café 東京 🎉', 0x1F8B0800, 4, '2026-06-01 00:00:00')");

            $this->exec("CREATE TABLE {$p}event_raw (yyyymmdd INT NOT NULL, id BIGINT NOT NULL, v VARCHAR(20) NULL,"
                . " PRIMARY KEY (yyyymmdd, id)) PARTITION BY RANGE (yyyymmdd)"
                . " (PARTITION p1 VALUES LESS THAN (20260601), PARTITION pmax VALUES LESS THAN MAXVALUE)");
            $this->exec("CREATE TABLE {$p}nokey (a INT NULL, b VARCHAR(10) NULL)");

            $raw = [];
            $nokey = [];
            for ($i = 0; $i < self::ROWS; $i++) {
                // Two days, so paging crosses from one key prefix to the next.
                $raw[]   = sprintf("(%d, %d, %s)", $i % 2 ? 20260515 : 20260701, 1000 - $i, $i % 5 ? "'v$i'" : 'NULL');
                $nokey[] = sprintf("(%d, 'b%d')", $i % 7, $i);
            }
            $this->exec("INSERT INTO {$p}event_raw VALUES " . implode(',', $raw));
            $this->exec("INSERT INTO {$p}nokey VALUES " . implode(',', $nokey));

            $this->exec("CREATE TABLE {$p}request (id BIGINT PRIMARY KEY)");
            $this->exec("INSERT INTO {$p}request VALUES (1), (2)");
            $this->exec("CREATE TABLE {$p}event_7 (id BIGINT PRIMARY KEY)");
            $this->exec("CREATE TABLE {$p}event_7_rebuild (id BIGINT PRIMARY KEY)");
        } finally {
            $db->query(sprintf("SET SESSION sql_mode = '%s'", $db->prepare($mode)));
        }
    }

    private function dropFixtures(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['site', 'event_raw', 'nokey', 'request', 'event_7', 'event_7_rebuild'] as $t) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $this->prefix, $t));
        }
    }

    private function exec(string $sql): void
    {
        $this->assertNotFalse(\OWA\Core\CoreAPI::dbSingleton()->query($sql), $sql);
    }

    /** Applies a backup statement by statement, as `mysql` would, then puts the session back. */
    private function load(string $file): void
    {
        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $keep = (array) $db->get_row('SELECT @@session.sql_mode AS m, @@session.time_zone AS z, @@session.character_set_connection AS c');
        $sql  = (string) gzdecode((string) file_get_contents($file));

        try {
            foreach (preg_split('/;\n/', $sql) as $statement) {
                $statement = trim((string) preg_replace('/^--.*$/m', '', $statement));

                if ($statement !== '') {
                    $this->exec($statement);
                }
            }
        } finally {
            $db->query(sprintf("SET SESSION sql_mode = '%s'", $db->prepare((string) $keep['m'])));
            $db->query(sprintf("SET time_zone = '%s'", $db->prepare((string) $keep['z'])));
            $db->query('SET NAMES ' . $keep['c']);
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            $db->query('SET UNIQUE_CHECKS = 1');
        }
    }

    /** @return array<string, string> table => CHECKSUM TABLE */
    private function checksums(array $tables): array
    {
        $out = [];

        foreach ($tables as $t) {
            $row = array_change_key_case((array) \OWA\Core\CoreAPI::dbSingleton()->get_row('CHECKSUM TABLE ' . $t));
            $out[$t] = (string) $row['checksum'];
        }

        return $out;
    }
}
