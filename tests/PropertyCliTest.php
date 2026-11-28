<?php

require_once __DIR__ . '/CliControllerTestCase.php';
require_once __DIR__ . '/CapturesLog.php';
require_once __DIR__ . '/PropertyFixtures.php';

use OWA\Core\Controller\Cli;
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

    /** TrackerBundle::$outDir before the test, and the directory it writes to during it. */
    private ?string $outWas = null;
    private string $out = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Bundles go to a directory of the test's own, not the install's public/tracker/.
        $this->outWas = \OWA\Module\Base\Classes\TrackerBundle::$outDir;
        $this->out    = sys_get_temp_dir() . '/owa-propcli-bundles-' . getmypid() . '/';
        @mkdir($this->out, 0700, true);
        \OWA\Module\Base\Classes\TrackerBundle::$outDir = $this->out;

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

            \OWA\Module\Base\Classes\TrackerBundle::$outDir = $this->outWas;
            exec('rm -rf ' . escapeshellarg($this->out));
        }

        parent::tearDown();
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
        $this->assertFalse((new PropertyCli([]))->needsCurrentSchema(), 'Update102 refuses duplicates this archives');
    }

    public function testIdOrDomainIsGivenOnce(): void
    {
        [$cli, , $log] = $this->run_(['status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('cmd=property: Give id=<property id> or domain=<domain>. Nothing was done.', $log);

        [, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'domain' => $this->host, 'status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('Give id= or domain=, not both.', $log);
    }

    public function testOneActionIsGiven(): void
    {
        [, , $log] = $this->run_(['id' => (string) self::PROPERTY]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('Give one of --status, --archive or --restore.', $log);

        $this->run_(['id' => (string) self::PROPERTY, 'archive' => true, 'restore' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame(0, $this->archivedDate('base.property', self::PROPERTY), 'nothing was archived');
    }

    public function testAnIdOfNoPropertyIsAUsageError(): void
    {
        [$cli] = $this->run_(['id' => '7710300000000099', 'status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
    }

    public function testADomainOfNoPropertyIsAUsageError(): void
    {
        [, , $log] = $this->run_(['domain' => 'nothing-' . $this->host, 'status' => true]);

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertStringContainsString('There is no Property with the domain nothing-' . $this->host . '.', $log);
    }

    public function testStatusPrintsThePropertyItsProfilesAndItsCube(): void
    {
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
        [, $out] = $this->run_(['id' => (string) self::PROPERTY, 'status' => true]);

        $this->assertStringContainsString('Cube: none yet', $out);
    }

    public function testAnAppPropertyIsReachedById(): void
    {
        $this->property(self::APP, '', 'app');

        [, $out] = $this->run_(['id' => (string) self::APP, 'status' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Domain: none, an app', $out);
        $this->assertStringContainsString('Profiles: 0', $out);
    }

    public function testTheDomainIsComparedAsAHost(): void
    {
        [, $out] = $this->run_(['domain' => 'HTTPS://WWW.' . strtoupper($this->host) . ':8080/', 'status' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ':', $out);
    }

    public function testArchiveArchivesThePropertyAndItsLiveProfiles(): void
    {
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
        $this->run_(['id' => (string) self::PROPERTY, 'archive' => true]);

        [, , $log] = $this->run_(['id' => (string) self::PROPERTY, 'restore' => true]);

        $this->assertSame(0, Cli::$exit_code);
        $this->assertStringContainsString('Property ' . self::PROPERTY . ' restored.', $log);
        $this->assertStringContainsString('Profiles restored, tracking bundles published: ' . $this->sites[0] . '.', $log);
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
