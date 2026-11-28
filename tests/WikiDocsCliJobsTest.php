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
        // owa_wiki_resolve(), called below.
        require_once self::WIKI . '/generate.php';
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
        $this->assertArrayHasKey('geoip-update-db', $commands, 'a command of a module a configless boot leaves inactive');

        foreach (array_unique(array_merge($active, array_keys($commands))) as $command) {

            if (!empty($commands[$command]::usage()['deprecated'])) {
                continue;
            }

            $this->assertStringContainsString("\n### $command\n", $md, "cmd=$command has no section");
            $this->assertStringContainsString("\n- [`$command`](#$command) — ", $md, "cmd=$command is not in the index");
        }
    }

    /**
     * A deprecated command is left out of the reference, by its usage()'s
     * `deprecated` marker, and keeps running. The marker and a description that
     * says "Deprecated" go together.
     */
    public function testADeprecatedCommandIsLeftOut(): void
    {
        $md       = $this->section('cli');
        $commands = $this->registeredCommands();

        $this->assertTrue(\OWA\Module\Base\Controller\ModuleInstallCli::usage()['deprecated'] ?? false);
        $this->assertSame(\OWA\Module\Base\Controller\ModuleInstallCli::class, $commands['install-module']);
        $this->assertStringNotContainsString('install-module', $md);
        $this->assertStringNotContainsString('Deprecated', $md);
        $this->assertStringContainsString("\n### activate\n", $md);
        $this->assertStringContainsString("\n- [`activate`](#activate) — ", $md);

        foreach ($commands as $command => $class) {

            $usage = $class::usage();

            $this->assertSame(!empty($usage['deprecated']), str_starts_with((string) $usage['description'], 'Deprecated'),
                "cmd=$command: the deprecated marker and the description disagree");
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
            'core-update-schema' => ['since' => 'modules/Base/Update/Update062.php', 'all' => 'modules/Base/Update/Update062.php'],
        ];

        foreach ($this->registeredCommands() as $command => $class) {

            foreach (array_keys($class::usage()['arguments']) as $arg) {

                $name = ltrim(explode('=', $arg, 2)[0], '-');
                $src  = isset($elsewhere[$command][$name])
                    ? file_get_contents(dirname(__DIR__) . '/' . $elsewhere[$command][$name])
                    : $this->sourceOf($class);

                // As a parsed parameter ('force'), or as the raw argument ('--force').
                $this->assertMatchesRegularExpression('/[\'"](?:--)?' . preg_quote($name, '/') . '[\'"]/', $src,
                    "cmd=$command documents $arg, but nothing in its code reads '$name'");
            }
        }
    }

    /** As documented, with a placeholder value where it names one, each argument is one cli.php accepts. */
    public function testEveryDocumentedArgumentParses(): void
    {
        foreach ($this->registeredCommands() as $command => $class) {

            foreach (array_keys($class::usage()['arguments']) as $arg) {

                $parsed = \OWA\Core\Lib::parseCliArgs(['cli.php', preg_replace('/=<[^>]*>|=\S+$/', '=x', $arg)]);
                $this->assertIsArray($parsed, "cmd=$command documents $arg, which cli.php refuses: " . (is_string($parsed) ? $parsed : ''));
            }
        }
    }

    public function testACommandWithNoArgumentsSaysSo(): void
    {
        $this->assertSame([], \OWA\Module\Base\Controller\ServerCheckCli::usage()['arguments']);
        $this->assertMatchesRegularExpression('/### server-check\n\n(?:.+\n\n)+```bash\nphp cli.php cmd=server-check\n```\n\nTakes no arguments\./', $this->section('cli'));
    }

    /** Each section names the server roles the command runs on (Controller\Cli::serverRoles()). */
    public function testEachSectionNamesTheServerRolesItRunsOn(): void
    {
        $md = $this->section('cli');

        $this->assertStringContainsString("### schedule-run\n", $md);
        $this->assertMatchesRegularExpression('/### schedule-run\n\n(?:.+\n\n)*?Runs on server roles: `full`\.\n/', $md);
        $this->assertMatchesRegularExpression('/### change-password\n\n(?:.+\n\n)*?Runs on server roles: `full`\.\n/', $md);
        $this->assertMatchesRegularExpression('/### server-check\n\n(?:.+\n\n)*?Runs on server roles: `full`, `collector`, `api`\.\n/', $md);
    }

    /*
     * The index is a list: in a table GitHub wrapped the names at every hyphen.
     * The capability moved into each command's section, so it has to be there.
     */
    public function testEachSectionStatesItsCapabilityAndTheIndexIsNotATable(): void
    {
        $md = $this->section('cli');

        $this->assertStringNotContainsString('| Command |', $md);
        $this->assertMatchesRegularExpression(
            '/### custom-dimension-register\n\n.+\n\nRequires the `edit_modules` capability\.\n/', $md);
        $this->assertDoesNotMatchRegularExpression('/### version-info\n\n.+\n\nRequires the /', $md);
    }

    public function testCapabilityIsReadUpTheClassChain(): void
    {
        // Declared on CustomDimensionsCli, the parent.
        $this->assertSame('edit_modules', owa_wiki_cli_capability(\OWA\Module\Base\Controller\CustomDimensionRegisterCli::class));
        $this->assertSame('edit_sites', owa_wiki_cli_capability(\OWA\Module\Base\Controller\SitesAddCli::class));
        $this->assertNull(owa_wiki_cli_capability(\OWA\Module\Base\Controller\VersionInfoCli::class));
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
        $this->assertSame('hourly, at a minute each install derives for itself', owa_wiki_job_schedule($jobs['core-update']));

        // A module's job: listed whether or not the module is active here.
        $this->assertTrue($jobs['refresh-geoip-db']['spread']);
        $this->assertSame('weekly, on a day and at a time each install derives for itself', owa_wiki_job_schedule($jobs['refresh-geoip-db']));
        $this->assertStringContainsString('`refresh-geoip-db` runs while the `maxmind_geoip` module is active.', $md);
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

        $this->assertSame('| `a-job` | `cube-rebuild days=3 property=p1` | sometimes | x\\|y | until the next run, waiting 1 minute and doubling to 1 hour, then every 1 hour |', $lines[2]);

        $lines = \OWA\Module\Base\Controller\ScheduleStatusCli::markdownTable(
            ['b-job' => ['command' => 'cube-rebuild', 'params' => [], 'description' => '', 'retry' => ['max_attempts' => 2, 'backoff' => [300]]]],
            fn ($job) => 'sometimes'
        );

        $this->assertStringEndsWith('| up to 2 attempts, waiting 5 minutes |', $lines[2]);
    }

    /**
     * cli-<command> is that command's arguments, for a page that embeds one
     * command: every argument usage() declares, and the same table the full
     * reference shows.
     */
    public function testACommandsOwnSectionIsItsArgumentsTable(): void
    {
        $fn   = require self::WIKI . '/sections/cli.php';
        $full = $fn(null);

        // A hyphenated command resolves to cli.php with the whole command as its argument.
        $resolved = owa_wiki_resolve('cli-custom-dimension-register');
        $this->assertSame([realpath(self::WIKI . '/sections/cli.php'), 'custom-dimension-register'],
            [realpath($resolved[0]), $resolved[1]]);

        foreach (owa_wiki_cli_commands() as $command => $c) {

            $md = $fn($command);

            if (!$c['arguments']) {
                $this->assertSame("`cmd=$command` takes no arguments.\n", $md);
                continue;
            }

            $this->assertStringStartsWith("| Argument | Description |\n|---|---|\n", $md);

            if ($c['deprecated']) {
                continue;
            }

            $this->assertStringContainsString("\n### $command\n", $full);
            $this->assertStringContainsString($md, $full, "cli-$command differs from its table in the full reference");

            foreach (array_keys($c['arguments']) as $arg) {
                $this->assertStringContainsString('| `' . owa_wiki_cell($arg) . '` |', $md, "cli-$command leaves out $arg");
            }
        }
    }

    public function testACommandSectionForNoSuchCommandIsAnError(): void
    {
        $fn = require self::WIKI . '/sections/cli.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no-such-command');

        $fn('no-such-command');
    }

    public function testEveryCronAliasIsInTheTable(): void
    {
        $md = $this->section('cron-aliases');

        $this->assertNotEmpty(\OWA\Core\Cron::ALIASES);

        foreach (\OWA\Core\Cron::ALIASES as $alias => $expr) {
            $this->assertMatchesRegularExpression('/^\| [^|]*`' . preg_quote($alias, '/') . '`[^|]* \| `' . preg_quote($expr, '/') . '` \| '
                . preg_quote(\OWA\Core\Cron::describe($alias), '/') . ' \|$/m', $md, "$alias is not in the table");
        }
    }

    public function testEveryScheduledJobKeyIsInTheTable(): void
    {
        $md   = $this->section('job-config-keys');
        $keys = \OWA\Module\Base\Classes\Service::SCHEDULED_JOB_KEYS;

        $this->assertSame(['command', 'schedule', 'params', 'description', 'retry'], array_keys($keys),
            'applyConfiguredJobs() copies exactly these keys, so a new one is documented by adding it here');

        foreach ($keys as $key => $meaning) {
            $this->assertStringContainsString("| `$key` | " . owa_wiki_cell($meaning) . ' |', $md, "$key is not in the table");
        }
    }

    public function testEveryRefusedCommandIsInTheListAndIsARealCommand(): void
    {
        $md       = $this->section('job-refused-commands');
        $commands = $this->registeredCommands();

        $this->assertNotEmpty(\OWA\Module\Base\Classes\Service::NEVER_SCHEDULE);

        foreach (array_keys(\OWA\Module\Base\Classes\Service::NEVER_SCHEDULE) as $command) {
            $this->assertArrayHasKey($command, $commands, "$command is refused, but no command has that name");
            $this->assertStringContainsString("| `$command` |\n", $md, "$command is not in the table");
        }
    }

    public function testTheLimitsTableShowsEachConstant(): void
    {
        $md     = $this->section('job-limits');
        $policy = \OWA\Module\Base\Classes\RetryPolicy::ONE_OFF;

        $expect = [
            'Time one `schedule-run` tick may spend starting jobs' => self::duration(\OWA\Module\Base\Controller\ScheduleRunCli::MAX_RUNTIME),
            'A job\'s lock lease when its command sets none'      => self::duration(\OWA\Core\Controller\Cli::DEFAULT_JOB_LEASE),
            'How late a job can be before it is called overdue'   => self::duration(\OWA\Module\Base\Classes\JobStatus::MIN_GRACE),
            'Attempts a one-off job gets'                         => (string) $policy['max_attempts'],
            'A one-off job\'s lease'                              => self::duration(\OWA\Module\Base\Classes\JobQueue::DEFAULT_LEASE),
            'A recurring job\'s retries'                          => 'until the next run',
            'How long a done one-off job is kept'                 => self::duration(\OWA\Module\Base\Classes\JobQueue::KEEP_DONE),
            'How long a failed one-off job is kept'               => self::duration(\OWA\Module\Base\Classes\JobQueue::KEEP_FAILED),
        ];

        foreach ($expect as $limit => $value) {
            $this->assertMatchesRegularExpression('/^\| ' . preg_quote($limit, '/') . '[^|]* \| [^|]*\b' . preg_quote($value, '/') . '\b[^|]* \|$/m',
                $md, "$limit is not shown as $value");
        }

        $this->assertMatchesRegularExpression(sprintf('/^\| Silence before .* \| %d intervals .*at least %s and at most %s \|$/m',
            \OWA\Module\Base\Classes\SchedulerHealth::MISSED_INTERVALS,
            self::duration(\OWA\Module\Base\Classes\SchedulerHealth::MIN_SILENT_FOR),
            self::duration(\OWA\Module\Base\Classes\SchedulerHealth::SILENT_FOR)), $md);

        // One wait per retry: an attempt short of the last is retried.
        preg_match('/^\| Wait before a failed one-off job is retried \| (.*) \|$/m', $md, $m);
        $this->assertCount($policy['max_attempts'] - 1, explode(', then ', $m[1] ?? ''));
    }

    /** Seconds in the largest whole unit, as job-limits writes them. */
    private static function duration(int $seconds): string
    {
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'] as $unit => $name) {
            if ($seconds >= $unit && $seconds % $unit === 0) {
                $n = intdiv($seconds, $unit);
                return $n . ' ' . $name . ($n === 1 ? '' : 's');
            }
        }

        return $seconds . ' seconds';
    }

    /** The class and its parents' source, up to the framework's controller, with the traits each uses. */
    private function sourceOf(string $class): string
    {
        $src = '';

        for ($r = new ReflectionClass($class); $r && $r->getName() !== 'OWA\\Core\\Controller'; $r = $r->getParentClass()) {
            $src .= file_get_contents($r->getFileName());

            foreach ($r->getTraits() as $trait) {
                $src .= file_get_contents($trait->getFileName());
            }
        }

        return $src;
    }
}
