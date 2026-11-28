<?php

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\AutoUpdate\Install;
use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;
use OWA\Module\Base\Controller\CoreInstallCli;
use OWA\Module\Base\Controller\CoreUpdateCli;
use PHPUnit\Framework\TestCase;

/**
 * The job commands that give up (Cli::fail( $msg, true )) rather than be
 * retried, each for a failure a retry would meet again -- and the failure
 * beside it on the same path that is still retried.
 *
 * The audit of every command that runs as a job found these two. The rest
 * fail on database refusals, locks, queues and partial builds, which a retry
 * can get past, or on usage errors, which give up on their own.
 */
final class JobGiveUpsTest extends TestCase
{
    private function coreUpdate(string $outcome): array
    {
        $check = new class($outcome) extends ReleaseCheck {
            private $answer;
            public function __construct($answer) { $this->answer = $answer; }
            public function run($dry_run = false) {
                return ['outcome' => $this->answer, 'version' => null, 'problems' => ['why'], 'lines' => [], 'message' => ''];
            }
        };

        $cli = new class(['scheduled' => 1], $check) extends CoreUpdateCli {
            private $check;
            public function __construct($params, $check) { parent::__construct($params); $this->check = $check; }
            protected function releaseCheck($scheduled) { return $this->check; }
        };

        $cli->action();

        return $cli->getCliOutcome();
    }

    /** An installation that is installed, or not: for the commands built here. */
    public static function installed(bool $installed): \OWA\Module\Base\Classes\InstallManager
    {
        return new class($installed) extends \OWA\Module\Base\Classes\InstallManager {
            private $installed;
            public function __construct($installed) { $this->installed = $installed; }
            public function isInstalled() { return $this->installed; }
        };
    }

    private function coreInstall(string $outcome): array
    {
        $cli = new class(['version' => '2.0.1'], $outcome) extends CoreInstallCli {
            private $answer;
            public function __construct($params, $answer) { parent::__construct($params); $this->answer = $answer; }
            protected function installManager() { return JobGiveUpsTest::installed(true); }
            protected function write($lines) {}
            protected function install($version, $force) {
                return ['outcome' => $this->answer, 'message' => 'answered ' . $this->answer, 'lines' => []];
            }
        };

        $cli->action();

        return $cli->getCliOutcome();
    }

    /** @var mixed */
    private $requestType;

    /** A CLI request: Controller\Cli's constructor exits on any other, and ended the run here when this file ran on its own. */
    protected function setUp(): void
    {
        $this->requestType = \OWA\Core\CoreAPI::getSetting('base', 'request_type');
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->requestType);
        \OWA\Core\Controller\Cli::$exit_code = 0;
    }

    /** A temp dir, another user's files and a failed download give up; an unanswered listing is the network, retried. */
    public function testTheReleaseCheckGivesUpOnlyWhereARetryMeetsTheSameAnswer(): void
    {
        foreach ([
            ReleaseCheck::NO_TEMP   => true,
            ReleaseCheck::FOREIGN   => true,
            ReleaseCheck::FAILED    => true,
            ReleaseCheck::UNREACHED => false,
        ] as $outcome => $gives_up) {
            $out = $this->coreUpdate($outcome);

            $this->assertSame('failed', $out['outcome'], $outcome);
            $this->assertSame($gives_up, $out['final'], $outcome);
        }

        foreach ([ReleaseCheck::CURRENT, ReleaseCheck::INSTALLING, ReleaseCheck::BUSY, ReleaseCheck::SKIPPED] as $outcome) {
            $this->assertSame('ok', $this->coreUpdate($outcome)['outcome'], $outcome);
        }
    }

    /** An install retries only another install running; a refusal or a failed install gives up. */
    public function testAnInstallRetriesOnlyABusyInstaller(): void
    {
        foreach ([Install::BUSY => false, Install::REFUSED => true, Install::FAILED => true] as $outcome => $gives_up) {
            $out = $this->coreInstall($outcome);

            $this->assertSame('failed', $out['outcome'], $outcome);
            $this->assertSame($gives_up, $out['final'], $outcome);
            $this->assertSame('answered ' . $outcome, $out['message']);
        }

        $this->assertSame('ok', $this->coreInstall(Install::OK)['outcome']);
    }

    /** A release named before OWA is installed is refused, and gives up: nothing would change on a retry. */
    public function testAReleaseBeforeTheInstallGivesUp(): void
    {
        $cli = new class(['version' => '2.0.1']) extends CoreInstallCli {
            protected function installManager() { return JobGiveUpsTest::installed(false); }
            protected function install($version, $force) { throw new \LogicException('installed a release'); }
        };
        $cli->action();

        $this->assertSame(['outcome' => 'failed', 'final' => true],
            array_intersect_key($cli->getCliOutcome(), ['outcome' => 1, 'final' => 1]));
    }
}
