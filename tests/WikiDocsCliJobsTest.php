<?php

require_once __DIR__ . '/CliControllerTestCase.php';

/**
 * The wiki's generated CLI and scheduled-jobs sections
 * (tests/tools/wiki/sections/cli.php, jobs.php), and the usage() declaration
 * the CLI section is generated from.
 *
 * Needs no database: everything here reads registrations and source.
 */
final class WikiDocsCliJobsTest extends CliControllerTestCase
{
    private const WIKI = __DIR__ . '/tools/wiki';

    protected function setUp(): void
    {
        require_once self::WIKI . '/lib/cli.php';
        require_once self::WIKI . '/lib/jobs.php';
    }

    protected function tearDown(): void
    {
    }

    private function section(string $name): string
    {
        $fn = require self::WIKI . "/sections/$name.php";

        return $fn(null);
    }

    /** @return array<string,string> command => class, from every shipped module */
    private function registeredCommands(): array
    {
        $s        = \OWA\Core\CoreAPI::serviceSingleton();
        $commands = [];

        foreach (owa_wiki_all_modules() as $module) {
            foreach ((array) $module->cli_commands as $command => $action) {
                $commands[$command] = $s->getMapValue('actions', $action)['class_name'];
            }
        }

        return $commands;
    }

    public function testEveryRegisteredCommandHasASection(): void
    {
        $md       = $this->section('cli');
        $commands = $this->registeredCommands();

        // Base's own map, read the way cli.php reads it, so a command can never be
        // missing from the page because owa_wiki_all_modules() missed its module.
        \OWA\Core\CoreAPI::serviceSingleton()->loadCliCommands();
        $active = array_keys((array) \OWA\Core\CoreAPI::serviceSingleton()->getMap('cli_commands'));

        $this->assertContains('cube-rebuild', $active);
        $this->assertArrayHasKey('update-geoip-db', $commands, 'a command of a module a configless boot leaves inactive');

        foreach (array_unique(array_merge($active, array_keys($commands))) as $command) {
            $this->assertStringContainsString("\n### $command\n", $md, "cmd=$command has no section");
            $this->assertStringContainsString("[`$command`](#$command)", $md, "cmd=$command is not in the table");
        }
    }

    public function testEveryCommandDeclaresItsOwnUsage(): void
    {
        foreach ($this->registeredCommands() as $command => $class) {

            $this->assertTrue(method_exists($class, 'usage'), "$class (cmd=$command) has no usage()");

            $declared = (new ReflectionMethod($class, 'usage'))->getDeclaringClass()->getName();

            $this->assertSame($class, $declared, "cmd=$command inherits usage() from $declared, so the page would describe that instead");

            $usage = $class::usage();

            $this->assertGreaterThan(10, strlen((string) ($usage['description'] ?? '')), "cmd=$command has no description");
            $this->assertIsArray($usage['arguments'] ?? null, "cmd=$command declares no arguments array");
        }
    }

    /**
     * Every documented argument is a name the command's own code reads. Catches
     * an argument renamed in the code and left behind in usage().
     */
    public function testEveryDocumentedArgumentIsReadByTheCommand(): void
    {
        // Read by the update that needs them, not by the controller.
        $elsewhere = [
            'update' => ['since' => 'modules/Base/Update/Update062.php', 'all' => 'modules/Base/Update/Update062.php'],
        ];

        foreach ($this->registeredCommands() as $command => $class) {

            foreach (array_keys($class::usage()['arguments']) as $arg) {

                $name = ltrim(explode('=', $arg, 2)[0], '-');
                $src  = isset($elsewhere[$command][$name])
                    ? file_get_contents(dirname(__DIR__) . '/' . $elsewhere[$command][$name])
                    : $this->sourceOf($class);

                $this->assertMatchesRegularExpression('/[\'"]' . preg_quote($name, '/') . '[\'"]/', $src,
                    "cmd=$command documents $arg, but nothing in its code reads '$name'");
            }
        }
    }

