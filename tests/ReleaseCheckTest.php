<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\ReleaseManifest;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\AutoUpdate\Preflight;
use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;
use OWA\Module\Base\Classes\AutoUpdate\Releases;
use OWA\Module\Base\Classes\AutoUpdate\Staging;
use OWA\Module\Base\Classes\AutoUpdate\Tar;
use OWA\Module\Base\Classes\AutoUpdate\Version;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Step 1: release check (Classes\AutoUpdate): which release is chosen, how it
 * is downloaded, proved, unpacked and judged, and what staged.json says
 * after. Against real tarballs GNU tar builds, served by Releases::$fetcher,
 * and an installed tree in a temporary directory outside any checkout.
 */
final class ReleaseCheckTest extends TestCase
{
    private string $tmp = '';

    /** @var array<string, string> url => file served */
    private array $served = [];

    /** @var list<int> releases each feed was given */
    private array $feeds = [];

    /** @var array<string, array<string, mixed>> source_key => notice, each raised once as notify() does */
    private array $notices = [];

    /** @var array<string, int> source_key => when first raised */
    private array $raised = [];

    /** @var array<string, int> module => stored schema */
    private array $schema = ['base' => 95];

    /** @var array<string, array<string, mixed>> version => more for its release.json */
    private array $releaseJson = [];

    private int $now = 1800000000;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/owa-release-check-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/owa', 0755, true);
        mkdir($this->tmp . '/data', 0755, true);
        file_put_contents($this->tmp . '/owa/cli.php', 'installed');

