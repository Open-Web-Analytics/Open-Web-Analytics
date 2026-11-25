<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\AutoUpdate\Finish;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\AutoUpdate\InstallNotices;
use OWA\Module\Base\Classes\AutoUpdate\Downloads;

/**
 * Finish, the parts that run without a fresh process: the clean-up
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
        InstallLock::$path         = null;
        InstallLock::$held_here    = false;
        InstallLock::$schema_lease = null;
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
        $this->failed($d, ['version' => '2.0.3', 'from' => '2.0.2', 'error' => 'an earlier attempt']);

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

        InstallNotices::forFailure(['attempts' => 1, 'retry_after' => 1800000000] + $failed, $notify);
        InstallNotices::forFailure(['attempts' => 3, 'retry_after' => null] + $failed, $notify);

        $this->assertCount(4, $raised, 'nothing for a record without an error');
        $this->assertSame('install_failed:2.0.3', $raised[2]['source_key'], 'tried again: the first failure\'s notice');
        $this->assertStringContainsString('try OWA 2.0.3 again after 2027-01-15 08:00 UTC, 3 attempts in all', $raised[2]['body']);
        $this->assertSame('install_failed:2.0.3:skipped', $raised[3]['source_key'], 'the last attempt failed: one more');
        $this->assertStringContainsString('Automatic updates skip OWA 2.0.3', $raised[3]['body']);
        $this->assertSame(['install_failed:2.0.3', 'warning', true], [$raised[0]['source_key'], $raised[0]['severity'], $raised[0]['email']]);
        $this->assertStringContainsString('The installed release runs as before. Automatic updates skip OWA 2.0.3', $raised[0]['body'], 'put back: the old release runs');
        $this->assertStringContainsString('php cli.php cmd=core-install --version=2.0.3', $raised[0]['body']);
        $this->assertStringContainsString('tracking is queued and reports wait', $raised[1]['body'], 'booted: the schema is behind');
        $this->assertStringContainsString('php cli.php cmd=core-update-schema', $raised[1]['body']);
    }

    /** cmd=core-update-schema by hand waits for an install and takes no lock of its own; the installer itself runs it. */
    public function testCoreUpdateSchemaWaitsForAnInstall(): void
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
            $this->assertSame('ok', $cli->getCliOutcome()['outcome'], 'the installer holds it already: the run goes ahead, ' . $cli->getCliOutcome()['message']);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }

        InstallLock::$held_here = false;
        $this->updates([]);
        $this->assertFalse(InstallLock::$held_here, 'a hand run never counts as the installer');
        $this->assertFalse(InstallLock::isHeld(), 'and leaves no lock behind');
    }

    /** A Finish killed during the schema updates left their lease held for an hour: the next takes it back, and no other run's. */
    public function testAKilledFinishsLeaseIsTakenBack(): void
    {
        $lock = \OWA\Module\Base\Controller\UpdatesApply::LOCK;
        $dead = new \OWA\Module\Base\Classes\JobLease($lock, 'aaaaaaaaaaaaaaaa');
        $this->assertTrue($dead->acquire(3600));
        $h = fopen(InstallLock::$path, 'c');
        flock($h, LOCK_EX);
        InstallLock::$held_here = true;

        try {
            InstallLock::$schema_lease = 'bbbbbbbbbbbbbbbb';
            $cli = $this->updates([]);
            $this->assertStringContainsString('Updates are already being applied by another run', $cli->getCliOutcome()['message'], 'another owner\'s lease stays');

            InstallLock::$schema_lease = 'aaaaaaaaaaaaaaaa';
            $cli = $this->updates([]);
            $this->assertStringNotContainsString('already being applied', $cli->getCliOutcome()['message'], 'the install\'s own is taken back');
            $this->assertEmpty(\OWA\Core\CoreAPI::dbSingleton()->getJobLock($lock), 'and let go after');
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
            $dead->release();
        }
    }

    /** Once nothing is pending, a hand run clears the record of the installed release's Finish that failed, and no other. */
    public function testAHandRunThatCompletesClearsTheFailedFinish(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', $this->tmp . '/updates');
        $d = new Downloads($this->tmp . '/updates');

        try {
            $this->failed($d, ['version' => '1.0.0', 'from' => '0.9.0', 'error' => 'another release', 'booted' => true]);
            $this->updates([]);
            $this->assertNotNull($d->failure(), 'another release\'s record stays');

            $this->failed($d, ['version' => \OWA\Core\Env::version(), 'from' => '0.9.0', 'error' => 'put back', 'booted' => false]);
            $this->updates([]);
            $this->assertNotNull($d->failure(), 'a release put back is not this one finishing');

            $this->failed($d, ['version' => \OWA\Core\Env::version(), 'from' => '0.9.0', 'error' => 'cmd=core-update-schema did not complete', 'booted' => true]);
            $this->updates([]);
            $this->assertNull($d->failure());
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', '');
        }
    }

    /** A failure record as updater.php `recordFailure` writes one, its fields as given. */
    private function failed(Downloads $d, array $failure): void
    {
        file_put_contents($d->root() . '/' . Downloads::FAILURE, json_encode(['format' => 1] + $failure));
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
