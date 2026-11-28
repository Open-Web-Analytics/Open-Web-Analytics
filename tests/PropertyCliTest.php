<?php

require_once __DIR__ . '/CliControllerTestCase.php';
require_once __DIR__ . '/CapturesLog.php';
require_once __DIR__ . '/PropertyFixtures.php';

use OWA\Core\Controller\Cli;
use OWA\Module\Base\Classes\TrackerBundle;
use OWA\Module\Base\Controller\PropertyCli;

/**
 * cmd=property: --status prints a Property, --archive archives it as the
 * Property screen's delete does, --restore brings it back with the Profiles
 * archived with it. id= or domain= names it, compared by host; a domain
 * several Properties have names none.
 */
final class PropertyCliTest extends CliControllerTestCase
{
    use CapturesLog;

    const PROPERTY = 7710300000000001;
    const OTHER    = 7710300000000002;
    const APP      = 7710300000000003;

    /** A Profile archived on its own, before its Property. */
    const EARLIER = 1700000000;

    private string $host = '';

    /** @var array{0: string, 1: string} the Property's live Profile and the one archived earlier, by site_id */
    private array $sites = [];

    /** TrackerBundle's $outDir, $distDir and $buildDir before the test. */
    private array $bundleDirsWas = [];

    /** The test's tracker build (dist/) and the directory bundles are written to (tracker/). */
    private string $root = '';
    private string $out = '';

    /** Whether the database answers. The usage checks run without it. */
    private bool $db = false;

    protected function setUp(): void
    {
        try {
            $row      = \OWA\Core\CoreAPI::dbSingleton()->get_row('SELECT 1 AS ok');
            $this->db = is_array($row) && ($row['ok'] ?? null) == 1;
        } catch (\Throwable $e) {
            $this->db = false;
        }

        if (!$this->db) {
            Cli::$exit_code = 0;
            $this->authenticateAs('admin');

            return;
        }

        parent::setUp();

        /*
         * A tracker build of the test's own, so a restore can publish bundles
         * where no tracker has been built, and bundles go to a directory of
         * the test's own, not the install's public/tracker/.
         */
        $this->bundleDirsWas = [TrackerBundle::$outDir, TrackerBundle::$distDir, TrackerBundle::$buildDir];
        $this->root = sys_get_temp_dir() . '/owa-propcli-' . bin2hex(random_bytes(4)) . '/';
        $this->out  = $this->root . 'tracker/';
        $dist       = $this->root . 'dist/';
        mkdir($dist, 0700, true);
        mkdir($this->out, 0700, true);

        file_put_contents($dist . 'owa.tracker.js', '/*core*/');
        file_put_contents($dist . 'owa.domstream.js', '/*chunk*/');
        file_put_contents($dist . 'owa.tracker.manifest.json', json_encode([
            'core'    => ['file' => 'owa.tracker.js', 'sha256' => hash('sha256', '/*core*/')],
            'plugins' => ['domstream' => ['file' => 'owa.domstream.js', 'sha256' => hash('sha256', '/*chunk*/')]],
        ]));

        TrackerBundle::$outDir   = $this->out;
        TrackerBundle::$distDir  = $dist;
        TrackerBundle::$buildDir = $dist;   // the manifest is beside the files

        $this->host  = 'propcli-' . $this->tok . '.example.test';
        $this->sites = ['pcli' . $this->tok . 'a', 'pcli' . $this->tok . 'b'];

        $this->removeFixtures();

        $this->property(self::PROPERTY, $this->host);
        $this->profile(self::PROPERTY * 10, $this->sites[0], self::PROPERTY, 0);
        $this->profile(self::PROPERTY * 10 + 1, $this->sites[1], self::PROPERTY, self::EARLIER);
    }

    protected function tearDown(): void
    {
        // Set only once the database answered.
        if ($this->host !== '') {
            $this->removeFixtures();

            [TrackerBundle::$outDir, TrackerBundle::$distDir, TrackerBundle::$buildDir] = $this->bundleDirsWas;
            exec('rm -rf ' . escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    private function requireDb(): void
    {
        if (!$this->db) {
            $this->markTestSkipped('OWA database not reachable.');
        }
    }

    private function removeFixtures(): void
    {
        foreach ([self::PROPERTY, self::OTHER, self::APP] as $id) {
            owa_test_remove_property($id);
        }
    }

    private function property(int $id, string $domain, string $type = 'web'): void
    {
        $property = \OWA\Core\CoreAPI::entityFactory('base.property');
        $property->setProperties(['id' => $id, 'name' => 'Property CLI ' . $id, 'domain' => $domain,
            'property_type' => $type, 'creation_date' => time()]);

        $this->assertTrue((bool) $property->create(), "creating Property $id failed");
    }

    private function profile(int $id, string $site_id, int $property_id, int $archived): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->setProperties(['id' => $id, 'site_id' => $site_id, 'property_id' => $property_id,
            'name' => 'Profile ' . $site_id, 'domain' => $this->host, 'archived_date' => $archived]);

        $this->assertTrue((bool) $site->create(), "creating Profile $site_id failed");
    }

    /** @return int|string archived_date as stored, past the entity cache */
    private function archivedDate(string $entity, $id)
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf('SELECT archived_date FROM %s WHERE id = ?',
            \OWA\Core\CoreAPI::entityFactory($entity)->getTableName()), [(string) $id]);

        return (int) ((array) $row)['archived_date'];
    }