    public function testACommandWithNoArgumentsSaysSo(): void
    {
        $this->assertSame([], \OWA\Module\Base\Controller\FlushCacheCli::usage()['arguments']);
        $this->assertMatchesRegularExpression('/### flush-cache\n\n.+\n\n```bash\nphp cli.php cmd=flush-cache\n```\n\nTakes no arguments\./', $this->section('cli'));
    }

    public function testCapabilityIsReadUpTheClassChain(): void
    {
        // Declared on CustomDimensionsCli, the parent.
        $this->assertSame('edit_modules', owa_wiki_cli_capability(\OWA\Module\Base\Controller\CustomDimensionRegisterCli::class));
        $this->assertSame('edit_sites', owa_wiki_cli_capability(\OWA\Module\Base\Controller\SitesAddCli::class));
        $this->assertNull(owa_wiki_cli_capability(\OWA\Module\Base\Controller\FlushCacheCli::class));
    }

    public function testEveryRegisteredJobIsInTheTable(): void
    {
        $md   = $this->section('jobs');
        $jobs = \OWA\Module\Base\Classes\JobStatus::jobs();

        $this->assertNotEmpty($jobs);

        foreach ($jobs as $name => $job) {
            $this->assertStringContainsString("| `$name` | `{$job['command']}", $md, "job $name is not in the table");
        }
    }

    /** The page must not carry this checkout's minute for a job each install times for itself. */
    public function testSpreadSchedulesAreDescribedWithoutThisInstallsTime(): void
    {
        $jobs = owa_wiki_jobs();
        $md   = $this->section('jobs');

        $this->assertTrue($jobs['rotate-partitions']['spread']);
        $this->assertTrue($jobs['rebuild-cube']['spread']);
        $this->assertFalse($jobs['drain-tracker-ingest']['spread'], 'every minute, on every install');

        foreach ($jobs as $name => $job) {
            if ($job['spread']) {
                $this->assertStringNotContainsString(\OWA\Core\Cron::describe($job['schedule']), $md, "$name shows this install's time");
            }
        }

        $this->assertSame('daily, at a time each install derives for itself', owa_wiki_job_schedule($jobs['rotate-partitions']));
        $this->assertSame('every 5 minutes, at an offset each install derives for itself', owa_wiki_job_schedule($jobs['rebuild-cube']));
        $this->assertSame('every minute', owa_wiki_job_schedule($jobs['drain-tracker-ingest']));
    }

    public function testDetectingSpreadLeavesTheRegistrationsAsTheyWere(): void
    {
        $base   = owa_wiki_all_modules()['base'];
        $before = $base->scheduled_jobs;
        $url    = \OWA\Core\CoreAPI::getSetting('base', 'public_url');

        owa_wiki_jobs();

        $this->assertSame($before, $base->scheduled_jobs);
        $this->assertSame($url, \OWA\Core\CoreAPI::getSetting('base', 'public_url'));
    }

    public function testTheTableShowsTheCommandLineAJobRuns(): void
    {
        $lines = \OWA\Module\Base\Controller\ScheduleStatusCli::markdownTable(
            ['a-job' => ['command' => 'cube-rebuild', 'params' => ['days' => 3, 'property' => 'p1'], 'description' => 'x|y']],
            fn ($job) => 'sometimes'
        );

        $this->assertSame('| `a-job` | `cube-rebuild days=3 property=p1` | sometimes | x\\|y |', $lines[2]);
    }

    /** The class and its parents' source, up to the framework's controller. */
    private function sourceOf(string $class): string
    {
        $src = '';

        for ($r = new ReflectionClass($class); $r && $r->getName() !== 'OWA\\Core\\Controller'; $r = $r->getParentClass()) {
            $src .= file_get_contents($r->getFileName());
        }

        return $src;
    }
}
