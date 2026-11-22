<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\AutoUpdate\Finish;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\AutoUpdate\InstallNotices;
use OWA\Module\Base\Classes\AutoUpdate\Staging;

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

    /** install.json and backup/, and the slot when it holds this release or an older one; a newer one staged meanwhile stays. */
    public function testTheCleanUp(): void
    {
        $s    = new Staging($this->tmp . '/updates');
        $slot = function (string $v) use ($s): void {
            @mkdir($s->releaseDir(), 0700, true);
            file_put_contents($s->releaseDir() . '/release.json', json_encode(['version' => $v]));
            $s->writeStaged($v, null, str_repeat('a', 64));
        };

        $slot('2.0.3');
        mkdir($this->tmp . '/updates/backup', 0700, true);
        file_put_contents($this->tmp . '/updates/install.json', '{"state":"installing","version":"2.0.3"}');

        Finish::cleanup($s, '2.0.3');
        $this->assertFileDoesNotExist($this->tmp . '/updates/install.json');
        $this->assertDirectoryDoesNotExist($this->tmp . '/updates/backup');
        $this->assertNull($s->staged());
        $this->assertDirectoryDoesNotExist($s->releaseDir());

        $slot('2.0.4');
        Finish::cleanup($s, '2.0.3');
        $this->assertSame('2.0.4', $s->staged()['version'], 'a newer release staged meanwhile stays');
        $this->assertTrue($s->isStaged('2.0.4'));

        // 2.0.5 installed by hand before 2.0.4's window.
        Finish::cleanup($s, '2.0.5');
        $this->assertNull($s->staged(), 'an older release goes');
    }

    /** A warning at the first failure, critical when the weekly back-off starts, then weekly reminders. */
    public function testTheNoticesOfAFailingInstall(): void
    {
        $raised = [];
        $notify = function (array $n) use (&$raised) { $raised[$n['source_key']] = $n['severity']; };
        $install = ['state' => 'installing', 'version' => '2.0.3', 'error' => 'the database went away', 'retry_after' => 1800000000];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $attempts) {
            InstallNotices::forInstall(['attempts' => $attempts] + $install, $notify);
        }

        $this->assertSame([
            'install_failed:2.0.3'  => 'warning',
            'install_stuck:2.0.3'   => 'critical',
            'install_stuck:2.0.3:8' => 'critical',
            'install_stuck:2.0.3:9' => 'critical',
        ], $raised, 'the weekly back-off starts at the seventh failed attempt');

        $raised = [];
        InstallNotices::forInstall(['attempts' => 1, 'error' => null] + $install, $notify);
        $this->assertSame([], $raised, 'nothing failed yet');

        // What the notice says depends on whether the new release had booted.
        $bodies = [];
        $keep   = function (array $n) use (&$bodies) { $bodies[] = $n['body']; };
        InstallNotices::forInstall(['attempts' => 1, 'booted' => false] + $install, $keep);
        InstallNotices::forInstall(['attempts' => 1, 'booted' => true] + $install, $keep);
        InstallNotices::forInstall(['attempts' => 7, 'booted' => false] + $install, $keep);
        $this->assertStringContainsString('the installed release runs as before', $bodies[0], 'undone: the old release runs');
        $this->assertStringNotContainsString('tracking is queued', $bodies[0]);
        $this->assertStringContainsString('tracking is queued and reports wait', $bodies[1], 'booted: the schema is behind');
        $this->assertStringContainsString('php cli.php cmd=core-update-install', $bodies[2]);
        $this->assertStringNotContainsString('cmd=core-update-schema;', $bodies[2], 'cmd=core-update-schema cannot finish an install that was undone');
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