    /**
     * Run the command as cli.php does: what it printed, and what it logged.
     *
     * @return array{0: PropertyCli, 1: string, 2: string}
     */
    private function run_(array $params): array
    {
        Cli::$exit_code = 0;

        $cli = new class($params) extends PropertyCli {
            public array $lines = [];
            protected function write($lines) { foreach ((array) $lines as $l) { $this->lines[] = (string) $l; } }
        };

        $log = $this->logDuring(fn () => $cli->doAction());

        return [$cli, implode("\n", $cli->lines), $log];
    }

    public function testItRunsWhileUpdatesArePending(): void
    {
        $this->assertFalse((new PropertyCli([]))->needsCurrentSchema(), 'Update103 refuses the duplicates this archives');
    }

    /** Each argument as cli.php reads and documents it. */
    public function testTheArgumentsItTakes(): void
    {
        $arguments = PropertyCli::usage()['arguments'];

        $this->assertSame(['id', 'domain', '--status', '--archive', '--restore'], array_keys($arguments));
        $this->assertSame('property', $arguments['id']['type']);
        $this->assertSame('string', $arguments['domain']['type']);

        foreach (['--status', '--archive', '--restore'] as $flag) {
            $this->assertSame('flag', $arguments[$flag]['type'], $flag);
        }

        $commands = require dirname(__DIR__) . '/modules/Base/config/cli_commands.php';
        $actions  = require dirname(__DIR__) . '/modules/Base/config/actions.php';

        $this->assertSame('base.propertyCli', $commands['property'] ?? null);
        $this->assertSame(PropertyCli::class, $actions['base.propertyCli'] ?? null);
    }

