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
        InstallLock::$path = $this->tmp . '/owa-update-lock.php';
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

    /** A release passed over for its upgrades_from is kept by the clean-up, the highest only: the next release check may install it. */
    public function testTheCleanUpKeepsTheHighestNewerRelease(): void
    {
        $d      = new Downloads($this->tmp . '/updates');
        $tarred = function (string $v) use ($d): void {
            file_put_contents($d->tarball($v), 'a tarball');
            file_put_contents($d->tarball($v) . '.sig', 'a signature');
        };

        foreach (['2.1.0', '2.2.0', '3.0.0', '3.1.0'] as $v) {
            $tarred($v);
        }

        Finish::cleanup($d, '2.2.0');
        $this->assertSame(['3.1.0', '2.2.0'], $d->versions());
    }

    /** One notice per failed release; what it says depends on whether the new release had booted. */
    /**
     * A reverted install is noticed once it is not tried again; a broken one
     * at once. Each says what failed and why, reverted or broken and what
     * to do, and what the install did.
     */
    public function testTheNoticesOfAFailedInstall(): void
    {
        $raised = [];
        $notify = function (array $n) use (&$raised) { $raised[] = $n; };
        $log    = ['2026-11-25 10:00:00 UTC Release check (the scheduler\'s), OWA 2.0.2 installed.', '2026-11-25 10:00:01 UTC Copying OWA 2.0.3\'s files over 2.0.2\'s.'];
        $failed = ['version' => '2.0.3', 'from' => '2.0.2', 'stage' => 'copy', 'error' => 'Could not write Core/Env.php. OWA 2.0.2 was put back.', 'log' => $log];

        InstallNotices::forFailure(['attempts' => 1, 'retry_after' => 1800000000] + $failed, $notify);
        $this->assertSame([], $raised, 'tried again: no notice yet');
        $this->assertStringContainsString('The install was reverted: OWA 2.0.2 runs as before. It is tried again after 2027-01-15 08:00 UTC, 3 attempts in all',
            InstallNotices::describe(['retry_after' => 1800000000] + $failed)['lines'][1]);

        InstallNotices::forFailure(['error' => '', 'retry_after' => null] + $failed, $notify);
        $this->assertSame([], $raised, 'nothing for a record without an error');

        InstallNotices::forFailure(['attempts' => 3, 'retry_after' => null] + $failed, $notify);
        $this->assertCount(1, $raised);
        $this->assertSame(['install_failed:2.0.3', 'Installing OWA 2.0.3 failed', 'warning', true],
            [$raised[0]['source_key'], $raised[0]['title'], $raised[0]['severity'], $raised[0]['email']]);
        $this->assertStringStartsWith("Could not write Core/Env.php. OWA 2.0.2 was put back.\n\nThe install was reverted: OWA 2.0.2 runs as before. It is not tried again", $raised[0]['body']);
        $this->assertStringContainsString('php cli.php cmd=core-install --version=2.0.3', $raised[0]['body']);
        $this->assertStringEndsWith("What the install did:\n" . implode("\n", $log), $raised[0]['body'], 'where it stopped');

        foreach ([
            'schema'    => ['Its schema updates did not complete: Update 095 failed.', 'tracking is queued and reports wait', 'php cli.php cmd=core-update-schema'],
            'trackers'  => ['Republishing its tracking bundles failed: Update 095 failed.', 'its tracking bundles were not republished', 'php cli.php cmd=core-update-schema'],
            'self_test' => ['Its own release check failed: Update 095 failed.', 'it may not install the release that fixes this', 'php cli.php cmd=core-install --version=<version>'],
        ] as $step => [$what, $state, $todo]) {
            $raised = [];
            InstallNotices::forFailure(['booted' => true, 'stage' => 'finish', 'step' => $step, 'error' => 'Update 095 failed', 'retry_after' => null] + $failed, $notify);

            $this->assertCount(1, $raised, "broken at $step: at once");
            $this->assertSame(["install_broken:2.0.3:$step", 'critical', true], [$raised[0]['source_key'], $raised[0]['severity'], $raised[0]['email']], $step);
            $this->assertStringStartsWith($what . "\n\nThe install is broken and needs attention now: ", $raised[0]['body'], $step);
            $this->assertStringContainsString($state, $raised[0]['body'], $step);
            $this->assertStringContainsString($todo, $raised[0]['body'], $step);
        }
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

    /** Once nothing is pending, a hand run finishes the installed release's failed Finish -- its record and the old tarballs go -- and no other. */
    /**
     * A hand run of cmd=core-update-schema after a broken install runs the
     * rest of Finish: the tracking bundles (written here under a test
     * public_path), the self-test (its listing unreachable, as a network
     * failure it shrugs off), the notice and the clean-up; a step that fails
     * again is recorded at that step, the install's log carried on.
     */
    public function testAHandRunThatCompletesClearsTheFailedFinish(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', $this->tmp . '/updates');
        $was = [\OWA\Core\CoreAPI::getSetting('base', 'public_path'), \OWA\Core\CoreAPI::getSetting('base', 'auto_update_releases_url')];
        mkdir($this->tmp . '/public', 0755);
        \OWA\Core\CoreAPI::setSetting('base', 'public_path', $this->tmp . '/public');
        \OWA\Core\CoreAPI::setSetting('base', 'auto_update_releases_url', 'http://127.0.0.1:9/releases');
        $d = new Downloads($this->tmp . '/updates');

        try {
            $this->failed($d, ['version' => '1.0.0', 'from' => '0.9.0', 'error' => 'another release', 'booted' => true]);
            $this->updates([]);
            $this->assertNotNull($d->failure(), 'another release\'s record stays');

            $this->failed($d, ['version' => \OWA\Core\Env::version(), 'from' => '0.9.0', 'error' => 'put back', 'booted' => false]);
            $this->updates([]);
            $this->assertNotNull($d->failure(), 'a release put back is not this one finishing');

            $this->failed($d, ['version' => \OWA\Core\Env::version(), 'from' => '0.9.0', 'error' => 'cmd=core-update-schema did not complete', 'booted' => true]);
            file_put_contents($d->tarball('0.9.0'), 'the replaced release');
            file_put_contents($d->tarball('0.9.0') . '.sig', 'its signature');
            $this->updates([]);

            // On to the bundles, which publish only where the tracker is built (not every CI job builds it;
            // the e2e's finish-trackers-fail finishes it for real): complete, or broken there, never left as it was.
            $after = $d->failure();

            if ($after === null) {
                $this->assertFileDoesNotExist($d->tarball('0.9.0'), 'cleaned up as Finish would have');
            } else {
                $this->assertSame('trackers', $after['step'], 'the hand run carried on to the bundles');
                $this->assertStringContainsString('cmd=core-update-schema, by hand', $after['log'][0]);
            }

            // The bundles cannot be written: broken again, at the step after the schema updates.
            $this->failed($d, ['version' => \OWA\Core\Env::version(), 'from' => '0.9.0', 'error' => 'the bundles', 'booted' => true, 'step' => 'trackers', 'log' => ['earlier']]);
            array_map('unlink', glob($this->tmp . '/public/tracker/*.js') ?: []);
            @mkdir($this->tmp . '/public/tracker', 0755);
            chmod($this->tmp . '/public/tracker', 0555);
            $this->updates([]);
            chmod($this->tmp . '/public/tracker', 0755);
            $this->assertSame(['finish', 'trackers', true], [$d->failure()['stage'] ?? null, $d->failure()['step'] ?? null, $d->failure()['booted'] ?? null]);
            $this->assertSame('earlier', $d->failure()['log'][0], 'the install\'s log, carried on');
            $this->assertStringContainsString('Republishing its tracking bundles (cmd=publish-trackers).', implode("\n", $d->failure()['log']));
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', '');
            \OWA\Core\CoreAPI::setSetting('base', 'public_path', $was[0]);
            \OWA\Core\CoreAPI::setSetting('base', 'auto_update_releases_url', $was[1]);
            @chmod($this->tmp . '/public/tracker', 0755);
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
