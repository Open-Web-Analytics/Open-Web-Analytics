<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\AutoUpdate\Downloads;
use OWA\Module\Base\Classes\AutoUpdate\Install;
use OWA\Module\Base\Classes\AutoUpdate\InstallLock;
use OWA\Module\Base\Classes\AutoUpdate\Preflight;
use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;
use OWA\Module\Base\Classes\AutoUpdate\Releases;
use OWA\Module\Base\Classes\AutoUpdate\Signature;
use OWA\Module\Base\Classes\AutoUpdate\Tar;
use OWA\Module\Base\Classes\AutoUpdate\Version;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The release check (Classes\AutoUpdate): which release is chosen, how it is
 * downloaded, proved and judged, when it installs, and installing by hand
 * (Install::byHand()). The installed release, 2.0.0, is listed too: its
 * tarball is what a failed install puts back. An install is proved and
 * pending (Install::$pending), never copied: run() ends the process. Against real tarballs GNU tar builds, served by Releases::$fetcher,
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

    /** @var array<string, array<string, mixed>> version => more for its release.php */
    private array $releaseJson = [];

    private int $now = 1800000000;

    private string $signingKey = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/owa-release-check-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/owa', 0755, true);
        mkdir($this->tmp . '/data', 0755, true);
        file_put_contents($this->tmp . '/owa/cli.php', 'installed');
        copy(dirname(__DIR__) . '/updater.php', $this->tmp . '/owa/updater.php');
        \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', '');

        InstallLock::$path = $this->tmp . '/data/updating';
        $this->served      = [];

        // Releases signed as the packager signs them, by a key made for the test.
        $pair                  = sodium_crypto_sign_keypair();
        $this->signingKey      = base64_encode(sodium_crypto_sign_secretkey($pair));
        Signature::$trusted    = [base64_encode(sodium_crypto_sign_publickey($pair))];
        Releases::$fetcher = function ($url, $timeout, $sink) {
            if (!isset($this->served[$url])) {
                throw new \RuntimeException("Fetching $url failed: 404");
            }
            if ($sink === null) {
                return (string) file_get_contents($this->served[$url]);
            }
            $in = fopen($this->served[$url], 'rb');
            stream_copy_to_stream($in, $sink);
            fclose($in);

            return '';
        };
    }

    protected function tearDown(): void
    {
        $this->dropPending();
        Releases::$fetcher  = null;
        Signature::$trusted = null;
        InstallLock::$path  = null;
        \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', '');
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

    /** Listed, downloaded, proved and judged; outside its window it waits, with both tarballs kept. */
    public function testAReleaseIsDownloadedAndWaitsForItsWindow(): void
    {
        $result = $this->check(['2.1.0']);

        $this->assertSame(ReleaseCheck::WAITING, $result['outcome'], implode("\n", $result['lines']));
        $this->assertStringContainsString('2.1.0 installs between 02:00 and 06:00 (Europe/London), Sat, Sun.', implode("\n", $result['lines']));
        $d = $this->downloads();
        $this->assertSame('new cli', Tar::member($d->tarball('2.1.0'), 'cli.php'), 'the tarball itself, not unpacked');
        $this->assertTrue($d->has('2.0.0'), 'the installed release\'s tarball, which a failed install puts back');
        $this->assertSame(['owa_2.0.0_packaged.tar', 'owa_2.0.0_packaged.tar.sig', 'owa_2.1.0_packaged.tar', 'owa_2.1.0_packaged.tar.sig'],
            array_map('basename', glob($d->root() . '/*')), 'nothing half downloaded');
        $this->assertMatchesRegularExpression('#/data/updates-[0-9a-f]{32}$#', $d->root());
        $this->assertSame('0711', sprintf('%04o', fileperms($d->root()) & 0777));
        $this->assertSame('0600', sprintf('%04o', fileperms($d->tarball('2.1.0')) & 0777));
        $this->assertNull(Install::$pending);

        $again = $this->check(['2.1.0']);
        $this->assertSame(ReleaseCheck::WAITING, $again['outcome']);
        $this->assertStringNotContainsString('Downloaded', implode("\n", $again['lines']), 'not downloaded again');
    }

    /** Inside its window, or with none, it installs in this run: both tarballs proved, the copy waiting for the end of the process. */
    public function testAReleaseInstallsInItsWindow(): void
    {
        // 03:00 on a Saturday in London.
        $sat3   = (new DateTimeImmutable('2026-11-21 03:00', new DateTimeZone('Europe/London')))->getTimestamp();
        $result = $this->check(['2.1.0'], ['now' => $sat3]);

        $this->assertSame(ReleaseCheck::INSTALLING, $result['outcome'], implode("\n", $result['lines']));
        $this->assertSame(['2.0.0', '2.1.0'], [Install::$pending['from'], Install::$pending['to']]);
        $this->assertStringContainsString('the files are copied when this run has finished', implode("\n", $result['lines']));
        $this->assertTrue(InstallLock::isHeld() || Install::$pending['hold']['handle'] !== null, 'the install lock is taken');
        $this->dropPending();

        $this->assertSame(ReleaseCheck::INSTALLING, $this->check(['2.1.0'], ['window' => ''])['outcome'], 'no window: at once');
        $this->dropPending();
    }

    public function testWhatIsInTheWindow(): void
    {
        $sat3   = (new DateTimeImmutable('2026-11-21 03:00', new DateTimeZone('Europe/London')))->getTimestamp();
        $window = ['days' => [6, 7], 'start' => '02:00', 'end' => '06:00', 'timezone' => 'Europe/London'];
        $this->assertTrue(ReleaseCheck::inWindow($window, $sat3));
        $this->assertFalse(ReleaseCheck::inWindow($window, $sat3 + 3 * 3600), 'after the window');
        $this->assertFalse(ReleaseCheck::inWindow($window, $sat3 - 2 * 86400), 'not on a Thursday');

        // 23:00-02:00 starting Saturday: 01:00 Sunday is still Saturday's window.
        $late = ['days' => [6], 'start' => '23:00', 'end' => '02:00', 'timezone' => 'Europe/London'];
        $this->assertTrue(ReleaseCheck::inWindow($late, $sat3 + 22 * 3600), '01:00 Sunday');
        $this->assertFalse(ReleaseCheck::inWindow($late, $sat3 - 2 * 3600), '01:00 Saturday belongs to Friday');
    }

    /** The scheduler's hourly runs list the releases in four hours a day, six apart; the others install what was downloaded. */
    public function testTheScheduledRunsListFourTimesADay(): void
    {
        $check = fn (int $hour) => new ReleaseCheck($this->downloads(), ['scheduled' => true, 'seed' => 'https://owa.example.test/|core-update',
            'now' => gmmktime($hour, 17, 0, 11, 23, 2026)]);
        $hours = array_values(array_filter(range(0, 23), fn ($h) => $check($h)->listingHour()));

        $this->assertCount(4, $hours);
        $this->assertSame([6, 12, 18], [$hours[1] - $hours[0], $hours[2] - $hours[0], $hours[3] - $hours[0]]);

        // And a second of the minute, the same each day; a run by hand waits for nothing.
        $slept = [];
        $sleep = function ($s) use (&$slept) { $slept[] = $s; };
        $delay = $check(0)->listingDelay();
        $this->assertGreaterThanOrEqual(0, $delay);
        $this->assertLessThan(60, $delay);
        $delays = array_map(fn ($i) => (new ReleaseCheck($this->downloads(), ['seed' => "installation $i"]))->listingDelay(), range(1, 30));
        $this->assertGreaterThan(15, count(array_unique($delays)), 'installations wait different seconds');
        $this->check(['2.0.1'], ['scheduled' => true, 'seed' => 'https://owa.example.test/|core-update', 'now' => gmmktime($hours[0], 17, 0, 11, 23, 2026), 'sleep' => $sleep]);
        $this->check(['2.0.1'], ['sleep' => $sleep]);
        $this->assertSame([$delay], $slept);

        $quiet = ($hours[0] + 1) % 24;
        $result = $this->check(['2.0.1', '2.0.2'], ['scheduled' => true, 'seed' => 'https://owa.example.test/|core-update', 'now' => gmmktime($quiet, 17, 0, 11, 23, 2026)]);
        $this->assertFalse($this->downloads()->has('2.0.2'), 'not a listing hour: nothing listed, nothing downloaded');
        $this->assertSame([2, 2], $this->feeds, 'nor the bell fed: only the listing hour\'s run and the run by hand fed it');
        $this->assertSame('2.0.1', $result['version'], 'what was downloaded is still judged');
    }

    /** Every download goes through the download URL (the update host), never the address the listing gives; the listing's digest is still checked. */
    public function testDownloadsGoThroughTheDownloadUrl(): void
    {
        $asked = [];
        $serve = Releases::$fetcher;
        Releases::$fetcher = function ($url, $timeout, $sink) use (&$asked, $serve) {
            $asked[] = $url;

            return $serve($url, $timeout, $sink);
        };

        $this->check(['2.1.0']);

        $this->assertTrue($this->downloads()->has('2.1.0') && $this->downloads()->has('2.0.0'));
        $this->assertSame([], array_values(array_filter($asked, fn ($u) => preg_match('#^https://example\.test/\d#', $u))), 'never the listing\'s address');
        $this->assertContains('https://example.test/download/2.1.0/owa_2.1.0_packaged.tar', $asked);
        $this->assertContains('https://example.test/download/2.1.0/owa_2.1.0_packaged.tar.sig', $asked);
    }

    /** Links the web user puts at the names a download writes, or as the updates directory, are replaced or passed over, never written through. */
    public function testADownloadNeverWritesThroughALink(): void
    {
        $d = $this->downloads();
        $d->ensureRoot();
        $root   = $d->root();
        $victim = $this->tmp . '/victim.php';
        file_put_contents($victim, 'the cron user\'s');
        $names = ['owa_2.1.0_packaged.tar.part', 'owa_2.1.0_packaged.tar', 'owa_2.1.0_packaged.tar.sig', Downloads::FAILURE];
        foreach ($names as $name) {
            symlink($victim, "$root/$name");
        }

        $result = $this->check(['2.1.0']);
        $this->downloads()->writeFailure(['version' => '2.1.0', 'error' => 'a test']);

        $this->assertSame(ReleaseCheck::WAITING, $result['outcome'], implode("\n", $result['lines']));
        $this->assertSame('the cron user\'s', file_get_contents($victim));
        foreach ($names as $name) {
            $this->assertFalse(is_link("$root/$name"), $name);
        }
        $this->assertTrue($this->downloads()->has('2.1.0'));

        // The setting, or a directory found, that is a link is not the updates directory.
        mkdir($this->tmp . '/elsewhere');
        \OWA\Core\CoreAPI::setSetting('base', 'updates_dir', $this->tmp . '/data/updates-' . str_repeat('e', 32));
        symlink($this->tmp . '/elsewhere', $this->tmp . '/data/updates-' . str_repeat('e', 32));
        $this->assertSame($root, $this->downloads()->root());
    }

    /** A proved tarball is a private copy: rewriting or replacing the file afterwards changes nothing that is read or installed. */
    public function testWhatIsReadIsWhatWasProved(): void
    {
        $this->check(['2.1.0']);
        $d   = $this->downloads();
        $sha = $d->sha256('2.1.0');
        $d->open('2.1.0');

        // Someone who can write the data directory swaps in another release's tarball.
        copy($d->tarball('2.0.0'), $d->tarball('2.1.0'));

        $this->assertSame('2.1.0', \OWA\Core\VersionInfo::decode(Tar::member($d->open('2.1.0'), 'release.php'))['version']);
        $this->assertSame($sha, $d->sha256('2.1.0'));
        $this->assertNotSame($sha, hash_file('sha256', $d->tarball('2.1.0')), 'the file on disk did change');
        $this->assertSame('2.1.0', $d->releaseInfo('2.1.0')['version']);
    }

    /** Nothing is parsed that has not been proved: a FIFO, or a tarball without a valid signature, is refused and deleted, and judged as a problem. */
    public function testAnUnprovedTarballIsNeverRead(): void
    {
        $this->check(['2.1.0']);
        $d   = $this->downloads();
        $tar = $d->tarball('2.1.0');

        unlink($tar);
        posix_mkfifo($tar, 0644);
        $this->assertFalse($this->downloads()->has('2.1.0'), 'not a download');
        $this->assertNull($this->downloads()->releaseInfo('2.1.0'), 'never opened, so never waited on');
        $this->assertStringContainsString('is not a plain file', implode("\n", $this->judge('2.1.0')['problems']));
        $this->assertFileDoesNotExist($tar, 'judged, it is deleted like any tarball that does not prove');

        // A tarball of its own, signed by no trusted key.
        copy($this->tmp . '/owa_2.1.0_packaged.tar', $tar);
        file_put_contents($tar . '.sig', Signature::sign('2.1.0', hash_file('sha256', $tar), base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))));
        $judged = $this->judge('2.1.0');
        $this->assertStringContainsString('is not signed by a key this installation trusts', implode("\n", $judged['problems']));
        $this->assertSame([], $judged['manual'], 'its release.php was never read');
        $this->assertFileDoesNotExist($tar);
    }

    /** @return array{problems: list<string>, manual: list<array<string, mixed>>, rollback: list<string>} */
    private function judge(string $v): array
    {
        return (new ReleaseCheck($this->downloads(), ['root' => $this->tmp . '/owa', 'env' => $this->env(), 'installed' => '2.0.0']))->judge($v);
    }

    /** A long name or pax record is read whole, so none larger than a release has is read at all. */
    public function testAnOversizedHeaderRecordIsRefused(): void
    {
        file_put_contents($this->tmp . '/big.tar', $this->member('././@LongLink', '', 'L', 2 * 1048576) . str_repeat("\0", 1024));

        $this->expectExceptionMessage('has a header record of 2097152 bytes');
        Tar::files($this->tmp . '/big.tar');
    }

    public function testATarballThatDoesNotMatchItsDigestIsDeleted(): void
    {
        $result = $this->check(['2.0.1'], [], function ($r) {
            $r['assets'][0]['digest'] = 'sha256:' . str_repeat('0', 64);

            return $r;
        });

        $this->assertSame(ReleaseCheck::FAILED, $result['outcome']);
        $this->assertStringContainsString('does not match its SHA-256', implode("\n", $result['problems']));
        $this->assertSame([], glob($this->downloads()->root() . '/owa_2.0.1*'), 'nothing of it is kept');
    }

    public function testATarballOfAnotherReleaseFailsTheDownload(): void
    {
        $result = $this->check(['2.0.1'], [], null, fn ($dir) => file_put_contents($dir . '/release.php', \OWA\Core\VersionInfo::encode(['version' => '2.0.9'])));

        $this->assertSame(ReleaseCheck::FAILED, $result['outcome']);
        $this->assertStringContainsString("2.0.1's release.php says it is '2.0.9'", implode("\n", $result['problems']));
        $this->assertFalse($this->downloads()->has('2.0.1'));
    }

    /** GitHub's digest for the asset changed: it was replaced, so it is downloaded again. */
    public function testAReplacedAssetIsDownloadedAgain(): void
    {
        $this->check(['2.0.1']);
        $first = hash_file('sha256', $this->downloads()->tarball('2.0.1'));

        unlink($this->tmp . '/owa_2.0.1_packaged.tar');
        Downloads::remove($this->tmp . '/build-2.0.1');
        $result = $this->check(['2.0.1'], [], null, fn ($dir) => file_put_contents($dir . '/cli.php', 'rebuilt'));

        $this->assertNotSame($first, hash_file('sha256', $this->downloads()->tarball('2.0.1')));
        $this->assertSame('rebuilt', Tar::member($this->downloads()->tarball('2.0.1'), 'cli.php'));
        $this->assertStringContainsString('as the release\'s asset was replaced', implode("\n", $result['lines']));
    }

    /** Two updates directories: which is the real one is not guessed; nothing is downloaded. */
    public function testMoreThanOneUpdatesDirectoryStopsDownloads(): void
    {
        mkdir($this->tmp . '/data/updates-' . str_repeat('a', 32), 0700);
        mkdir($this->tmp . '/data/updates-' . str_repeat('b', 32), 0700);

        $result = $this->check(['2.0.1']);

        $this->assertSame(ReleaseCheck::FAILED, $result['outcome']);
        $this->assertStringContainsString('more than one updates-* directory', implode("\n", $result['problems']));
    }

    /** An unmet requirement: it is kept, and waits, judged again each run, until the server meets it. */
    public function testAnUnmetRequirementWaits(): void
    {
        $result = $this->check(['2.0.1'], ['env' => ['php' => '8.1.0'] + $this->env(), 'window' => '']);

        $this->assertSame(ReleaseCheck::UNMET, $result['outcome']);
        $this->assertTrue($this->downloads()->has('2.0.1'));
        $this->assertStringContainsString('PHP 8.2 or later; the CLI runs PHP 8.1.0', implode("\n", $result['problems']));
        $this->assertNull(Install::$pending);

        $this->assertSame(ReleaseCheck::WAITING, $this->check(['2.0.1'])['outcome'], 'and installs, in its window, once the server meets it');
    }

    public function testANewerReleaseReplacesTheDownloadedOne(): void
    {
        $this->check(['2.0.1']);
        $result = $this->check(['2.0.1', '2.0.2']);

        $this->assertSame('2.0.2', $result['version']);
        $this->assertSame(['2.0.2', '2.0.0'], $this->downloads()->versions(), 'the one to install, and the installed one');
    }

    /** While an install is under way (the install marker, or its lock) nothing is downloaded, deleted or installed. */
    public function testNothingChangesWhileAnInstallIsUnderWay(): void
    {
        $this->check(['2.0.1']);
        file_put_contents($this->tmp . '/owa/owa-update.php', "<?php exit; ?>\n" . json_encode(['phase' => 'copied', 'from' => '2.0.0', 'to' => '2.0.1']) . "\n");

        $this->assertSame(ReleaseCheck::BUSY, $this->check(['2.0.1', '2.0.2'], ['window' => ''])['outcome']);
        $this->assertSame(['2.0.1', '2.0.0'], $this->downloads()->versions());
        unlink($this->tmp . '/owa/owa-update.php');

        $h = fopen(InstallLock::$path, 'c');
        flock($h, LOCK_EX);

        try {
            $this->assertSame(ReleaseCheck::BUSY, $this->check(['2.0.1', '2.0.2'], ['window' => ''])['outcome']);
            $this->assertSame(['2.0.1', '2.0.0'], $this->downloads()->versions());
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** A run that chooses nothing deletes what it downloaded; one that cannot list still judges what was downloaded before. */
    public function testWhatIsKeptFollowsTheListing(): void
    {
        $this->check(['2.0.1']);

        $result = $this->check([], ['installed' => '2.0.1']);
        $this->assertSame(ReleaseCheck::CURRENT, $result['outcome']);
        $this->assertSame(['2.0.1'], $this->downloads()->versions(), 'only the installed release\'s');

        $this->check(['2.0.2'], ['installed' => '2.0.1']);
        $result = $this->check(null, ['installed' => '2.0.1']);
        $this->assertSame(ReleaseCheck::WAITING, $result['outcome'], 'GitHub unreachable: 2.0.2 still installs in its window');
        $this->assertStringContainsString('The releases could not be listed', implode("\n", $result['lines']));
        $this->assertTrue($this->downloads()->has('2.0.2'));
    }

    /** The listing request names the installed version and nothing else about the installation, for the update host's logs. */
    public function testTheListingRequestSendsTheInstalledVersionOnly(): void
    {
        $asked = [];
        Releases::$fetcher = function ($url) use (&$asked) {
            $asked[] = $url;
            throw new \RuntimeException("Fetching $url failed: 404");
        };

        $this->check(null);
        $this->check(null, ['url' => 'https://example.test/releases?per_page=100', 'installed' => '2.0.1']);

        $this->assertSame(['https://example.test/releases?from=2.0.0', 'https://example.test/releases?per_page=100&from=2.0.1'], $asked);

        // The installed release's tarball, when the listing it needs is not the caller's.
        $asked = [];
        $check = new ReleaseCheck($this->downloads(), ['root' => $this->tmp . '/owa', 'installed' => '2.0.1', 'url' => 'https://example.test/releases',
            'download' => 'https://example.test/download/']);
        $this->assertStringContainsString('The installed release, 2.0.1, could not be downloaded', (string) $check->ensureInstalled());
        $this->assertSame(['https://example.test/releases?from=2.0.1', 'https://example.test/download/2.0.1/owa_2.0.1_packaged.tar'], $asked,
            'the listing, then the release by its version');
    }

    /** A minor downloaded for tonight, then automatic updates set to patch only: it is deleted, and nothing installs. */
    public function testALevelThatNoLongerChoosesItDeletesTheDownload(): void
    {
        $this->check(['2.1.0']);
        $this->assertTrue($this->downloads()->has('2.1.0'));

        $this->assertSame(ReleaseCheck::CURRENT, $this->check(['2.1.0'], ['level' => 'patch'])['outcome']);
        $this->assertFalse($this->downloads()->has('2.1.0'));
    }

    /** Automatic updates turned off: what was downloaded goes, but the installed release's. */
    public function testTurningOffDeletesTheDownload(): void
    {
        $this->check(['2.1.0']);

        $this->assertSame(ReleaseCheck::OFF, $this->check(['2.1.0'], ['enabled' => false, 'window' => ''])['outcome']);
        $this->assertSame(['2.0.0'], $this->downloads()->versions());
        $this->assertNull(Install::$pending);
    }

    /** A release whose install failed and was put back is skipped; a newer one is not. */
    public function testAReleaseWhoseInstallFailedIsSkipped(): void
    {
        $this->check(['2.0.1']);
        $this->downloads()->writeFailure(['version' => '2.0.1', 'from' => '2.0.0', 'error' => 'Could not write Core/Env.php. OWA 2.0.0 was put back.', 'at' => $this->now]);

        $result = $this->check(['2.0.1'], ['window' => '']);
        $this->assertSame(ReleaseCheck::SKIPPED, $result['outcome']);
        $this->assertStringContainsString('cmd=core-install --version=2.0.1', implode("\n", $result['lines']));
        $this->assertArrayHasKey('install_failed:2.0.1', $this->notices, 'the notice the failure calls for');
        $this->assertNull(Install::$pending);

        $this->assertSame(ReleaseCheck::INSTALLING, $this->check(['2.0.1', '2.0.2'], ['window' => ''])['outcome'], 'a newer release installs as usual');
        $this->assertSame('2.0.2', Install::$pending['to']);
        $this->dropPending();

        $this->assertSame(ReleaseCheck::CURRENT, $this->check(['2.0.1'], ['installed' => '2.0.2'])['outcome'], 'the failure was of 2.0.1 over 2.0.0 only');
    }

    public function testNothingChangesWhileAnInstallHoldsTheLock(): void
    {
        $this->check(['2.0.1']);
        $h = fopen(InstallLock::$path, 'c');
        flock($h, LOCK_EX);

        try {
            $this->assertSame(ReleaseCheck::BUSY, $this->check(['2.0.1', '2.0.2'])['outcome']);
            $this->assertTrue($this->downloads()->has('2.0.1'));
            $this->assertFalse($this->downloads()->has('2.0.2'));
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public function testNoAutomaticUpdateFromACheckoutOrWhenOff(): void
    {
        file_put_contents($this->tmp . '/owa/.git', 'gitdir: elsewhere');

        $result = $this->check(['2.0.1', '2.0.2']);
        $this->assertSame(ReleaseCheck::CHECKOUT, $result['outcome']);
        $this->assertStringContainsString('/owa/.git', implode("\n", $result['lines']), 'a .git file counts, as a worktree has');
        $this->assertSame([], $this->downloads()->versions());
        $this->assertSame([3], $this->feeds, 'the bell says what is new');

        unlink($this->tmp . '/owa/.git');
        $this->assertSame(ReleaseCheck::OFF, $this->check(['2.0.1'], ['enabled' => false])['outcome']);
        $this->assertSame(ReleaseCheck::ROLE, $this->check(['2.0.1'], ['role' => 'collector'])['outcome']);
        $this->assertSame([], $this->downloads()->versions());
    }

    /** The bell's release feed comes from the listing on every run: off, a checkout or another role. */
    public function testTheBellIsFedWhateverFollows(): void
    {
        file_put_contents($this->tmp . '/owa/.git', 'gitdir: elsewhere');
        $this->check(['2.0.1']);
        $this->check(['2.0.1'], ['enabled' => false]);
        $this->check(['2.0.1'], ['role' => 'worker']);
        $this->assertSame([2, 2, 2], $this->feeds, '2.0.1 and the installed 2.0.0');

        $this->check(null);
        $this->check(['2.0.1'], [], null, null, true);
        $this->assertSame([2, 2, 2], $this->feeds, 'not when the listing fails, nor on a dry run');
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
        $this->assertSame(['download_failing:2.0.1'], array_keys($this->notices));
        $this->assertFalse($this->notices['download_failing:2.0.1']['email'], 'the bell only, at first');

        $this->now += ReleaseCheck::FAILING_FOR - 1;
        $this->check(['2.0.1'], ['now' => $this->now], $bad);
        $this->assertArrayNotHasKey('download_failed:2.0.1', $this->notices);

        $this->now += 1;
        $this->check(['2.0.1'], ['now' => $this->now], $bad);
        $this->assertTrue($this->notices['download_failed:2.0.1']['email']);
        $this->assertSame('warning', $this->notices['download_failed:2.0.1']['severity']);
    }

    /** An update this install would run that must be installed by hand: downloaded and announced, never installing itself. */
    public function testAnUpdateMarkedForAManualInstall(): void
    {
        $this->releaseJson['2.1.0'] = ['modules' => ['base' => 97], 'updates' => [
            ['module' => 'base', 'sequence' => 95, 'manual_install' => 'Already run here.'],
            ['module' => 'base', 'sequence' => 96, 'manual_install' => ''],
            ['module' => 'base', 'sequence' => 97, 'manual_install' => 'It rewrites every event. Back up the data tables first: php cli.php cmd=backup scope=data'],
            ['module' => 'gone', 'sequence' => 1, 'manual_install' => 'A module this install does not have.'],
        ]];

        $result = $this->check(['2.1.0'], ['window' => '']);

        $this->assertSame(ReleaseCheck::MANUAL, $result['outcome']);
        $this->assertTrue($this->downloads()->has('2.1.0'));
        $this->assertNull(Install::$pending);
        $this->assertSame(['base update 97: It rewrites every event. Back up the data tables first: php cli.php cmd=backup scope=data'],
            $result['problems'], 'only what this install would run');
        $this->assertStringContainsString('then install it: php cli.php cmd=core-install --version=2.1.0', $this->notices['manual:2.1.0']['body']);

        $this->schema['base'] = 97;
        $this->assertSame(ReleaseCheck::WAITING, $this->check(['2.1.0'])['outcome'], 'once run by hand there is nothing manual left');
    }

    /** By hand: the release named installs now whatever the settings, and a failure recorded for it is cleared. */
    public function testInstallByHand(): void
    {
        $this->downloads()->ensureRoot();
        $this->downloads()->writeFailure(['version' => '2.0.2', 'from' => '2.0.0', 'error' => 'it failed']);

        $result = $this->hand('2.0.2', ['2.0.1', '2.0.2'], ['enabled' => false]);
        $this->assertSame(Install::OK, $result['outcome'], $result['message'] . implode("\n", $result['lines']));
        $this->assertSame('2.0.2', Install::$pending['to']);
        $this->assertNull($this->downloads()->failure());
        $this->dropPending();
    }

    /** --version fetches that release by its version, not through the listing, the signature its only proof; a download that does not prove is refused. */
    public function testInstallByVersionFetchesByTag(): void
    {
        $tar = $this->build('2.0.3', null);
        $this->serve('2.0.3', $tar);

        $result = $this->hand('2.0.3', [], []);
        $this->assertSame(Install::OK, $result['outcome'], $result['message']);
        $this->assertSame('2.0.3', Install::$pending['to'], 'not in the listing, fetched by its version');
        $this->dropPending();

        // The listing's 2.0.0 tarball is fine; this one is signed for another release.
        $this->served['https://example.test/download/2.0.3/owa_2.0.3_packaged.tar.sig'] = $this->tmp . '/bad.sig';
        file_put_contents($this->tmp . '/bad.sig', Signature::sign('2.0.4', hash_file('sha256', $tar), $this->signingKey));
        $result = $this->hand('2.0.3', [], []);
        $this->assertSame(Install::FAILED, $result['outcome']);
        $this->assertStringContainsString('is not signed by a key this installation trusts', $result['message']);
        $this->assertNull(Install::$pending);
    }

    /** The installed release not in the listing (past its first 100): fetched by its version. */
    public function testTheInstalledReleaseIsFetchedByTagWhenTheListingLacksIt(): void
    {
        $this->serve('2.0.0', $this->build('2.0.0', null));

        $result = $this->check(['2.0.1'], ['window' => ''], null, null, false, false, null, false);
        $this->assertSame(ReleaseCheck::INSTALLING, $result['outcome'], implode("\n", $result['lines']));
        $this->assertTrue($this->downloads()->has('2.0.0'));
        $this->dropPending();
    }

    /** By hand, going back: only with --force, and never to a release older than the database. */
    public function testAnOlderReleaseByHand(): void
    {
        $this->releaseJson['2.0.1'] = ['modules' => ['base' => 93]];
        $result = $this->hand('2.0.1', ['2.0.1'], ['installed' => '2.0.4']);
        $this->assertSame(Install::REFUSED, $result['outcome']);
        $this->assertStringContainsString('add --force', $result['message']);

        $result = $this->hand('2.0.1', ['2.0.1'], ['installed' => '2.0.4'], true);
        $this->assertSame(Install::REFUSED, $result['outcome']);
        $this->assertStringContainsString('This database is newer than 2.0.1 expects', $result['message']);
        $this->assertSame(['php cli.php cmd=core-update-schema rollback=base.095', 'php cli.php cmd=core-update-schema rollback=base.094'], $result['lines']);
        $this->assertNull(Install::$pending);
        $this->assertFalse($this->downloads()->has('2.0.1'), 'refused after its download: deleted');
        $this->assertTrue($this->downloads()->has('2.0.4'), 'the installed release\'s stays');

        Downloads::remove($this->tmp . '/build-2.0.1');
        @unlink($this->tmp . '/owa_2.0.1_packaged.tar');
        @unlink($this->downloads()->tarball('2.0.1'));
        $this->releaseJson['2.0.1'] = ['modules' => ['base' => 95]];
        $this->assertSame(Install::OK, $this->hand('2.0.1', ['2.0.1'], ['installed' => '2.0.4'], true)['outcome'], 'the schema it expects: it installs');
        $this->dropPending();
    }

    /** By hand: a major, an unpublished release, unmet requirements and a checkout are refused. */
    public function testInstallByHandRefuses(): void
    {
        $this->assertStringContainsString('another major version', $this->hand('3.0.0', ['3.0.0'])['message']);
        $this->assertStringContainsString('Downloading 2.0.9 failed: Fetching https://example.test/download/2.0.9/owa_2.0.9_packaged.tar failed', $this->hand('2.0.9', ['2.0.1'])['message'], 'not published');
        $this->assertStringContainsString('cannot be installed here', $this->hand('2.0.1', ['2.0.1'], ['env' => ['php' => '8.1.0'] + $this->env()])['message']);

        file_put_contents($this->tmp . '/owa/.git', 'gitdir: elsewhere');
        $this->assertStringContainsString('version control checkout', $this->hand('2.0.1', ['2.0.1'])['message']);
        $this->assertNull(Install::$pending);
    }

    public function testStatus(): void
    {
        $this->check(['2.1.0'], ['env' => ['php' => '8.1.0'] + $this->env()]);

        $status = fn (array $env) => implode("\n", (new ReleaseCheck($this->downloads(), [
            'root' => $this->tmp . '/owa', 'installed' => '2.0.0', 'enabled' => true, 'level' => 'minor',
            'window' => '02:00-06:00', 'days' => '', 'timezone' => 'UTC', 'env' => $env,
        ]))->status());

        $lines = $status(['php' => '8.1.0'] + $this->env());
        $this->assertStringContainsString('Automatic updates: patch and minor releases, installed between 02:00 and 06:00 (UTC), any day.', $lines);
        $this->assertStringContainsString('Downloaded: 2.0.0, the installed release, which a failed install puts back.', $lines);
        $this->assertStringContainsString('cannot be installed yet', $lines);
        $this->assertStringContainsString('It needs PHP 8.2 or later', $lines);
        $this->assertStringContainsString('; can be installed.', $status($this->env()), 'judged now, not remembered');

        $this->downloads()->writeFailure(['version' => '2.1.0', 'from' => '2.0.0', 'error' => 'Could not write Core/Env.php. OWA 2.0.0 was put back.', 'at' => 1800000000]);
        $this->assertStringContainsString('Installing 2.1.0 over 2.0.0 failed, 2027-01-15 08:00 UTC: Could not write Core/Env.php.', $status($this->env()));
        $this->assertStringContainsString('skipped, its install failed', $status($this->env()));

        file_put_contents($this->tmp . '/owa/owa-update.php', "<?php exit; ?>\n" . json_encode(['phase' => 'copied', 'from' => '2.0.0', 'to' => '2.1.0', 'since' => 1800000000]) . "\n");
        $this->assertStringContainsString('Installing 2.1.0 over 2.0.0: copied, waiting for the next run of cli.php to boot it since 2027-01-15 08:00 UTC.', $status($this->env()));
    }

    /** The installed release's tarball cannot be had: nothing installs without the way back. */
    public function testWithoutTheInstalledReleasesTarballNothingInstallsItself(): void
    {
        $result = $this->check(['2.0.1'], ['installed' => '2.0.0', 'window' => ''], null, null, false, false, null, false);

        $this->assertSame(ReleaseCheck::UNMET, $result['outcome'], implode("\n", $result['lines']));
        $this->assertStringContainsString('The installed release, 2.0.0, could not be downloaded: Fetching https://example.test/download/2.0.0/owa_2.0.0_packaged.tar failed: 404. A failed install puts it back',
            implode("\n", $result['problems']));
        $this->assertNull(Install::$pending);

        $this->assertSame(ReleaseCheck::WAITING, $this->check(['2.0.1'])['outcome'], 'and installs once it can be had');
    }

    public function testAReleaseWithoutAnUpdaterCannotInstallItself(): void
    {
        $result = $this->check(['2.0.1'], [], null, fn ($dir) => null, false, true);

        $this->assertSame(ReleaseCheck::UNMET, $result['outcome']);
        $this->assertStringContainsString('The release has no updater.php', implode("\n", $result['problems']));
    }

    /** Signed by no key this installation trusts, unsigned, or a signature of another release: nothing is staged, and the bell says why. */
    public function testOnlyAReleaseSignedByATrustedKeyIsStaged(): void
    {
        $other = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $tar   = $this->build('2.0.1', null);

        $cases = [
            'another key'     => [Signature::sign('2.0.1', hash_file('sha256', $tar), $other), 'owa_2.0.1_packaged.tar is not signed by a key this installation trusts.'],
            'another release' => [Signature::sign('2.0.2', hash_file('sha256', $tar), $this->signingKey), 'owa_2.0.1_packaged.tar is not signed by a key this installation trusts.'],
            'not a signature' => ['c2ln', 'owa_2.0.1_packaged.tar.sig is not a release signature.'],
        ];

        foreach ($cases as $case => [$signature, $why]) {
            $this->notices = [];
            $this->raised  = [];
            $result = $this->check(['2.0.1'], [], null, null, false, false, static fn () => $signature);

            $this->assertSame(ReleaseCheck::FAILED, $result['outcome'], $case);
            $this->assertSame([$why], $result['problems'], $case);
            $this->assertFalse($this->downloads()->has('2.0.1'), "$case: nothing kept");
            $this->assertArrayHasKey('download_failing:2.0.1', $this->notices, $case);
        }

        // No signature asset at all.
        $result = $this->check(['2.0.1'], [], static function (array $r) {
            $r['assets'] = [$r['assets'][0]];

            return $r;
        });
        $this->assertSame(ReleaseCheck::FAILED, $result['outcome']);
        $this->assertStringContainsString('owa_2.0.1_packaged.tar is not signed: the release has no owa_2.0.1_packaged.tar.sig.', implode("\n", $result['problems']));

        // No trusted key at all: fail closed.
        Signature::$trusted = [];
        $result = $this->check(['2.0.1']);
        $this->assertStringContainsString('trusts no release signing key', implode("\n", $result['problems']));
    }

    public function testADryRunWritesNothing(): void
    {
        $result = $this->check(['2.0.1'], [], null, null, true);

        $this->assertSame(ReleaseCheck::CHOSEN, $result['outcome']);
        $this->assertSame('2.0.1', $result['version']);
        $this->assertSame([], glob($this->tmp . '/data/updates-*'), 'not even the updates directory');
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

        // Judged as boot judges them: a database user without a password is a database user.
        $this->assertSame([], Preflight::requirements($req, ['constants' => ['OWA_DB_PASSWORD' => ''] + $env['constants']] + $env));
        $this->assertStringContainsString('OWA_PUBLIC_URL defined',
            implode("\n", Preflight::requirements($req, ['constants' => ['OWA_PUBLIC_URL' => ''] + $env['constants']] + $env)));
        $this->assertStringContainsString('OWA_AUTH_KEY to be at least 32',
            implode("\n", Preflight::requirements($req, ['constants' => ['OWA_AUTH_KEY' => ''] + $env['constants']] + $env)));
    }

    /** Each tarball is read into a private temporary copy: PHP's temporary directory must take both. */
    public function testTheTemporaryDirectoryTakesTheTarballs(): void
    {
        mkdir($this->tmp . '/t', 0755);
        $this->assertSame([], Preflight::temp(1048576, $this->tmp . '/t'));
        $this->assertStringContainsString('Installing needs about 1073741824 MB free in PHP\'s temporary directory',
            implode("\n", Preflight::temp(1073741824 * 1048576, $this->tmp . '/t')));

        chmod($this->tmp . '/t', 0555);
        $this->assertStringContainsString('cannot be written by the user the scheduler runs as', implode("\n", Preflight::temp(1, $this->tmp . '/t')));
        chmod($this->tmp . '/t', 0755);
        $this->assertStringContainsString('cannot be written', implode("\n", Preflight::temp(1, $this->tmp . '/none')));

        $this->check(['2.1.0']);
        $d = $this->downloads();
        $this->assertSame(filesize($d->tarball('2.1.0')) + filesize($d->tarball('2.0.0')), $d->tarballBytes('2.1.0', '2.0.0'));
        $this->assertSame(2 * filesize($d->tarball('2.1.0')), $d->tarballBytes('2.1.0', '9.9.9'), 'one not downloaded counts as the first');
    }

    /** Every run tries PHP's temporary directory: one it cannot write fails the job, so System Health says so before a release needs it. */
    public function testAnUnwritableTemporaryDirectoryFailsEveryRun(): void
    {
        $result = $this->check([], ['temp' => ['PHP\'s temporary directory (/x) cannot be written.']]);
        $this->assertSame(ReleaseCheck::NO_TEMP, $result['outcome']);
        $this->assertStringContainsString('/x) cannot be written', implode("\n", $result['lines']));
        $this->assertSame(ReleaseCheck::CURRENT, $this->check([])['outcome'], 'this one can');

        // The probe, in a PHP whose temporary directory cannot be written.
        mkdir($this->tmp . '/ro', 0555);
        $probe = 'require ' . var_export(dirname(__DIR__) . '/modules/Base/Classes/AutoUpdate/Preflight.php', true)
            . '; echo json_encode(\\OWA\\Module\\Base\\Classes\\AutoUpdate\\Preflight::tempWrites());';
        foreach ([$this->tmp . '/ro' => 'false', $this->tmp => 'true'] as $dir => $expected) {
            $this->assertSame($expected, shell_exec(escapeshellarg(PHP_BINARY) . ' -n -d sys_temp_dir=' . escapeshellarg($dir) . ' -r ' . escapeshellarg($probe)), $dir);
        }
        chmod($this->tmp . '/ro', 0755);
    }

    public function testInstallConditions(): void
    {
        $root  = $this->tmp . '/owa';
        $files = ['cli.php' => 3, 'new/dir/file.php' => 3];

        $this->assertSame([], Preflight::install($files, $root));

        chmod($root, 02775);
        $this->assertSame([], Preflight::install($files, $root), 'a group-writable OWA directory is a setgid web group, not a problem');
        chmod($root, 0755);

        // A file is renamed over, so its own mode does not matter; its directory's does.
        chmod($root . '/cli.php', 0444);
        $this->assertSame([], Preflight::install($files, $root), 'a read-only file in a writable directory is replaced');

        mkdir($root . '/new', 0755);
        chmod($root . '/new', 0555);
        $this->assertStringContainsString('1 of the directories the release writes in cannot be written by the user the scheduler runs as, such as new/.',
            implode("\n", Preflight::install($files, $root)), 'new/dir/ is created in new/');
        chmod($root . '/new', 0755);

        chmod($root, 0555);
        $this->assertStringContainsString('1 of the directories the release writes in cannot be written by the user the scheduler runs as, such as the OWA directory.',
            implode("\n", Preflight::install($files, $root)));
        chmod($root, 0755);

        $this->assertStringContainsString('Installing it needs about', implode("\n", Preflight::install(['huge.bin' => PHP_INT_MAX >> 1], $root)));
    }

    public function testTheCodeTheInstallerCannotWrite(): void
    {
        $root = $this->tmp . '/owa';
        mkdir($root . '/Core', 0755, true);
        mkdir($root . '/owa-data/logs', 0755, true);
        mkdir($root . '/public/tracker', 0755, true);

        $this->assertSame([], Preflight::unwritableCode($root));

        chmod($root . '/Core', 0555);
        chmod($root . '/owa-data', 0555);
        chmod($root . '/public/tracker', 0555);

        try {
            $this->assertSame(['Core'], Preflight::unwritableCode($root), 'the installation\'s own directories are not code');
        } finally {
            chmod($root . '/Core', 0755);
            chmod($root . '/owa-data', 0755);
            chmod($root . '/public/tracker', 0755);
        }
    }

    // ---------------------------------------------------------------------

    private function downloads(): Downloads
    {
        return new Downloads(null, $this->tmp . '/data');
    }

    /** An install prepare() proved: its handles closed and its lock let go, as the process ending would. */
    private function dropPending(): void
    {
        $p = Install::$pending;
        Install::$pending = null;

        if ($p !== null) {
            fclose($p['to_h']);
            fclose($p['from_h']);
            $p['u']['unlock']($p['hold']);
        }
    }

    /**
     * One run of ReleaseCheck against listed releases built here.
     *
     * @param list<string>|null $versions released, null for a listing that fails
     */
    /**
     * $sign: the signature file for a release in place of a good one, given its version and tarball.
     * $withInstalled: the installed release is listed too, as GitHub lists every release.
     */
    private function check(?array $versions, array $context = [], ?callable $edit = null, ?callable $tamper = null, bool $dry = false, bool $noUpdater = false, ?callable $sign = null, bool $withInstalled = true): array
    {
        $list      = null;
        $installed = (string) ($context['installed'] ?? '2.0.0');

        if ($versions !== null) {
            $list = [];
            if ($withInstalled && !in_array($installed, $versions, true) && $versions !== []) {
                $tar = $this->build($installed, null);
                $this->serve($installed, $tar);
                $list[] = $this->listed($installed, 'https://example.test/' . $installed . '.tar', hash_file('sha256', $tar));
            }
            foreach ($versions as $v) {
                $tar = $this->build($v, $tamper, $noUpdater);
                $this->serve($v, $tar, $sign ? $sign($v, $tar) : null);
                $r = $this->listed($v, 'https://example.test/' . $v . '.tar', hash_file('sha256', $tar));
                $list[] = $edit ? $edit($r) : $r;
            }
        }

        $check = new ReleaseCheck($this->downloads(), $context + [
            'root'      => $this->tmp . '/owa',
            'installed' => '2.0.0',
            'role'      => \OWA\Core\ServerRole::FULL,
            'enabled'   => true,
            'level'     => 'minor',
            'days'      => 'sat, sun',
            'window'    => '02:00-06:00',
            'timezone'  => 'Europe/London',
            'url'       => 'https://example.test/releases',
            'download'  => 'https://example.test/download/',
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
            'sleep'     => static function ($s) {},
            'data_dir'  => $this->tmp . '/data/',
        ]);

        if ($versions === null) {
            $check->context['releases'] = null;
            $this->served = [];
        }

        return $check->run($dry);
    }

    /** Install::byHand() against listed releases built here. */
    private function hand(string $version, array $versions, array $context = [], bool $force = false): array
    {
        $list      = [];
        $installed = (string) ($context['installed'] ?? '2.0.0');
        if (!in_array($installed, $versions, true)) {
            $versions[] = $installed;
        }
        foreach ($versions as $v) {
            $tar = $this->build($v, null);
            $this->serve($v, $tar);
            $list[] = $this->listed($v, 'https://example.test/' . $v . '.tar', hash_file('sha256', $tar));
        }

        return Install::byHand($version, $force, $this->downloads(), $context + [
            'root'      => $this->tmp . '/owa',
            'installed' => '2.0.0',
            'role'      => \OWA\Core\ServerRole::FULL,
            'releases'  => $list,
            'download'  => 'https://example.test/download/',
            'env'       => $this->env(),
            'notify'    => function (array $n) { $this->notices[$n['source_key']] = $n; },
            'schema'    => fn ($module) => $this->schema[$module] ?? null,
            'now'       => $this->now,
        ]);
    }

    /** A release tarball as the packager makes it: release.php inside, members ./... */
    private function build(string $v, ?callable $tamper, bool $noUpdater = false): string
    {
        $dir = $this->tmp . "/build-$v";
        $tar = $this->tmp . "/owa_{$v}_packaged.tar";

        if (is_file($tar)) {
            return $tar;
        }

        mkdir($dir . '/modules/Base/Classes/AutoUpdate', 0755, true);
        mkdir($dir . '/Core', 0755, true);
        file_put_contents($dir . '/cli.php', 'new cli');
        if (!$noUpdater) {
            copy(dirname(__DIR__) . '/updater.php', $dir . '/updater.php');
        }
        file_put_contents($dir . '/owa_env.php', '<?php');
        file_put_contents($dir . '/Core/Env.php', "<?php\nfinal class Env {\n    const VERSION = '$v';\n}\n");
        foreach (['Tar', 'Signature'] as $class) {
            copy(dirname(__DIR__) . "/modules/Base/Classes/AutoUpdate/$class.php", $dir . "/modules/Base/Classes/AutoUpdate/$class.php");
        }
        file_put_contents($dir . '/modules/Base/Module.php', "<?php // $v");
        file_put_contents($dir . '/release.php', \OWA\Core\VersionInfo::encode(($this->releaseJson[$v] ?? []) + ['format' => 1, 'version' => $v,
            'requirements' => \OWA\Core\Requirements::toArray(), 'modules' => ['base' => 95], 'updates' => []]));

        if ($tamper) {
            $tamper($dir);
        }

        exec(sprintf('tar --format=gnu -C %s -cf %s ./ 2>&1', escapeshellarg($dir), escapeshellarg($tar)));

        return $tar;
    }

    /** The tarball at https://example.test/<v>.tar and its signature beside it, as the packager publishes them. */
    /** Also where a release is fetched by its version: https://example.test/download/<v>/<file>. */
    private function serve(string $v, string $tar, ?string $signature = null): void
    {
        $this->served["https://example.test/$v.tar"] = $tar;
        file_put_contents("$tar.sig", ($signature ?? Signature::sign($v, hash_file('sha256', $tar), $this->signingKey)) . "\n");
        $this->served["https://example.test/$v.tar.sig"] = "$tar.sig";
        $this->served["https://example.test/download/$v/owa_{$v}_packaged.tar"] = $tar;
        $this->served["https://example.test/download/$v/owa_{$v}_packaged.tar.sig"] = "$tar.sig";
    }

    private function listed(string $v, ?string $url = null, ?string $sha = null): array
    {
        $url = $url ?? "https://example.test/$v.tar";

        return [
            'tag_name' => $v,
            'html_url' => "https://github.com/Open-Web-Analytics/Open-Web-Analytics/releases/tag/$v",
            'assets'   => [[
                'name'                 => "owa_{$v}_packaged.tar",
                'browser_download_url' => $url,
                'size'                 => 10240,
                'digest'               => 'sha256:' . ($sha ?? str_repeat('a', 64)),
            ], [
                'name'                 => "owa_{$v}_packaged.tar.sig",
                'browser_download_url' => "$url.sig",
                'size'                 => 89,
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

    /** One ustar member, for archives GNU tar refuses to make; $size in place of the data's when given. */
    private function member(string $name, string $data, string $type = '0', ?int $size = null): string
    {
        $h = str_pad($name, 100, "\0") . str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', $size ?? strlen($data)), 12, "\0") . str_pad(sprintf('%011o', 0), 12, "\0") . '        ' . $type
            . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);

        return $h . str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\0");
    }
}
