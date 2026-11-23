<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\AutoUpdate\Finish;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\AutoUpdate\InstallNotices;
use OWA\Module\Base\Classes\AutoUpdate\Downloads;

/**
 * Step 4: finish, the parts that run without a fresh process: the clean-up
 * after a successful install, the notices a failing one raises, and
 * cmd=core-update-schema keeping out of an install's way.
 */
final class InstallFinishTest extends CliControllerTestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/owa-finish-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/updates', 0700, true);
        InstallLock::$path = $this->tmp . '/updating';
    }

    protected function tearDown(): void
    {
        InstallLock::$path      = null;
        InstallLock::$held_here = false;
        exec('rm -rf ' . escapeshellarg($this->tmp));

        parent::tearDown();
    }

    /** After an install: the failure record, and every tarball but this release's, which the next install puts back if it fails. */
    public function testTheCleanUp(): void
    {
        $d      = new Downloads($this->tmp . '/updates');
        $tarred = function (string $v) use ($d): void {
            file_put_contents($d->tarball($v), 'a tarball');
            file_put_contents($d->tarball($v) . '.sig', 'a signature');
        };

        $tarred('2.0.2');
        $tarred('2.0.3');
        $d->writeFailure(['version' => '2.0.3', 'from' => '2.0.2', 'error' => 'an earlier attempt']);

        Finish::cleanup($d, '2.0.3');
        $this->assertNull($d->failure());
        $this->assertSame(['2.0.3'], $d->versions());
    }

    /** One notice per failed release; what it says depends on whether the new release had booted. */
    public function testTheNoticeOfAFailedInstall(): void
    {
        $raised = [];
        $notify = function (array $n) use (&$raised) { $raised[] = $n; };
        $failed = ['version' => '2.0.3', 'from' => '2.0.2', 'error' => 'Could not write Core/Env.php. OWA 2.0.2 was put back.'];

        InstallNotices::forFailure($failed, $notify);
        InstallNotices::forFailure(['booted' => true, 'error' => 'cmd=core-update-schema did not complete'] + $failed, $notify);
        InstallNotices::forFailure(['error' => ''] + $failed, $notify);

        $this->assertCount(2, $raised, 'nothing for a record without an error');
        $this->assertSame(['install_failed:2.0.3', 'warning', true], [$raised[0]['source_key'], $raised[0]['severity'], $raised[0]['email']]);
        $this->assertStringContainsString('The installed release runs as before. Automatic updates skip OWA 2.0.3', $raised[0]['body'], 'put back: the old release runs');
        $this->assertStringContainsString('php cli.php cmd=core-update --version=2.0.3', $raised[0]['body']);
        $this->assertStringContainsString('tracking is queued and reports wait', $raised[1]['body'], 'booted: the schema is behind');
        $this->assertStringContainsString('php cli.php cmd=core-update-schema', $raised[1]['body']);
    }

    /** cmd=core-update-schema by hand waits for an install and takes no lock of its own; the installer itself runs it. */
    public function testCmdUpdateWaitsForAnInstall(): void
    {
        $h = fopen(InstallLock::$path, 'c');
        flock($h, LOCK_EX);

        try {
            $cli = $this->updates(['listpending' => true]);
            $this->assertSame('ok', $cli->getCliOutcome()['outcome'], 'listing pending updates needs no lock');

            $cli = $this->updates([]);
            $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
            $this->assertStringContainsString('An automatic update is being installed', $cli->getCliOutcome()['message']);

            InstallLock::$held_here = true;
            $cli = $this->updates([]);
            $this->assertNotSame('An automatic update is being installed; it runs the schema updates itself. Try again once it has finished.',
                $cli->getCliOutcome()['message'], 'the installer holds it already');
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }

        InstallLock::$held_here = false;
        $this->updates([]);
        $this->assertFalse(InstallLock::$held_here, 'a hand run never counts as the installer');
        $this->assertFalse(InstallLock::isHeld(), 'and leaves no lock behind');
    }

    private function updates(array $params): \OWA\Module\Base\Controller\CoreUpdateSchemaCli
    {
        // Built without its constructor, which defines OWA_UPDATING for the rest of the process.
        $cli = (new ReflectionClass(\OWA\Module\Base\Controller\CoreUpdateSchemaCli::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(\OWA\Core\Controller\Cli::class, '__construct'))->invoke($cli, $params);

        ob_start();
        try {
            $cli->action();
        } finally {
            ob_end_clean();
        }

        return $cli;
    }
}
