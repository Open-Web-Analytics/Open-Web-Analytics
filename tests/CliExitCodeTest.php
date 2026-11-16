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

        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, dirname(__DIR__));
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return array('code' => proc_close($proc), 'stderr' => $stderr);
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
        $cli = new \OWA\Module\Base\Controller\PartitionStatusCli(array());
        $m   = new ReflectionMethod($cli, 'fail');
        $m->invoke($cli, 'it broke');

        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
    }

    public function testUndeclaredArguments(): void
    {
        $drop = \OWA\Module\Base\Controller\PartitionDropCli::class;

        $this->assertSame(array('dryrun'), Cli::undeclaredArguments($drop, array('cmd' => 'partition-drop', 'dryrun' => true)));
        $this->assertSame(array(), Cli::undeclaredArguments($drop, array('cmd' => 'partition-drop', 'dry-run' => true, 'older-than' => '2years')));

        // Read only to say what replaced it.
        $this->assertSame(array(), Cli::undeclaredArguments(\OWA\Module\Base\Controller\PartitionRotateCli::class, array('keep' => '24')));

        // A command that does not declare its own usage() is not checked.
        $this->assertSame(array(), Cli::undeclaredArguments(\OWA\Module\Base\Controller\SchedulerCli::class, array('anything' => 1)));
    }

    /**
     * Every argument a command reads is one it accepts, so refusing undeclared
     * arguments never refuses one that works.
     */
    public function testEveryArgumentACommandReadsIsAccepted(): void
    {
        // Set by Controller::doAction() from validation, not typed.
        $internal = array('validation_errors');
        // Read in code these commands never reach: PartitionsCli's budget
        // checks, and the JobsCli modes other than their own.
        $unreached = array(
            'partition-status' => array('force'),
            'jobs'             => array('id'),
            'jobs-retry'       => array('status', 'limit'),
            'jobs-forget'      => array('status', 'limit'),
            'jobs-prune'       => array('id', 'status', 'limit'),
        );
        $s        = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ((array) $s->getMap('cli_commands') as $command => $action) {
            $map   = $s->getMapValue('actions', $action);
            $class = is_array($map) ? (string) ($map['class_name'] ?? '') : '';

            if ($class === '' || ($accepted = Cli::acceptedArgumentsFor($class)) === null) {
                continue;
            }

            // The command and the Cli classes it extends; a screen it extends
            // (sitesAddCli) reads the screen's form fields.
            $src = '';
            for ($r = new ReflectionClass($class); $r && ($r->getName() === ltrim($class, '\\') || $r->isSubclassOf(Cli::class)); $r = $r->getParentClass()) {
                $src .= file_get_contents($r->getFileName());
            }

            preg_match_all('/(?:getParam|isParam)\(\s*[\'"]([^\'"]+)[\'"]/', $src, $m);

            $this->assertSame(array(), array_values(array_diff(array_unique($m[1]), $accepted, $internal, $unreached[$command] ?? array())),
                "cmd=$command reads arguments its usage() does not declare");
        }
    }

    /** @return iterable<string, array{class-string, array}> */
    public static function usageErrors(): iterable
    {
        $C = '\\OWA\\Module\\Base\\Controller\\';

        yield 'activate, no module'         => array($C . 'ModuleActivateCli', array());
        yield 'deactivate, no module'       => array($C . 'ModuleDeactivateCli', array());
        yield 'install-module, no module'   => array($C . 'ModuleInstallCli', array());
        yield 'jobs-retry, no id'           => array($C . 'JobsRetryCli', array());
        yield 'jobs-forget, no id'          => array($C . 'JobsForgetCli', array());
        yield 'schedule-run, unknown job'   => array($C . 'ScheduleRunCli', array('job' => 'owa-no-such-job'));
        yield 'custom-dimension-list'       => array($C . 'CustomDimensionListCli', array('property' => 'abc'));
        yield 'cube-rebuild, bad range'     => array($C . 'CubeRebuildCli', array('from' => 'garbage'));
        yield 'partition-drop, no cutoff'   => array($C . 'PartitionDropCli', array());
        yield 'partition-drop, bad table'   => array($C . 'PartitionDropCli', array('older-than' => '20years', 'table' => 'owa_no_such_table'));
        yield 'partition-init, weekly'      => array($C . 'PartitionInitCli', array('granularity' => 'weekly'));
        yield 'partition-reorganize, none'  => array($C . 'PartitionReorganizeCli', array());
        yield 'partition-reorganize, 31 Feb'=> array($C . 'PartitionReorganizeCli', array('granularity' => 'monthly', 'from' => '20260231'));
        yield 'prune interval 7days'        => array($C . 'PruneEventQueueArchivesCli', array('interval' => '7days', 'queues' => 'owa-no-such-queue'));
    }

    /** @dataProvider usageErrors */
    public function testAnArgumentACommandCannotReadExits2(string $class, array $params): void
    {
        $cli = new $class($params);

        ob_start();
        try {
            $cli->action();
        } finally {
            ob_end_clean();
        }

        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }

    public function testPartitionReorganizeReadsRealDates(): void
    {
        $R = \OWA\Module\Base\Controller\PartitionReorganizeCli::class;

        $this->assertTrue($R::isDate('20260228'));
        $this->assertTrue($R::isDate('20240229'));
        $this->assertFalse($R::isDate('20260229'));
        $this->assertFalse($R::isDate('20261301'));
        $this->assertFalse($R::isDate('2026-01-01'));
    }

    public function testAnUpdateIsNamedModuleDotSequence(): void
    {
        $U = \OWA\Module\Base\Controller\UpdatesApplyCli::class;

        $this->assertSame(array('base', '86'), $U::parseUpdateName('base.86'));
        $this->assertNull($U::parseUpdateName('base'));
        $this->assertNull($U::parseUpdateName('base.'));
        $this->assertNull($U::parseUpdateName(true));
    }

    /**
     * An update that stops for the operator leaves the run incomplete: exit 1,
     * with the message saying what to do.
     *
     * In its own process: UpdatesApplyCli defines OWA_UPDATING, which would
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
$cli = new \OWA\Module\Base\Controller\UpdatesApplyCli(array());
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
        $this->assertSame('Updates paused: one needs a choice from the operator. Do what it says above, then run cmd=update again.',
            $result['message']);
    }

    /**
     * cmd=install refused for its password -- none, or too short -- is a usage
     * error, and exits 2 before installing anything. In its own process, as
     * InstallCli defines OWA_INSTALLING; its action() is replaced, so nothing
     * could be installed even if validation let it through.
     *
     * @dataProvider refusedInstalls
     */
    public function testAnInstallRefusedForItsPasswordExits2(array $params, string $says): void
    {
        $code = <<<'PHP'
$_SERVER['argc'] = 1;
require 'owa.php';
new owa(array('request_type' => 'cli'));
\OWA\Core\CoreAPI::getCurrentUser()->setRole('admin');
\OWA\Core\CoreAPI::getCurrentUser()->setAuthStatus(true);
$cli = new class(json_decode($argv[1], true)) extends \OWA\Module\Base\Controller\InstallCli {
    function action() { echo "ACTION RAN\n"; }
};
ob_start();
$data = $cli->doAction();
ob_end_clean();
echo 'RESULT ', json_encode(array('exit' => \OWA\Core\Controller\Cli::$exit_code, 'errors' => $data['validation_errors'] ?? null)), "\n";
PHP;

        $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && '
            . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg(json_encode($params)) . ' 2>&1');

        $this->assertStringNotContainsString('ACTION RAN', $out);
        $this->assertSame(1, preg_match('/^RESULT (.*)$/m', $out, $m), $out);

        $result = json_decode($m[1], true);

        $this->assertSame(Cli::EXIT_USAGE, $result['exit']);
        $this->assertStringContainsString($says, json_encode($result['errors']));
    }

    /** @return iterable<string, array{array, string}> */
    public static function refusedInstalls(): iterable
    {
        $base = array('user_id' => 'owa-test-admin', 'email_address' => 'owa-test-admin@example.test', 'domain' => 'https://example.test');

        yield 'no password'    => array($base, 'password for the admin account is required');
        yield 'short password' => array($base + array('password' => 'short'), 'at least');
    }

    /** A capability refused before action() is a failure. */
    public function testARefusedCapabilityExits1(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(\OWA\Module\Base\Controller\JobsPruneCli::class, '', array());

        $this->assertNotCapable($result);
        $this->assertSame(Cli::EXIT_FAILED, Cli::$exit_code);
    }

    /** Validation failing before action() is a usage error. */
    public function testFailedValidationExits2(): void
    {
        ob_start();
        try {
            $result = $this->runCommand(\OWA\Module\Base\Controller\ChangeUserPasswordCli::class, '', array('user' => 'nobody', 'password' => 'short'));
        } finally {
            ob_end_clean();
        }

        $this->assertArrayHasKey('validation_errors', $result['data']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }

    public function testAddSiteWithInvalidArgumentsExits2(): void
    {
        $result = $this->runCommand(\OWA\Module\Base\Controller\SitesAddCli::class, '', array('name' => 'no domain ' . $this->tok));

        $this->assertArrayHasKey('validation_errors', $result['data']);
        $this->assertSame(Cli::EXIT_USAGE, Cli::$exit_code);
    }
}
