<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\AutoUpdate\Finish;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\AutoUpdate\InstallNotices;
use OWA\Module\Base\Classes\AutoUpdate\Staging;

/**
 * Step 4: finish, the parts that run without a fresh process: the clean-up
 * after a successful install, the notices a failing one raises, and
 * cmd=update keeping out of an install's way.
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

    /** Its own staging, installs it superseded, staged.json only if it names it; never a hand staging or a newer one. */
    public function testTheCleanUp(): void
    {
        $s = new Staging($this->tmp . '/updates');

        foreach (['2.0.1' => true, '2.0.2' => false, '2.0.3' => true, '2.0.4' => false] as $v => $claimed) {
            mkdir($s->dir($v), 0700);
            if ($claimed) {
                file_put_contents($s->dir($v) . '/install.json', '{"state":"installing"}');
            }
        }

        $s->writeStaged('2.0.4', null);
        Finish::cleanup($s, '2.0.3');

        $this->assertSame(['2.0.2', '2.0.4'], $s->versions(), '2.0.1 was superseded; 2.0.2 is someone\'s staging, 2.0.4 newer');
        $this->assertSame('2.0.4', $s->staged()['version']);

        $s->writeStaged('2.0.4', null);
        Finish::cleanup($s, '2.0.4');
        $this->assertNull($s->staged());
    }

    /** A warning at the first failure, critical when the weekly back-off starts, then weekly reminders. */
    public function testTheNoticesOfAFailingInstall(): void
    {
        $raised = [];
        $notify = function (array $n) use (&$raised) { $raised[$n['source_key']] = $n['severity']; };
        $install = ['state' => 'installing', 'version' => '2.0.3', 'error' => 'the database went away', 'retry_after' => 1800000000];

        foreach ([1, 2, 3, 4, 5, 6] as $attempts) {
            InstallNotices::forInstall(['attempts' => $attempts] + $install, $notify);
        }

        $this->assertSame([
            'install_failed:2.0.3'  => 'warning',
            'install_stuck:2.0.3'   => 'critical',
            'install_stuck:2.0.3:5' => 'critical',
            'install_stuck:2.0.3:6' => 'critical',
        ], $raised);

        $raised = [];
        InstallNotices::forInstall(['attempts' => 1, 'error' => null] + $install, $notify);
        $this->assertSame([], $raised, 'nothing failed yet');
    }

    /** cmd=update takes the install lock: refused while an install holds it; the installer itself runs it. */
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
        $this->assertFalse(InstallLock::$held_here, 'released when it is done');
        $this->assertFalse(InstallLock::isHeld());
    }

    private function updates(array $params): \OWA\Module\Base\Controller\UpdatesApplyCli
    {
        // Built without its constructor, which defines OWA_UPDATING for the rest of the process.
        $cli = (new ReflectionClass(\OWA\Module\Base\Controller\UpdatesApplyCli::class))->newInstanceWithoutConstructor();
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