    public function testNeitherIdNorDomainIsAUsageError(): void
    {
        [, , $log] = $this->run_(['status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('cmd=property: Give id=<property id> or domain=<domain>. Nothing was done.', $log);
    }

    public function testIdAndDomainTogetherAreAUsageError(): void
    {
        $this->requireDb();

        [, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'domain' => $this->host, 'status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('Give id= or domain=, not both.', $log);
        $this->assertSame(0, $this->archivedDate('base.property', self::PROPERTY), 'nothing was done');
    }

    public function testNoActionIsAUsageError(): void
    {
        [, , $log] = $this->run_(['domain' => 'propcli.example.test']);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('cmd=property: Give one of --status, --archive or --restore. Nothing was done.', $log);
    }

    public function testTwoActionsAreAUsageError(): void
    {
        [, , $log] = $this->run_(['domain' => 'propcli.example.test', 'archive' => true, 'restore' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('Give one of --status, --archive or --restore.', $log);
    }

    public function testTwoActionsArchiveNothing(): void
    {
        $this->requireDb();

        $this->run_(['id' => (string) self::PROPERTY, 'archive' => true, 'restore' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame(0, $this->archivedDate('base.property', self::PROPERTY), 'nothing was archived');
    }

    public function testAnIdOfNoPropertyIsAUsageError(): void
    {
        $this->requireDb();

        [$cli] = $this->run_(['id' => '7710300000000099', 'status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
    }

    public function testADomainOfNoPropertyIsAUsageError(): void
    {
        $this->requireDb();

        [, , $log] = $this->run_(['domain' => 'nothing-' . $this->host, 'status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('There is no Property with the domain nothing-' . $this->host . '.', $log);
    }

    public function testStatusPrintsThePropertyItsProfilesAndItsCube(): void
    {
        $this->requireDb();

        $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY);
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        $this->assertNotFalse($db->query(sprintf('CREATE TABLE %s (id BIGINT PRIMARY KEY, yyyymmdd INT)', $cube)));
        $this->assertNotFalse($db->query(sprintf('INSERT INTO %s (id, yyyymmdd) VALUES (1, 20261101), (2, 20261127)', $cube)));

        [$cli, $out] = $this->run_(['id' => (string) self::PROPERTY, 'status' => true]);

        $this->assertSame(0, Cli::$exit_code, $cli->getCliOutcome()['message']);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ': Property CLI ' . self::PROPERTY, $out);
        $this->assertStringContainsString('Domain: ' . $this->host, $out);
        $this->assertStringContainsString('State: live', $out);
        $this->assertStringContainsString('Profiles: 2', $out);
        $this->assertMatchesRegularExpression('/' . $this->sites[0] . '  Profile ' . $this->sites[0] . '  web  live$/m', $out);
        $this->assertStringContainsString($this->sites[1] . '  Profile ' . $this->sites[1] . '  web  archived '
            . date('Y-m-d H:i', self::EARLIER), $out);
        $this->assertStringContainsString('Cube: ' . $cube . ', 2 rows, newest day ', $out);
        $this->assertSame(0, $this->archivedDate('base.property', self::PROPERTY), '--status changes nothing');
    }

    public function testStatusOfAPropertyWithNoCube(): void
    {
        $this->requireDb();

        [, $out] = $this->run_(['id' => (string) self::PROPERTY, 'status' => true]);

        $this->assertStringContainsString('Cube: none yet', $out);
    }

    public function testAnAppPropertyIsReachedById(): void
    {
        $this->requireDb();

        $this->property(self::APP, '', 'app');

        [, $out] = $this->run_(['id' => (string) self::APP, 'status' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Domain: none, an app', $out);
        $this->assertStringContainsString('Profiles: 0', $out);
    }

    public function testTheDomainIsComparedAsAHost(): void
    {
        $this->requireDb();

        [, $out] = $this->run_(['domain' => 'HTTPS://WWW.' . strtoupper($this->host) . ':8080/', 'status' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ':', $out);
    }

    public function testArchiveArchivesThePropertyAndItsLiveProfiles(): void
    {
        $this->requireDb();

        file_put_contents($this->out . $this->sites[0] . '.js', '// bundle');

        [, , $log] = $this->run_(['domain' => $this->host, 'archive' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertFileDoesNotExist($this->out . $this->sites[0] . '.js', 'the archived Profile\'s bundle is still served');
        $this->assertStringContainsString('Property ' . self::PROPERTY . ' archived.', $log);
        $this->assertStringContainsString('Profiles archived, tracking bundles removed: ' . $this->sites[0] . '.', $log);

        $at = $this->archivedDate('base.property', self::PROPERTY);

        $this->assertNotSame(0, $at);
        $this->assertSame($at, $this->archivedDate('base.site', self::PROPERTY * 10), 'the live Profile takes its Property\'s date');
        $this->assertSame(self::EARLIER, $this->archivedDate('base.site', self::PROPERTY * 10 + 1), 'one archived before keeps its own');

        [, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'archive' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ' was archived already, on ' . date('Y-m-d H:i', $at) . '. Nothing changed.', $log);
        $this->assertSame($at, $this->archivedDate('base.property', self::PROPERTY));
    }

    public function testRestoreBringsBackOnlyTheProfilesArchivedWithIt(): void
    {
        $this->requireDb();

        $this->run_(['id' => (string) self::PROPERTY, 'archive' => true]);

        [, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'restore' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ' restored.', $log);
        $this->assertStringContainsString('Profiles restored, tracking bundles published: ' . $this->sites[0] . '.', $log);
        $this->assertFileExists($this->out . $this->sites[0] . '.js', 'the restored Profile\'s bundle is served again');
        $this->assertFileDoesNotExist($this->out . $this->sites[1] . '.js', 'the Profile still archived has none');
        $this->assertSame(0, $this->archivedDate('base.property', self::PROPERTY));
        $this->assertSame(0, $this->archivedDate('base.site', self::PROPERTY * 10));
        $this->assertSame(self::EARLIER, $this->archivedDate('base.site', self::PROPERTY * 10 + 1),
            'a Profile archived on its own stays archived');

        [, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'restore' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ' is live. Nothing changed.', $log);
    }

    public function testRestoreIsRefusedWhileALivePropertyHasTheDomain(): void
    {
        $this->requireDb();

        $this->run_(['id' => (string) self::PROPERTY, 'archive' => true]);
        $this->property(self::OTHER, 'www.' . $this->host);

        [$cli, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'restore' => true]);

        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ' was not restored.', $log);
        $this->assertStringContainsString('(id ' . self::OTHER . ')', $log);
        $this->assertNotSame(0, $this->archivedDate('base.property', self::PROPERTY), 'it stays archived');
    }

    /** Every Property with the host, live or archived, is shown, and none is acted on. */
    public function testADomainSeveralPropertiesHaveChangesNothing(): void
    {
        $this->requireDb();

        $this->run_(['id' => (string) self::PROPERTY, 'archive' => true]);
        $this->property(self::OTHER, $this->host);

        foreach (['status', 'archive', 'restore'] as $action) {
            [, $out, $log] = $this->run_(['domain' => $this->host, $action => true]);

            $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code, $action);
            $this->assertStringContainsString('2 Properties have the domain ' . $this->host . ':', $out, $action);
            $this->assertStringContainsString('Property ' . self::PROPERTY . ':', $out, $action);
            $this->assertStringContainsString('Property ' . self::OTHER . ':', $out, $action);
            $this->assertStringContainsString('Run the command again with id=<property id>.', $log, $action);
        }

        $this->assertNotSame(0, $this->archivedDate('base.property', self::PROPERTY));
        $this->assertSame(0, $this->archivedDate('base.property', self::OTHER));
    }
}