        InstallLock::$path = $this->tmp . '/data/updating';
        $this->served      = [];
        Releases::$fetcher = function ($url, $timeout, $sink) {
            if (!isset($this->served[$url])) {
                throw new \RuntimeException("Fetching $url failed: 404");
            }
            if ($sink === null) {
                return (string) file_get_contents($this->served[$url]);
            }
            copy($this->served[$url], $sink);

            return '';
        };
    }

    protected function tearDown(): void
    {
        Releases::$fetcher = null;
        InstallLock::$path = null;
        exec('chmod -R u+w ' . escapeshellarg($this->tmp) . '; rm -rf ' . escapeshellarg($this->tmp));
    }

    public function testVersions(): void
    {
        $this->assertSame([2, 0, 13], Version::parse('2.0.13'));
        $this->assertNull(Version::parse('v2.0.1'));
        $this->assertNull(Version::parse('2.0'));
        $this->assertNull(Version::parse('2.0.1-rc1'));
        $this->assertSame(1, Version::compare('2.0.10', '2.0.9'), 'numerically, not as strings');
        $this->assertSame('patch', Version::step('2.0.1', '2.0.2'));
        $this->assertSame('minor', Version::step('2.0.9', '2.1.0'));
        $this->assertSame('major', Version::step('2.9.9', '3.0.0'));
        $this->assertNull(Version::step('2.1.0', '2.0.9'));
    }

    public function testTheHighestEligibleReleaseIsChosen(): void
    {
        $list = [
            $this->listed('2.1.0'),
            $this->listed('3.0.0'),
            $this->listed('2.0.9') + ['draft' => true],
            $this->listed('2.0.10') + ['prerelease' => true],
            $this->listed('2.0.4'),
            $this->listed('2.0.7'),
            $this->listed('1.14.20'),
            ['tag_name' => '2.0.8', 'assets' => []],
            $this->listed('2.0.3'),
        ];

        $this->assertSame('2.1.0', Releases::choose($list, '2.0.3', 'minor')['version'], 'not 3.0.0: a major is never automatic');
        $this->assertSame('2.0.7', Releases::choose($list, '2.0.3', 'patch')['version'],
            'not the draft, the pre-release, or 2.0.8 which ships no tarball');
        $this->assertNull(Releases::choose($list, '2.1.0', 'minor'), 'nothing newer');

        $chosen = Releases::choose([$this->listed('2.0.4')], '2.0.3', 'minor');
        $this->assertSame(str_repeat('a', 64), $chosen['sha256']);
        $this->assertSame('patch', $chosen['step']);
    }

    public function testSha256SumsIsTheFallback(): void
    {
        $sums = str_repeat('b', 64) . "  owa_2.0.4_packaged.tar\n" . str_repeat('c', 64) . " *other.tar\n";

        $this->assertSame(str_repeat('b', 64), Releases::sumFor($sums, 'owa_2.0.4_packaged.tar'));
        $this->assertSame(str_repeat('c', 64), Releases::sumFor($sums, 'other.tar'));
        $this->assertNull(Releases::sumFor($sums, 'missing.tar'));
    }

    /** As the Release Packager builds it: members named ./..., GNU format, names over 100 characters. */
    public function testTheTarReaderUnpacksAPackagerArchive(): void
    {
        $src  = $this->tmp . '/src';
        $deep = $src . '/' . str_repeat('directory/', 12) . 'file.php';
        mkdir(dirname($deep), 0755, true);
        file_put_contents($deep, 'deep');
        file_put_contents($src . '/cli.php', 'cli');
        chmod($src . '/cli.php', 0700);
        mkdir($src . '/empty');

        exec(sprintf('tar --format=gnu -C %s -cf %s/a.tar ./ 2>&1', escapeshellarg($src), escapeshellarg($this->tmp)));
        mkdir($this->tmp . '/out');

        $this->assertSame(2, Tar::extract($this->tmp . '/a.tar', $this->tmp . '/out'));
        $this->assertSame('deep', file_get_contents(str_replace($src, $this->tmp . '/out', $deep)));
        $this->assertDirectoryExists($this->tmp . '/out/empty');
        $this->assertSame('0700', sprintf('%04o', fileperms($this->tmp . '/out/cli.php') & 0777));

        exec(sprintf('tar --format=posix -C %s -cf %s/p.tar . 2>&1', escapeshellarg($src), escapeshellarg($this->tmp)));
        mkdir($this->tmp . '/out2');
        $this->assertSame(2, Tar::extract($this->tmp . '/p.tar', $this->tmp . '/out2'), 'pax headers too');
    }

    public function testTheTarReaderRefusesWhatAReleaseDoesNotShip(): void
    {
        mkdir($this->tmp . '/src');
        symlink('/etc/passwd', $this->tmp . '/src/link');
        exec(sprintf('tar -C %s -cf %s/l.tar . 2>&1', escapeshellarg($this->tmp . '/src'), escapeshellarg($this->tmp)));

        foreach ([['l.tar', 'not a plain file'], ['dots.tar', 'unsafe path'], ['abs.tar', 'unsafe path']] as [$tar, $why]) {
            if ($tar === 'dots.tar') {
                file_put_contents($this->tmp . '/' . $tar, $this->member('./a/../../escape.php', 'x') . str_repeat("\0", 1024));
            } elseif ($tar === 'abs.tar') {
                file_put_contents($this->tmp . '/' . $tar, $this->member('/tmp/escape.php', 'x') . str_repeat("\0", 1024));
            }
            @mkdir($this->tmp . '/o-' . $tar);

            try {
                Tar::extract($this->tmp . '/' . $tar, $this->tmp . '/o-' . $tar);
                $this->fail("$tar was unpacked");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage(), $tar);
            }
        }

        $this->assertFileDoesNotExist($this->tmp . '/escape.php');
        file_put_contents($this->tmp . '/cut.tar', substr($this->member('./a.php', str_repeat('x', 2000)), 0, 1200));
        mkdir($this->tmp . '/o-cut');
        $this->expectExceptionMessage('ends inside a member');
        Tar::extract($this->tmp . '/cut.tar', $this->tmp . '/o-cut');
    }

    /** Listed, downloaded, proved, unpacked, judged: staged.json points at it with its window. */
    public function testAReleaseIsStaged(): void
    {
        $result = $this->check(['2.1.0'], ['level' => 'minor']);

        $this->assertSame(ReleaseCheck::STAGED, $result['outcome'], implode("\n", $result['lines']));
        $staging = $this->staging();
        $this->assertSame('2.1.0', $staging->staged()['version']);
        $this->assertSame(['days' => [6, 7], 'start' => '02:00', 'end' => '06:00', 'timezone' => 'Europe/London'],
            $staging->staged()['window'], 'a minor waits for the window');

        $info = $staging->stageInfo('2.1.0');
        $this->assertTrue($info['manifest_checked']);
        $this->assertTrue($info['preflight']['passed']);
        $this->assertSame(hash_file('sha256', $this->served['https://example.test/2.1.0.tar']), $info['tarball_sha256']);
        $this->assertSame('new cli', file_get_contents($staging->releaseDir('2.1.0') . '/cli.php'));
        $this->assertSame('0700', sprintf('%04o', fileperms($staging->root()) & 0777));
        $this->assertSame('0600', sprintf('%04o', fileperms($staging->root() . '/staged.json') & 0777));

        $again = $this->check(['2.1.0'], ['level' => 'minor']);
        $this->assertSame(ReleaseCheck::STAGED, $again['outcome']);
        $this->assertStringContainsString('as it was', implode("\n", $again['lines']), 'not downloaded again');
    }

    public function testAPatchHasNoWindowWhilePatchesInstallImmediately(): void
    {
        $this->check(['2.0.1']);

        $this->assertNull($this->staging()->staged()['window']);
    }

    public function testATarballThatDoesNotMatchItsDigestIsDeleted(): void
    {
        $result = $this->check(['2.0.1'], [], function ($r) {
            $r['assets'][0]['digest'] = 'sha256:' . str_repeat('0', 64);

            return $r;
        });

        $this->assertSame(ReleaseCheck::FAILED, $result['outcome']);
        $this->assertStringContainsString('does not match its SHA-256', implode("\n", $result['problems']));
        $this->assertDirectoryDoesNotExist($this->staging()->dir('2.0.1'));
        $this->assertNull($this->staging()->staged());
    }

    public function testAFileThatDoesNotMatchTheManifestFailsTheStaging(): void
    {
        $result = $this->check(['2.0.1'], [], null, fn ($dir) => file_put_contents($dir . '/cli.php', 'tampered'));

        $this->assertSame(ReleaseCheck::FAILED, $result['outcome']);
        $this->assertStringContainsString('does not match its manifest: 1 changed, such as cli.php', implode("\n", $result['problems']));
        $this->assertDirectoryDoesNotExist($this->staging()->dir('2.0.1'));
    }

    public function testAnUnmetRequirementKeepsTheStagingButNotStagedJson(): void
    {
        $this->check(['2.0.1']);
        $result = $this->check(['2.0.1'], ['env' => ['php' => '8.1.0'] + $this->env()]);

        $this->assertSame(ReleaseCheck::UNMET, $result['outcome']);
        $this->assertNull($this->staging()->staged());
        $this->assertTrue($this->staging()->isStaged('2.0.1'), 'judged again next run, from disk');
        $this->assertFalse($this->staging()->stageInfo('2.0.1')['preflight']['passed']);
        $this->assertStringContainsString('PHP 8.2 or later; the CLI runs PHP 8.1.0', implode("\n", $result['problems']));

        $this->assertSame(ReleaseCheck::STAGED, $this->check(['2.0.1'])['outcome'], 'and staged once the server meets it');
    }

    public function testANewerReleaseReplacesTheStagedOne(): void
    {
        $this->check(['2.0.1']);
        $result = $this->check(['2.0.1', '2.0.2']);

        $this->assertSame('2.0.2', $this->staging()->staged()['version']);
        $this->assertDirectoryDoesNotExist($this->staging()->dir('2.0.1'));
        $this->assertStringContainsString('Deleted the staging of 2.0.1, which 2.0.2 replaces', implode("\n", $result['lines']));
    }

    public function testAStagingTheInstallerClaimedIsLeftAlone(): void
    {
        $this->check(['2.0.1']);
        file_put_contents($this->staging()->dir('2.0.1') . '/install.json', '{"state":"installing"}');
        $before = file_get_contents($this->staging()->dir('2.0.1') . '/stage.json');

        $this->assertSame(ReleaseCheck::CLAIMED, $this->check(['2.0.1'])['outcome']);
        $this->assertSame($before, file_get_contents($this->staging()->dir('2.0.1') . '/stage.json'));

        $this->check(['2.0.1', '2.0.2']);
        $this->assertSame('2.0.2', $this->staging()->staged()['version']);
        $this->assertDirectoryExists($this->staging()->dir('2.0.1'), 'its install still retries from it');
    }

    public function testInstalledVersionsAreClearedAndStagedJsonRepaired(): void
    {
        $this->check(['2.0.1']);

        $result = $this->check([], ['installed' => '2.0.1']);
        $this->assertSame(ReleaseCheck::CURRENT, $result['outcome']);
        $this->assertDirectoryDoesNotExist($this->staging()->dir('2.0.1'));
        $this->assertNull($this->staging()->staged());

        $this->check(['2.0.2']);
        Staging::remove($this->staging()->dir('2.0.2'));
        $this->assertSame(ReleaseCheck::UNREACHED, $this->check(null)['outcome']);
        $this->assertNull($this->staging()->staged(), 'it named a staging that is gone');
    }

    public function testNothingChangesWhileAnInstallHoldsTheLock(): void
    {
        $this->check(['2.0.1']);
        $h = fopen(InstallLock::$path, 'c');
        flock($h, LOCK_EX);

        try {
            $this->assertSame(ReleaseCheck::BUSY, $this->check(['2.0.1', '2.0.2'])['outcome']);
            $this->assertSame('2.0.1', $this->staging()->staged()['version']);
            $this->assertDirectoryExists($this->staging()->dir('2.0.1'));
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public function testNoAutomaticUpdateFromACheckoutOrWhenOff(): void
    {
        $this->check(['2.0.1']);
        file_put_contents($this->tmp . '/owa/.git', 'gitdir: elsewhere');

        $result = $this->check(['2.0.1', '2.0.2']);
        $this->assertSame(ReleaseCheck::CHECKOUT, $result['outcome']);
        $this->assertStringContainsString('/owa/.git', implode("\n", $result['lines']), 'a .git file counts, as a worktree has');
        $this->assertNull($this->staging()->staged());

        unlink($this->tmp . '/owa/.git');
        $this->check(['2.0.1']);
        $this->assertSame(ReleaseCheck::OFF, $this->check(['2.0.1'], ['enabled' => false])['outcome']);
        $this->assertNull($this->staging()->staged());

        $this->assertSame(ReleaseCheck::ROLE, $this->check(['2.0.1'], ['role' => 'collector'])['outcome']);
    }

    /** The bell's release feed comes from the listing on every run: off, a checkout or another role. */
    public function testTheBellIsFedWhateverFollows(): void
    {
        file_put_contents($this->tmp . '/owa/.git', 'gitdir: elsewhere');
        $this->check(['2.0.1']);
        $this->check(['2.0.1'], ['enabled' => false]);
        $this->check(['2.0.1'], ['role' => 'worker']);
        $this->assertSame([1, 1, 1], $this->feeds);

        $this->check(null);
        $this->check(['2.0.1'], [], null, null, true);
        $this->assertSame([1, 1, 1], $this->feeds, 'not when the listing fails, nor on a dry run');
    }

    public function testStagingIsAnnouncedOnce(): void
    {
        $this->check(['2.1.0']);
        $this->check(['2.1.0']);

        $this->assertSame(['staged:2.1.0'], array_keys($this->notices));
        $n = $this->notices['staged:2.1.0'];
        $this->assertSame('OWA 2.1.0 will be installed between 02:00 and 06:00 (Europe/London), Sat, Sun', $n['title']);
        $this->assertSame(['auto_update', 'info', true], [$n['source'], $n['severity'], $n['email']]);
        $this->assertStringContainsString('cmd=self-update-cancel', $n['body']);

        $this->check(['2.0.1'], ['installed' => '2.0.0', 'level' => 'patch']);
        $this->assertSame('OWA 2.0.1 will be installed at the next scheduler run', $this->notices['staged:2.0.1']['title']);
    }

    public function testAnUnmetRequirementIsAnnouncedOncePerSetOfProblems(): void
    {
        $old = ['php' => '8.1.0'] + $this->env();
        $this->check(['2.0.1'], ['env' => $old]);
        $this->check(['2.0.1'], ['env' => $old]);
        $this->check(['2.0.1'], ['env' => ['database' => '8.0.11'] + $old]);

        $unmet = array_values(array_filter(array_keys($this->notices), fn ($k) => str_starts_with($k, 'unmet:2.0.1:')));
        $this->assertCount(2, $unmet, 'a new problem is a new notice; the same one is not repeated');
        $this->assertSame('warning', $this->notices[$unmet[0]]['severity']);
        $this->assertStringContainsString('PHP 8.2 or later', $this->notices[$unmet[0]]['body']);
    }

    public function testAFailingDownloadIsInTheBellThenMailedAfterAWeek(): void
    {
        $bad = function ($r) {
            $r['assets'][0]['digest'] = 'sha256:' . str_repeat('0', 64);

            return $r;
        };

        $this->check(['2.0.1'], [], $bad);
        $this->assertSame(['staging_failing:2.0.1'], array_keys($this->notices));
        $this->assertFalse($this->notices['staging_failing:2.0.1']['email'], 'the bell only, at first');

        $this->now += ReleaseCheck::FAILING_FOR - 1;
        $this->check(['2.0.1'], ['now' => $this->now], $bad);
        $this->assertArrayNotHasKey('staging_failed:2.0.1', $this->notices);

        $this->now += 1;
        $this->check(['2.0.1'], ['now' => $this->now], $bad);
        $this->assertTrue($this->notices['staging_failed:2.0.1']['email']);
        $this->assertSame('warning', $this->notices['staging_failed:2.0.1']['severity']);
    }

    /** An update this install would run that must be installed by hand: staged, announced, never staged.json. */
    public function testAnUpdateMarkedForAManualInstall(): void
    {
        $this->releaseJson['2.1.0'] = ['modules' => ['base' => 97], 'updates' => [
            ['module' => 'base', 'sequence' => 95, 'manual_install' => 'Already run here.'],
            ['module' => 'base', 'sequence' => 96, 'manual_install' => ''],
            ['module' => 'base', 'sequence' => 97, 'manual_install' => 'It rewrites every event. Back up the data tables first: php cli.php cmd=backup scope=data'],
            ['module' => 'gone', 'sequence' => 1, 'manual_install' => 'A module this install does not have.'],
        ]];

        $result = $this->check(['2.1.0']);

        $this->assertSame(ReleaseCheck::MANUAL, $result['outcome']);
        $this->assertNull($this->staging()->staged());
        $this->assertTrue($this->staging()->isStaged('2.1.0'));
        $this->assertSame([['module' => 'base', 'sequence' => 97, 'manual_install' => 'It rewrites every event. Back up the data tables first: php cli.php cmd=backup scope=data']],
            $this->staging()->stageInfo('2.1.0')['manual_install'], 'only what this install would run');
        $this->assertStringContainsString('cmd=self-update-install version=2.1.0', $this->notices['manual:2.1.0']['body']);
        $this->assertStringContainsString('base update 97: It rewrites every event', $this->notices['manual:2.1.0']['body']);

        $this->schema['base'] = 97;
        $this->assertSame(ReleaseCheck::STAGED, $this->check(['2.1.0'])['outcome'], 'once run by hand there is nothing manual left');
    }

    /** By hand: staged and judged, staged.json untouched, kept by the cleanup though it is older. */
    public function testAReleaseIsStagedByHand(): void
    {
        $result = $this->hand('2.0.1', ['2.0.1', '2.0.2']);

        $this->assertSame(ReleaseCheck::STAGED, $result['outcome'], implode("\n", $result['lines']));
        $this->assertNull($this->staging()->staged(), 'not scheduled');
        $this->assertTrue($this->staging()->stageInfo('2.0.1')['by_hand']);
        $this->assertStringContainsString('cmd=self-update-install version=2.0.1', implode("\n", $result['lines']));
        $this->assertSame([], $this->notices, 'the operator is reading the output');

        $this->check([], ['installed' => '2.0.2']);
        $this->assertDirectoryExists($this->staging()->dir('2.0.1'), 'an older staging made by hand is how a downgrade is prepared');
    }

    public function testAnOlderReleaseByHandListsTheRollbacksFirst(): void
    {
        $this->releaseJson['2.0.1'] = ['modules' => ['base' => 93]];
        $result = $this->hand('2.0.1', ['2.0.1'], ['installed' => '2.0.4']);

        $this->assertSame(ReleaseCheck::UNMET, $result['outcome']);
        $this->assertSame(['php cli.php cmd=update rollback=base.095', 'php cli.php cmd=update rollback=base.094'], $result['problems']);
        $this->assertStringContainsString('installing it goes back', implode("\n", $result['lines']));
    }

    public function testByHandRefusesAMajorACheckoutAndAClaimedStaging(): void
    {
        $this->assertStringContainsString('another major version', implode("\n", $this->hand('3.0.0', ['3.0.0'])['lines']));
        $this->assertStringContainsString('no published release 2.0.9', implode("\n", $this->hand('2.0.9', ['2.0.1'])['lines']));

        $this->hand('2.0.1', ['2.0.1']);
        file_put_contents($this->staging()->dir('2.0.1') . '/install.json', '{"state":"installing","copied":true}');
        mkdir($this->staging()->dir('2.0.1') . '/backup');
        $this->assertSame(ReleaseCheck::CLAIMED, $this->hand('2.0.1', ['2.0.1'])['outcome']);
        $this->assertDirectoryExists($this->staging()->dir('2.0.1') . '/backup', 'never replaced: it may restore a partly copied install');

        file_put_contents($this->tmp . '/owa/.git', 'gitdir: elsewhere');
        $this->assertSame(ReleaseCheck::CHECKOUT, $this->hand('2.0.2', ['2.0.2'])['outcome']);
    }

    public function testCancel(): void
    {
        $c = fn (array $ctx = []) => new ReleaseCheck($this->staging(), $ctx + ['root' => $this->tmp . '/owa', 'enabled' => true]);

        $this->assertStringContainsString('Nothing is staged', implode("\n", $c()->cancel()['lines']));

        $this->check(['2.0.1']);
        $this->assertStringContainsString('The next release check stages it again', implode("\n", $c()->cancel()['lines']));
        $this->assertNull($this->staging()->staged());
        $this->assertDirectoryExists($this->staging()->dir('2.0.1'));

        $this->check(['2.0.1']);
        $c()->cancel('2.0.1');
        $this->assertNull($this->staging()->staged());
        $this->assertDirectoryDoesNotExist($this->staging()->dir('2.0.1'));

        $this->check(['2.0.1']);
        $h = fopen(InstallLock::$path, 'c');
        flock($h, LOCK_EX);
        try {
            $this->assertSame(ReleaseCheck::BUSY, $c()->cancel()['outcome']);
            $this->assertNotNull($this->staging()->staged());
            $this->assertTrue(InstallLock::isHeld());
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
        $this->assertFalse(InstallLock::isHeld());
    }

    public function testStatus(): void
    {
        $this->check(['2.0.1']);
        $this->check(['2.1.0'], ['env' => ['php' => '8.1.0'] + $this->env()]);

        $lines = implode("\n", (new ReleaseCheck($this->staging(), [
            'root' => $this->tmp . '/owa', 'installed' => '2.0.0', 'enabled' => true, 'level' => 'minor',
            'window' => '02:00-06:00', 'days' => '', 'immediate' => true, 'timezone' => 'UTC',
        ]))->status());

        $this->assertStringContainsString('Automatic updates: patch and minor releases, starting between 02:00 and 06:00 (UTC), any day; patches install as soon as they are staged.', $lines);
        $this->assertStringContainsString('Staged to install: nothing.', $lines, '2.1.0 is the one chosen, and it cannot be installed');
        $this->assertStringContainsString('can be installed.', $lines);

        $this->check(['2.0.1']);
        $this->assertStringContainsString('Staged to install: 2.0.1, at the next scheduler run.', implode("\n", (new ReleaseCheck($this->staging(),
            ['root' => $this->tmp . '/owa', 'installed' => '2.0.0', 'enabled' => true]))->status()));
        $this->assertStringContainsString('2.1.0: downloaded', $lines);
        $this->assertStringContainsString('cannot be installed yet', $lines);
        $this->assertStringContainsString('It needs PHP 8.2 or later', $lines);
    }

    public function testADryRunWritesNothing(): void
    {
        $result = $this->check(['2.0.1'], [], null, null, true);

        $this->assertSame(ReleaseCheck::CHOSEN, $result['outcome']);
        $this->assertSame('2.0.1', $result['version']);
        $this->assertDirectoryDoesNotExist($this->tmp . '/data/updates');
    }

    public function testRequirements(): void
    {
        $req = \OWA\Core\Requirements::toArray();
        $env = $this->env();

        $this->assertSame([], Preflight::requirements($req, $env));

        $bad = Preflight::requirements($req, [
            'extensions' => ['json', 'pcre', 'zlib'],
            'database'   => '8.0.11',
            'constants'  => ['OWA_DB_HOST' => null, 'OWA_NONCE_KEY' => 'short'] + $env['constants'],
        ] + $env);

        $this->assertStringContainsString("PHP's mbstring extension", implode("\n", $bad));
        $this->assertStringContainsString('database drivers', implode("\n", $bad));
        $this->assertStringContainsString('MySQL 8.0.11', implode("\n", $bad));
        $this->assertStringContainsString('OWA_DB_HOST defined', implode("\n", $bad));
        $this->assertStringContainsString('OWA_NONCE_KEY to be at least 32', implode("\n", $bad));
        $this->assertSame(['The release does not say what it requires.'], Preflight::requirements([], $env));
    }

    public function testInstallConditions(): void
    {
        $root = $this->tmp . '/owa';
        mkdir($this->tmp . '/data/updates/2.0.1', 0700, true);
        chmod($this->tmp . '/data/updates', 0700);
        $manifest = ['files' => ['cli.php' => ['size' => 3], 'new/dir/file.php' => ['size' => 3]]];

        $this->assertSame([], Preflight::install($this->tmp . '/data/updates/2.0.1', $manifest, $root));

        chmod($root, 02775);
        $this->assertSame([], Preflight::install($this->tmp . '/data/updates/2.0.1', $manifest, $root),
            'a group-writable OWA directory is a setgid web group, not a problem');
        chmod($root, 0755);

        chmod($this->tmp . '/data/updates/2.0.1', 0770);
        $this->assertStringContainsString('2.0.1 can be written by users other than its owner',
            implode("\n", Preflight::install($this->tmp . '/data/updates/2.0.1', $manifest, $root)), 'the staging is what runs');
        chmod($this->tmp . '/data/updates/2.0.1', 0700);

        chmod($root . '/cli.php', 0444);
        $this->assertStringContainsString('1 of the files the release replaces or adds cannot be written',
            implode("\n", Preflight::install($this->tmp . '/data/updates/2.0.1', $manifest, $root)));
    }

    // ---------------------------------------------------------------------

    private function staging(): Staging
    {
        return new Staging($this->tmp . '/data/updates');
    }

    /**
     * One run of ReleaseCheck against listed releases built here.
     *
     * @param list<string>|null $versions released, null for a listing that fails
     */
    private function check(?array $versions, array $context = [], ?callable $edit = null, ?callable $tamper = null, bool $dry = false): array
    {
        $list = null;

        if ($versions !== null) {
            $list = [];
            foreach ($versions as $v) {
                $tar = $this->build($v, $tamper);
                $this->served["https://example.test/$v.tar"] = $tar;
                $r = $this->listed($v, 'https://example.test/' . $v . '.tar', hash_file('sha256', $tar));
                $list[] = $edit ? $edit($r) : $r;
            }
        }

        $check = new ReleaseCheck($this->staging(), $context + [
            'root'      => $this->tmp . '/owa',
            'installed' => '2.0.0',
            'role'      => \OWA\Core\ServerRole::FULL,
            'enabled'   => true,
            'level'     => 'minor',
            'days'      => 'sat, sun',
            'window'    => '02:00-06:00',
            'immediate' => true,
            'timezone'  => 'Europe/London',
            'url'       => 'https://example.test/releases',
            'releases'  => $list,
            'env'       => $this->env(),
            'feed'      => function (array $list) { $this->feeds[] = count($list); return 0; },
            'notify'    => function (array $n) {
                if (!isset($this->notices[$n['source_key']])) {
                    $this->notices[$n['source_key']] = $n;
                    $this->raised[$n['source_key']] = $this->now;
                }
            },
            'raised_at' => fn ($key) => $this->raised[$key] ?? null,
            'schema'    => fn ($module) => $this->schema[$module] ?? null,
            'now'       => $this->now,
        ]);

        if ($versions === null) {
            $check->context['releases'] = null;
            $this->served = [];
        }

        return $check->run($dry);
    }

    /** ReleaseCheck::stageByHand() against listed releases built here. */
    private function hand(string $version, array $versions, array $context = []): array
    {
        $list = [];
        foreach ($versions as $v) {
            $tar = $this->build($v, null);
            $this->served["https://example.test/$v.tar"] = $tar;
            $list[] = $this->listed($v, 'https://example.test/' . $v . '.tar', hash_file('sha256', $tar));
        }

        return (new ReleaseCheck($this->staging(), $context + [
            'root'      => $this->tmp . '/owa',
            'installed' => '2.0.0',
            'releases'  => $list,
            'env'       => $this->env(),
            'notify'    => function (array $n) { $this->notices[$n['source_key']] = $n; },
            'schema'    => fn ($module) => $this->schema[$module] ?? null,
            'now'       => $this->now,
        ]))->stageByHand($version);
    }

    /** A release tarball as the packager makes it: release.json and manifest.json inside, members ./... */
    private function build(string $v, ?callable $tamper): string
    {
        $dir = $this->tmp . "/build-$v";
        $tar = $this->tmp . "/owa_{$v}_packaged.tar";

        if (is_file($tar)) {
            return $tar;
        }

        mkdir($dir . '/modules/Base', 0755, true);
        file_put_contents($dir . '/cli.php', 'new cli');
        file_put_contents($dir . '/modules/Base/Module.php', "<?php // $v");
        file_put_contents($dir . '/release.json', json_encode(($this->releaseJson[$v] ?? []) + ['format' => 1, 'version' => $v,
            'requirements' => \OWA\Core\Requirements::toArray(), 'modules' => ['base' => 95], 'updates' => []]));
        file_put_contents($dir . '/manifest.json', json_encode(ReleaseManifest::build($dir)));

        if ($tamper) {
            $tamper($dir);
        }

        exec(sprintf('tar --format=gnu -C %s -cf %s ./ 2>&1', escapeshellarg($dir), escapeshellarg($tar)));

        return $tar;
    }

    private function listed(string $v, ?string $url = null, ?string $sha = null): array
    {
        return [
            'tag_name' => $v,
            'html_url' => "https://github.com/Open-Web-Analytics/Open-Web-Analytics/releases/tag/$v",
            'assets'   => [[
                'name'                 => "owa_{$v}_packaged.tar",
                'browser_download_url' => $url ?? "https://example.test/$v.tar",
                'size'                 => 10240,
                'digest'               => 'sha256:' . ($sha ?? str_repeat('a', 64)),
            ]],
        ];
    }

    private function env(): array
    {
        $constants = [];
        $req = \OWA\Core\Requirements::toArray()['config'];

        foreach (array_merge($req['database_constants'], $req['url_constants'], $req['secret_constants']) as $name) {
            $constants[$name] = str_repeat('k', 40);
        }

        return [
            'php'        => '8.2.20',
            'int_size'   => 8,
            'extensions' => ['json', 'mbstring', 'pcre', 'zlib', 'pdo_mysql'],
            'mysqlnd'    => true,
            'database'   => '8.4.10',
            'constants'  => $constants,
        ];
    }

    /** One ustar member, for archives GNU tar refuses to make. */
    private function member(string $name, string $data): string
    {
        $h = str_pad($name, 100, "\0") . str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', strlen($data)), 12, "\0") . str_pad(sprintf('%011o', 0), 12, "\0") . '        ' . '0'
            . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);

        return $h . str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\0");
    }
}
