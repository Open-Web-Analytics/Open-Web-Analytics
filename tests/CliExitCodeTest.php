<?php

require_once(__DIR__ . '/CliControllerTestCase.php');

use OWA\Core\Controller\Cli;

/**
 * cli.php's exit code: 0 when the command did what it was asked, 1 when it
 * failed, 2 when the command line is wrong -- no command, an unknown one, an
 * argument the command does not declare, or a value it cannot read.
 *
 * The command-line cases run cli.php as a person would, with commands that
 * change nothing. The rest run the controllers in this process, with arguments
 * each refuses before acting.
 */
final class CliExitCodeTest extends CliControllerTestCase
{
    /** @return array{code:int, stderr:string} */
    private function cli(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/cli.php');

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'stderr' => $stderr];
    }

    public function testNoCommandExits2(): void
    {
        $this->assertSame(2, $this->cli()['code']);
        $this->assertSame(2, $this->cli('table=owa_event_raw')['code']);
    }

    public function testAnUnknownCommandExits2(): void
    {
        $r = $this->cli('cmd=owa-no-such-command');

        $this->assertSame(2, $r['code']);
        $this->assertStringContainsString('owa-no-such-command', $r['stderr']);
    }

    public function testAnUndeclaredArgumentExits2AndIsNamed(): void
    {
        $r = $this->cli('cmd=partition-status', '--dryrun');

        $this->assertSame(2, $r['code']);
        $this->assertStringContainsString('dryrun', $r['stderr']);
    }

    public function testACommandThatSucceedsExits0(): void
    {
        $this->assertSame(0, $this->cli('cmd=partition-status')['code']);
    }

    public function testACommandThatFailsExits1(): void
    {
        $this->assertSame(1, $this->cli('cmd=jobs-forget', 'id=999999999999999')['code']);
    }

    /** fail() sets the exit code without a command opting in. */
    public function testAFailureSetsTheExitCodeByDefault(): void
    {
        $cli = new \OWA\Module\Base\Controller\PartitionStatusCli([]);
        $m   = new ReflectionMethod($cli, 'fail');
        $m->invoke($cli, 'it broke');

        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
    }

    public function testUndeclaredArguments(): void
    {
        $drop = \OWA\Module\Base\Controller\PartitionDropCli::class;

        $this->assertSame(['dryrun'], Cli::undeclaredArguments($drop, ['cmd' => 'partition-drop', 'dryrun' => true]));
        $this->assertSame([], Cli::undeclaredArguments($drop, ['cmd' => 'partition-drop', 'dry-run' => true, 'older-than' => '2years']));

        // Read only to say what replaced it.
        $this->assertSame([], Cli::undeclaredArguments(\OWA\Module\Base\Controller\PartitionRotateCli::class, ['keep' => '24']));

        // A command that does not declare its own usage() is not checked.
        $this->assertSame([], Cli::undeclaredArguments(\OWA\Module\Base\Controller\SchedulerCli::class, ['anything' => 1]));
    }

    /** @return iterable<string, array{class-string, array}> */
    public static function usageErrors(): iterable
    {
        $C = '\\OWA\\Module\\Base\\Controller\\';

        yield 'activate, no module'         => [$C . 'ModuleActivateCli', []];
        yield 'deactivate, no module'       => [$C . 'ModuleDeactivateCli', []];
        yield 'activate, unknown module'    => [$C . 'ModuleActivateCli', ['module' => 'owa_no_such_module']];
        yield 'deactivate, unknown module'  => [$C . 'ModuleDeactivateCli', ['module' => 'owa_no_such_module']];
        yield 'drain, seconds=abc'          => [$C . 'DrainTrackerIngestCli', ['seconds' => 'abc']];
        yield 'drain, seconds=0'            => [$C . 'DrainTrackerIngestCli', ['seconds' => '0']];
        yield 'prune, interval=0'           => [$C . 'PruneEventQueueArchivesCli', ['interval' => '0']];
        yield 'rotate, months-ahead=abc'    => [$C . 'PartitionRotateCli', ['months-ahead' => 'abc']];
        yield 'rotate, months-ahead=-3'     => [$C . 'PartitionRotateCli', ['months-ahead' => '-3']];
        yield 'rotate, keep='               => [$C . 'PartitionRotateCli', ['keep' => '24']];
        yield 'cube-rebuild, no Property'   => [$C . 'CubeRebuildCli', ['property' => '999999999']];
        yield 'publish-trackers, no Profile'=> [$C . 'TrackerPublishCli', ['site' => 'owa-no-such-site']];
        yield 'add-site, streamType=App'    => [$C . 'SitesAddCli', ['streamType' => 'App', 'name' => 'x', 'domain' => 'https://owa-no-site.example.com']];
        yield 'revoke, --all=maybe'         => [$C . 'AppPasswordRevokeCli', ['user' => 'owa-no-such-user', 'all' => 'maybe']];
        yield 'jobs, limit=abc'             => [$C . 'JobsCli', ['limit' => 'abc']];
        yield 'reorganize, from after to'   => [$C . 'PartitionReorganizeCli', ['granularity' => 'monthly', 'from' => '20260301', 'to' => '20260201']];
        yield 'partition-drop, only=x'      => [$C . 'PartitionDropCli', ['older-than' => '20years', 'only' => 'x']];
        yield 'register, scope=visit'       => [$C . 'CustomDimensionRegisterCli', ['property' => '999999999', 'key' => 'k', 'scope' => 'visit']];
        yield 'jobs-retry, no id'           => [$C . 'JobsRetryCli', []];
        yield 'jobs-forget, no id'          => [$C . 'JobsForgetCli', []];
        yield 'schedule-run, unknown job'   => [$C . 'ScheduleRunCli', ['job' => 'owa-no-such-job']];
        yield 'custom-dimension-list'       => [$C . 'CustomDimensionListCli', ['property' => 'abc']];
        yield 'cube-rebuild, bad range'     => [$C . 'CubeRebuildCli', ['from' => 'garbage']];
        yield 'partition-drop, no cutoff'   => [$C . 'PartitionDropCli', []];
        yield 'partition-drop, bad table'   => [$C . 'PartitionDropCli', ['older-than' => '20years', 'table' => 'owa_no_such_table']];
        yield 'partition-reorganize, none'  => [$C . 'PartitionReorganizeCli', []];
        yield 'partition-reorganize, 31 Feb'=> [$C . 'PartitionReorganizeCli', ['granularity' => 'monthly', 'from' => '20260231']];
        yield 'prune interval 7days'        => [$C . 'PruneEventQueueArchivesCli', ['interval' => '7days', 'queues' => 'owa-no-such-queue']];
        yield 'prune, unknown queue'        => [$C . 'PruneEventQueueArchivesCli', ['queues' => 'owa-no-such-queue']];
    }

    /** Every name is checked first: an unknown one stops the command before any queue is pruned. */
    public function testAnUnknownQueueIsNamedWithTheQueuesThereAre(): void
    {
        $known = array_values(array_unique(array_merge(
            array_keys((array) \OWA\Core\CoreAPI::serviceSingleton()->getMap('event_queues')),
            [\OWA\Module\Base\Classes\TrackerIngest::QUEUE])));

        $cli = new \OWA\Module\Base\Controller\PruneEventQueueArchivesCli(
            ['queues' => \OWA\Module\Base\Classes\TrackerIngest::QUEUE . ', owa-no-such-queue']);

        $log = '';
        ob_start();
        try {
            $cli->action();
        } finally {
            $log = (string) ob_get_clean();
        }

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame('Unknown queue: owa-no-such-queue. Queues: ' . implode(', ', $known) . '.', $cli->getCliOutcome()['message']);
        $this->assertStringNotContainsString('Pruned', $log, 'nothing pruned before the names were checked');
    }

    /** @dataProvider usageErrors */
    public function testAnArgumentACommandCannotReadExits2(string $class, array $params): void
    {
        $cli = new $class($params);

        ob_start();
        try {
            $cli->doAction();
        } finally {
            ob_end_clean();
        }

        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertTrue($cli->getCliOutcome()['final']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }

    /** A refused argument is named, with what it takes, and the command does nothing. */
    public function testARefusedArgumentIsNamedWithWhatItTakes(): void
    {
        $cli = new \OWA\Module\Base\Controller\ModuleActivateCli(['cmd' => 'activate', 'module' => 'owa_no_such_module']);
        $cli->doAction();

        $this->assertSame('cmd=activate: module=owa_no_such_module: There is no module named owa_no_such_module. Modules: '
            . implode(', ', \OWA\Core\Validation\ModuleExists::names()) . '. Nothing was done.', $cli->getCliOutcome()['message']);

        Cli::$exit_code = 0;
        $cli = new \OWA\Module\Base\Controller\CubeRebuildCli(['property' => '999999999']);
        $cli->doAction();

        $this->assertSame('cmd=cube-rebuild: property=999999999: There is no Property 999999999. Nothing was done.', $cli->getCliOutcome()['message']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }

    /** publish-trackers site= takes a live web Profile: an archived one and an app's are refused. */
    public function testPublishTrackersTakesOnlyALiveWebProfile(): void
    {
        $sm   = new \OWA\Module\Base\Classes\SiteManager();
        $web  = $sm->createSite('https://owatest-publish-' . $this->tok . '.example.com', 'Publish ' . $this->tok);
        $app  = $sm->createSite('', 'Publish app ' . $this->tok, '', '', '', '', \OWA\Module\Base\Entity\Site::STREAM_APP, 'com.example.owatest' . $this->tok);

        foreach ([$web, $app] as $site) {
            $this->assertNotEmpty($site);
            $this->trackForCleanup('base.site', $site->get('id'), 'id');
            $this->trackForCleanup('base.property', $site->get('property_id'), 'id');
        }

        $declared = \OWA\Module\Base\Controller\TrackerPublishCli::params();

        $this->assertSame([], \OWA\Core\Params::problems($declared, ['site' => $web->get('site_id')]));
        $this->assertSame(['site' => 'site=' . $app->get('site_id') . ': Profile ' . $app->get('site_id') . ' observes app, not web.'],
            \OWA\Core\Params::problems($declared, ['site' => $app->get('site_id')]));

        $web->set('archived_date', time());
        $web->update();

        $this->assertSame(['site' => 'site=' . $web->get('site_id') . ': Profile ' . $web->get('site_id') . ' is archived.'],
            \OWA\Core\Params::problems($declared, ['site' => $web->get('site_id')]));
    }

    /** core-update-schema apply= or rollback= with no update is refused before anything is applied. */
    public function testAnEmptyUpdateNameIsAUsageError(): void
    {
        $declared = \OWA\Module\Base\Controller\CoreUpdateSchemaCli::params();

        $this->assertSame(['apply' => 'apply=: Needs an update, such as base.98.'], \OWA\Core\Params::problems($declared, ['apply' => '']));
        $this->assertSame(['rollback' => 'rollback=: Needs an update, such as base.98.'], \OWA\Core\Params::problems($declared, ['rollback' => '']));
        $this->assertSame(['apply' => '--apply: Needs an update, such as base.98.'], \OWA\Core\Params::problems($declared, ['apply' => true]));
        $this->assertSame([], \OWA\Core\Params::problems($declared, ['apply' => 'base.98']));
    }

    public function testAnUpdateIsNamedModuleDotSequence(): void
    {
        $U = \OWA\Module\Base\Controller\CoreUpdateSchemaCli::class;

        $this->assertSame(['base', '86'], $U::parseUpdateName('base.86'));
        $this->assertNull($U::parseUpdateName('base'));
        $this->assertNull($U::parseUpdateName('base.'));
        $this->assertNull($U::parseUpdateName(true));
    }

    /**
     * An update that stops for the operator leaves the run incomplete: exit 1,
     * with the message saying what to do.
     *
     * In its own process: CoreUpdateSchemaCli defines OWA_UPDATING, which would
     * turn off the pending-update check for every later test here.
     */
    public function testAnUpdatePausedForTheOperatorExits1(): void
    {
        $code = <<<'PHP'
$_SERVER['argc'] = 1;
require 'owa.php';
new owa(array('request_type' => 'cli'));
$s = \OWA\Core\CoreAPI::serviceSingleton();
$s->modules_needing_updates = array('owatestpause');
$s->modules['owatestpause'] = new class {
    public $update_awaiting = '';
    public function update() { $this->update_awaiting = 'a choice from the operator'; return false; }
};
$cli = new \OWA\Module\Base\Controller\CoreUpdateSchemaCli(array());
ob_start();
$cli->action();
ob_end_clean();
echo 'RESULT ', json_encode(array('exit' => \OWA\Core\Controller\Cli::$exit_code) + $cli->getCliOutcome()), "\n";
PHP;

        $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && '
            . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        $this->assertMatchesRegularExpression('/^RESULT (.*)$/m', $out, $out);
        preg_match('/^RESULT (.*)$/m', $out, $m);
        $result = json_decode($m[1], true);

        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(Cli::EXIT_FAILED, $result['exit']);
        $this->assertSame('Updates paused: one needs a choice from the operator. Do what it says above, then run cmd=core-update-schema again.',
            $result['message']);
    }

    /**
     * An update that fails exits 1 with the failure message as text. The
     * message was handed over as its headline-and-message array.
     */
    public function testAFailedUpdateExits1WithItsMessage(): void
    {
        $code = <<<'PHP'
$_SERVER['argc'] = 1;
require 'owa.php';
new owa(array('request_type' => 'cli'));
$s = \OWA\Core\CoreAPI::serviceSingleton();
$s->modules_needing_updates = array('owatestfail');
$s->modules['owatestfail'] = new class {
    public $update_awaiting = '';
    public function update() { return false; }
};
$cli = new \OWA\Module\Base\Controller\CoreUpdateSchemaCli(array());
ob_start();
$cli->action();
ob_end_clean();
echo 'RESULT ', json_encode(array('exit' => \OWA\Core\Controller\Cli::$exit_code) + $cli->getCliOutcome()), "\n";
PHP;

        $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && '
            . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        $this->assertSame(1, preg_match('/^RESULT (.*)$/m', $out, $m), $out);
        $result = json_decode($m[1], true);

        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(Cli::EXIT_FAILED, $result['exit']);
        $this->assertSame('The updates failed. See the error log.', $result['message']);
    }

    /** A capability refused before action() is a failure. */
    public function testARefusedCapabilityExits1(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(\OWA\Module\Base\Controller\JobsPruneCli::class, '', []);

        $this->assertNotCapable($result);
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
    }

    /** Validation failing before action() is a usage error. */
    /** A declared argument refused: the uniform usage error, naming it, exit 2. */
    public function testFailedValidationExits2(): void
    {
        $cli = new \OWA\Module\Base\Controller\ChangeUserPasswordCli(['cmd' => 'change-password', 'user' => 'nobody', 'password' => 'short']);

        ob_start();
        try {
            $cli->doAction();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
        $this->assertSame(['outcome' => 'failed', 'message' => 'cmd=change-password: user=nobody: There is no user nobody. Nothing was done.', 'final' => true],
            $cli->getCliOutcome());
    }

    /** A rule validate() adds, past the declared arguments, still answers with validation_errors and exits 2. */
    public function testAFailedValidateRuleExits2(): void
    {
        $user = $this->makeUser('viewer', 'pwrule');

        ob_start();
        try {
            $result = $this->runCommand(\OWA\Module\Base\Controller\ChangeUserPasswordCli::class, '', ['user' => $user['user_id'], 'password' => 'short']);
        } finally {
            ob_end_clean();
        }

        $this->assertArrayHasKey('validation_errors', $result['data']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }

    public function testAddSiteWithInvalidArgumentsExits2(): void
    {
        $result = $this->runCommand(\OWA\Module\Base\Controller\SitesAddCli::class, '', ['name' => 'no domain ' . $this->tok]);

        $this->assertArrayHasKey('validation_errors', $result['data']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }
}
