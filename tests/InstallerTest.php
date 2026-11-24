<?php

use OWA\Module\Base\Classes\AutoUpdate\Signature;
use OWA\Module\Base\Classes\AutoUpdate\Tar;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Automatic updates before OWA loads, and the copy: updater.php's closures
 * (the copy, the install marker, preboot, putting a release back, the lock,
 * the entry points' answers).
 *
 * Against a throwaway installed tree, 2.0.0, and two signed tarballs as the
 * packager makes them: 2.0.0's, which a failed install puts back, and 2.0.1's.
 * Install::run() ends the process it runs in; the end-to-end harness
 * (tests/tools/self_update_e2e.php) drives it through cli.php.
 */
final class InstallerTest extends TestCase
{
    private string $tmp = '';
    private string $root = '';
    private string $data = '';
    private string $updates = '';
    private string $signingKey = '';

    /** @var array<string, \Closure> updater.php's closures */
    private array $u = [];

    /** 2.0.0's files, as installed. */
    private const OLD = [
        'cli.php'                 => 'old cli',
        'owa_env.php'             => 'env',
        'Core/Env.php'            => "<?php\nfinal class Env {\n    const VERSION = '2.0.0';\n}\n",
        'modules/Base/Module.php' => 'old module',
        'modules/Base/Retired.php' => 'a file 2.0.1 no longer has',
        'modules/Gone/Module.php' => 'a module 2.0.1 no longer has',
        'public/base/old.js'      => 'a built asset 2.0.1 does not ship',
        'same.php'                => 'unchanged',
        'owa.php'                 => '<?php // 2.0.0',
    ];

    /** 2.0.1's. */
    private const NEW = [
        'cli.php'                 => 'new cli',
        'owa_env.php'             => 'env',
        'Core/Env.php'            => "<?php\nfinal class Env {\n    const VERSION = '2.0.1';\n}\n",
        'modules/Base/Module.php' => 'new module',
        'modules/Base/Added.php'  => 'added',
        'public/base/new.js'      => 'a built asset',
        'same.php'                => 'unchanged',
        'owa.php'                 => '<?php // 2.0.1',
    ];

    protected function setUp(): void
    {
        $this->tmp     = sys_get_temp_dir() . '/owa-installer-' . bin2hex(random_bytes(4));
        $this->root    = $this->tmp . '/owa';
        $this->data    = $this->tmp . '/data';
        $this->u       = require dirname(__DIR__) . '/updater.php';

        mkdir($this->root, 0755, true);
        mkdir($this->data, 0755, true);
        $this->updates = (string) $this->u['updatesDir']($this->data, true);

        $pair               = sodium_crypto_sign_keypair();
        $this->signingKey   = base64_encode(sodium_crypto_sign_secretkey($pair));
        Signature::$trusted = [base64_encode(sodium_crypto_sign_publickey($pair))];

        // The installed release, and what belongs to the installation and its operator.
        $this->tree($this->root, self::OLD + [
            'owa-config.php'                => '<?php // the installation\'s',
            'owa-data/logs/errors.txt'      => 'the data directory',
            'public/tracker/OWA-site.js'    => 'a tracking bundle',
            'modules/ThirdParty/Module.php' => 'someone else\'s module',
            '.htpasswd'                     => 'the operator\'s',
            '.well-known/security.txt'      => 'the operator\'s',
        ]);
        chmod($this->root . '/cli.php', 0700);
        copy(dirname(__DIR__) . '/updater.php', $this->root . '/updater.php');

        $this->release('2.0.0', self::OLD);
        $this->release('2.0.1', self::NEW);
    }

    protected function tearDown(): void
    {
        Signature::$trusted = null;

        if ($this->tmp !== '') {
            exec('chmod -R u+w ' . escapeshellarg($this->tmp) . '; rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testTheUpdatesDirectoryIsFoundNotGuessed(): void
    {
        $this->assertMatchesRegularExpression('#/data/updates-[0-9a-f]{32}$#', $this->updates);
        $this->assertSame('0755', sprintf('%04o', fileperms($this->updates) & 0777), 'the web user lists it, for the Updates screen, and writes nothing');
        $this->assertSame($this->updates, $this->u['updatesDir']($this->data), 'found again, never derived');
        $this->assertSame($this->updates, $this->u['updatesDir']($this->data, true), 'created once');

        mkdir($this->data . '/updates-' . str_repeat('c', 32), 0700);
        $this->assertNull($this->u['updatesDir']($this->data), 'two: neither is guessed at');
        $this->assertNull($this->u['updatesDir']($this->data, true), 'nor a third made');
    }

    /** 2.0.1 over 2.0.0: what differs is written, what 2.0.0 shipped and 2.0.1 does not goes, and nothing else is touched. */
    public function testTheCopy(): void
    {
        touch($this->root . '/same.php', 1700000000);

        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));

        foreach (self::NEW as $path => $content) {
            $this->assertSame($content, file_get_contents($this->root . '/' . $path), $path);
        }

        clearstatcache();
        $this->assertSame(1700000000, filemtime($this->root . '/same.php'), 'a file that is already the release\'s is left as it is');
        $this->assertSame('0700', sprintf('%04o', fileperms($this->root . '/cli.php') & 0777), 'a replaced file keeps its mode');

        foreach (['modules/Base/Retired.php', 'public/base/old.js'] as $gone) {
            $this->assertFileDoesNotExist($this->root . '/' . $gone, '2.0.1 does not ship it');
        }
        $this->assertDirectoryDoesNotExist($this->root . '/modules/Gone', 'the directory it leaves empty goes too');

        foreach (['owa-config.php', 'owa-data/logs/errors.txt', 'public/tracker/OWA-site.js', 'modules/ThirdParty/Module.php', '.htpasswd', '.well-known/security.txt', 'updater.php'] as $kept) {
            $this->assertFileExists($this->root . '/' . $kept, 'no release ships it, so the copy never deletes it');
        }

        $this->assertSame([], $this->leftovers(), 'no temporary file left');
    }

    /** owa-data/ is the installation's, and the web user writes it: the copy neither writes nor deletes there, so a link planted in it leads nowhere. */
    public function testTheCopyLeavesOwaDataAlone(): void
    {
        $this->release('2.0.2', self::NEW + [
            'owa-data/index.php'      => '<?php // the release\'s stub',
            'owa-data/logs/index.php' => '<?php // the release\'s stub',
            'owa-data/geo/index.php'  => '<?php // the release\'s stub',
        ]);
        file_put_contents($this->tmp . '/victim.php', 'the cron user\'s');
        mkdir($this->tmp . '/elsewhere');
        symlink($this->tmp . '/victim.php', $this->root . '/owa-data/index.php.owa-update');
        symlink($this->tmp . '/victim.php', $this->root . '/owa-data/logs/index.php');
        symlink($this->tmp . '/elsewhere', $this->root . '/owa-data/geo');

        $this->u['apply']($this->root, $this->open('2.0.2'), array_merge(Tar::files($this->open('2.0.0')), ['owa-data/logs/errors.txt']));

        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
        $this->assertSame('the cron user\'s', file_get_contents($this->tmp . '/victim.php'));
        $this->assertSame([], array_diff((array) scandir($this->tmp . '/elsewhere'), ['.', '..']));
        $this->assertFileDoesNotExist($this->root . '/owa-data/index.php', 'not written');
        $this->assertFileExists($this->root . '/owa-data/logs/errors.txt', 'not deleted, though the old release is said to ship it');
        $this->assertTrue(is_link($this->root . '/owa-data/logs/index.php'), 'left as it is');
    }

    /** updater.php's own writes, which load no class: a link at last-failure.json or the marker is replaced, never written through; modes are their own whatever the umask; a link named like the updates directory is not it. */
    public function testNoWriteFollowsALink(): void
    {
        $victim = $this->tmp . '/victim.php';
        file_put_contents($victim, 'the cron user\'s');
        chmod($victim, 0640);
        symlink($victim, $this->updates . '/last-failure.json');
        symlink($victim, $this->root . '/owa-update.php');

        $umask = umask(0077);
        try {
            $this->u['recordFailure']($this->updates, ['from' => '2.0.0', 'to' => '2.0.1', 'since' => 1], 'a test');
            $this->marker('copying');
            mkdir($this->tmp . '/other');
            $dir = (string) $this->u['updatesDir']($this->tmp . '/other', true);
        } finally {
            umask($umask);
        }

        $this->assertSame('the cron user\'s', file_get_contents($victim));
        $this->assertSame('0640', sprintf('%04o', fileperms($victim) & 0777));
        foreach ([$this->updates . '/last-failure.json', $this->root . '/owa-update.php'] as $file) {
            $this->assertFalse(is_link($file), $file);
            $this->assertSame('0644', sprintf('%04o', fileperms($file) & 0777), 'readable by the web user, whatever the umask');
        }
        $this->assertSame('a test', $this->failure()['error']);
        $this->assertSame('copying', $this->u['readMarker']($this->root)['phase']);
        $this->assertSame([], $this->leftovers());
        $this->assertSame('0755', sprintf('%04o', fileperms($dir) & 0777), 'whatever the umask');

        mkdir($this->tmp . '/elsewhere');
        symlink($this->tmp . '/elsewhere', $this->tmp . '/other/updates-' . str_repeat('d', 32));
        $this->assertSame($dir, $this->u['updatesDir']($this->tmp . '/other'));
    }

    /** A copy that cannot write is put back from the installed release's tarball by the same closure: only what it changed is written. */
    public function testAFailedCopyIsPutBack(): void
    {
        $before = $this->snapshot();
        chmod($this->root . '/modules/Base', 0555);

        try {
            $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
            $this->fail('the copy wrote into a directory it cannot write');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not write modules/Base/', $e->getMessage());
        }

        $this->u['apply']($this->root, $this->open('2.0.0'), Tar::files($this->open('2.0.1')));
        chmod($this->root . '/modules/Base', 0755);

        $this->assertSame($before, $this->snapshot(), 'every file as it was, the unwritable directory\'s left alone');
        $this->assertFileDoesNotExist($this->root . '/public/base/new.js', 'what 2.0.1 added before the failure goes');
    }

    public function testTheCopyRefusesATreeThatIsNotOwa(): void
    {
        unlink($this->root . '/owa_env.php');

        $this->expectExceptionMessage('is not an OWA directory');
        $this->u['apply']($this->root, $this->open('2.0.1'), []);
    }

    public function testWithoutAMarkerOwaBoots(): void
    {
        $this->assertNull($this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertFalse($this->u['dropsBeacon']($this->root));
        $this->assertFalse($this->serve(false));
    }

    /** The copy under way, its installer holding the lock: every run of cli.php waits, the scheduler's quietly. */
    public function testWhileTheCopyRunsEveryoneWaits(): void
    {
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1');
        $this->marker('copying');

        try {
            $this->assertSame(75, $this->u['preboot']($this->root, ['cli.php', 'cmd=core-update-status']));
            $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
            $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', '--cmd=schedule-run']), 'the other form cli.php accepts');
            $this->assertTrue($this->u['dropsBeacon']($this->root));
            $this->assertSame('0644', sprintf('%04o', fileperms($this->u['marker']($this->root)) & 0777));
        } finally {
            $this->u['unlock']($hold);
        }
    }

    /** A failed install is tried again, 3 attempts in all; one install recorded twice counts once. */
    public function testAFailedInstallIsTriedAgainThreeTimesInAll(): void
    {
        $at = fn (int $since): array => ['from' => '2.0.0', 'to' => '2.0.1', 'since' => $since];

        $this->u['recordFailure']($this->updates, $at(1), 'first');
        $this->assertSame(1, $this->failure()['attempts']);
        $this->assertNotNull($this->failure()['retry_after']);

        $this->u['recordFailure']($this->updates, $at(1), 'the same install, put back by the next run');
        $this->assertSame(1, $this->failure()['attempts'], 'one install counts once');

        $this->u['recordFailure']($this->updates, $at(2), 'second');
        $this->assertSame([2, true], [$this->failure()['attempts'], $this->failure()['retry_after'] !== null]);

        $this->u['recordFailure']($this->updates, $at(3), 'third');
        $this->assertSame([3, null], [$this->failure()['attempts'], $this->failure()['retry_after']], 'skipped after the third');

        $this->u['recordFailure']($this->updates, ['from' => '2.0.0', 'to' => '2.0.2', 'since' => 4], 'another release');
        $this->assertSame(1, $this->failure()['attempts'], 'counted per release');

        $this->u['recordFailure']($this->updates, $at(5), 'not retried', false);
        $this->assertSame([1, null], [$this->failure()['attempts'], $this->failure()['retry_after']]);
    }

    /** Booted, Finish dying: run again twice, then the failure is recorded and OWA runs with its schema updates pending. */
    public function testFinishIsRunThreeTimesInAll(): void
    {
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $this->marker('booted');

        foreach ([1, 2] as $finishes) {
            $finish = $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']);
            $this->assertIsArray($finish, "run $finishes");
            $this->assertSame($finishes, $this->u['readMarker']($this->root)['finishes']);
            // Finish dies: the process ends, and the kernel lets the lock go.
            flock($finish['hold']['handle'], LOCK_UN);
            fclose($finish['hold']['handle']);
        }

        $this->assertNull($this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']), 'OWA boots');
        $this->assertFileDoesNotExist($this->u['marker']($this->root));
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertSame(self::NEW['Core/Env.php'], file_get_contents($this->root . '/Core/Env.php'), 'nothing is undone once it booted');
        $this->assertSame([true, null], [$this->failure()['booted'], $this->failure()['retry_after']]);
        $this->assertStringContainsString('stopped three times', $this->failure()['error']);
    }

    /** Waiting for the other runs to end: every run of cli.php that starts stops, and the web and beacons carry on, as nothing has changed. */
    public function testWhileTheInstallerWaitsEveryRunStops(): void
    {
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1');
        $this->marker('waiting');

        try {
            $this->assertSame(75, $this->u['preboot']($this->root, ['cli.php', 'cmd=core-update-status']));
            $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
            $this->assertFalse($this->u['dropsBeacon']($this->root));
            $this->assertFalse($this->serve(false));
        } finally {
            // The installer dies waiting: the kernel lets the lock go.
            flock($hold['handle'], LOCK_UN);
            fclose($hold['handle']);
        }

        $this->assertNull($this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']), 'nothing changed: OWA boots');
        $this->assertFileDoesNotExist($this->u['marker']($this->root));
        $this->assertFileDoesNotExist($this->updates . '/last-failure.json', 'and nothing is skipped');
    }

    /** The installer holds cli.php alone only once every other run of cli.php has ended; a run that starts meanwhile is told. */
    public function testTheInstallerWaitsForTheOtherRuns(): void
    {
        $mine = $this->u['running']($this->root);
        $this->assertIsResource($mine);

        // Another run, holding cli.php as every run does, for a second.
        $code = sprintf('$h = (require %s)["running"](%s); echo is_resource($h) ? "held\n" : "not held\n"; sleep(1);',
            var_export($this->root . '/updater.php', true), var_export($this->root, true));
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        $this->assertSame("held\n", fgets($pipes[1]), 'runs share it');

        $this->assertFalse($this->u['alone']($mine, 0), 'the other run is going');
        $started = microtime(true);
        $this->assertTrue($this->u['alone']($mine, 5));
        $this->assertGreaterThan(0.5, microtime(true) - $started, 'once it ended');
        $this->assertSame(0, proc_close($proc));

        $this->assertFalse($this->u['running']($this->root), 'a run that starts now finds the installer holding it');

        flock($mine, LOCK_UN);
        fclose($mine);
        $this->assertTrue($this->u['alone'](null, 0), 'no flock(): nothing is told');
    }

    /** The copy killed part-way: the next run of cli.php puts the installed release back, checked against its signature. */
    public function testAKilledCopyIsPutBack(): void
    {
        $before = $this->snapshot();
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $this->marker('copying');

        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));

        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist($this->root . '/modules/Base/Added.php', 'what 2.0.1 shipped and 2.0.0 does not goes');
        $this->assertFileDoesNotExist($this->u['marker']($this->root));
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertSame(['version' => '2.0.1', 'from' => '2.0.0', 'error' => 'The copy stopped before it finished. OWA 2.0.0 was put back.', 'booted' => false, 'attempts' => 1],
            array_intersect_key($this->failure(), ['version' => 1, 'from' => 1, 'error' => 1, 'booted' => 1, 'attempts' => 1]));
        $this->assertGreaterThanOrEqual(time() + 21600 - 5, $this->failure()['retry_after'], 'tried again 6 hours on');
    }

    /** Copied: the next two runs boot it, counting; the third puts the installed release back. Booted: finish, uncounted. */
    public function testARunBootsTheCopiedReleaseTwiceThenPutsItBack(): void
    {
        $before = $this->snapshot();
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $this->marker('copied');

        foreach ([1, 2] as $boots) {
            $finish = $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']);
            $this->assertIsArray($finish, "boot $boots");
            $this->assertSame($boots, $finish['finish']['boots']);
            $this->assertSame($boots, $this->u['readMarker']($this->root)['boots']);
            $this->assertSame(75, $this->u['preboot']($this->root, ['cli.php', 'cmd=jobs']), 'it holds the lock until it finishes');
            // The boot fails: the process ends, and the kernel lets the lock go.
            flock($finish['hold']['handle'], LOCK_UN);
            fclose($finish['hold']['handle']);
        }

        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('OWA 2.0.1 did not boot. OWA 2.0.0 was put back.', $this->failure()['error']);
        $this->assertNull($this->failure()['retry_after'], 'a release that does not boot is not tried again');

        $this->marker('booted', ['boots' => 2]);
        $finish = $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']);
        $this->assertSame(2, $finish['finish']['boots'], 'booted: Finish is not counted');
        $this->u['unlock']($finish['hold']);
    }

    /** Putting back: the installed release's tarball must check against its signature, or nothing is touched. */
    /** Putting back: the installed release's tarball must be the one the marker names (its SHA-256), or nothing is touched. */
    public function testATarballThatDoesNotCheckIsNotPutBack(): void
    {
        $this->marker('copying');
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $after = $this->snapshot();
        // Someone who can write the data directory replaces it, with its signature: a real release, not the one installed from.
        copy($this->updates . '/owa_2.0.1_packaged.tar', $this->updates . '/owa_2.0.0_packaged.tar');

        $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertSame($after, $this->snapshot(), 'nothing unpacked');
        $this->assertFileExists($this->u['marker']($this->root), 'the marker stays: OWA does not run on a tree it cannot vouch for');
        $this->assertFalse($this->u['isLocked']($this->data));
    }

    /** What is unpacked is what was hashed: a FIFO, or a link, in place of the tarball is refused, never read twice. */
    public function testAPutBackReadsAPlainFileOnce(): void
    {
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $this->marker('copying');
        $tar = $this->updates . '/owa_2.0.0_packaged.tar';
        rename($tar, $tar . '.real');

        foreach (['fifo', 'link'] as $kind) {
            $kind === 'fifo' ? posix_mkfifo($tar, 0644) : symlink($tar . '.real', $tar);
            $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']), $kind);
            $this->assertSame(self::NEW['Core/Env.php'], file_get_contents($this->root . '/Core/Env.php'), "$kind: nothing unpacked");
            unlink($tar);
        }

        rename($tar . '.real', $tar);
        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']), 'the real one');
    }

    /** Putting back checks the hash the installing release recorded, not a signature: a new release that retires a key can still put its predecessor back. */
    public function testAPutBackNeedsNoKey(): void
    {
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $this->marker('copying');
        Signature::$trusted = [base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))];

        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertSame(self::OLD['Core/Env.php'], file_get_contents($this->root . '/Core/Env.php'));

        // Without a recorded hash there is nothing to check it against: nothing is unpacked.
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        $this->marker('copying', ['from_sha256' => '']);
        $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertSame(self::NEW['Core/Env.php'], file_get_contents($this->root . '/Core/Env.php'));
    }

    /** 2.0.1's tarball is not the one installed: 2.0.0 is put back, and what 2.0.1 added stays, since nothing proves which files it shipped. */
    public function testWithoutAProvenNewTarballNothingIsDeleted(): void
    {
        $this->marker('copying');
        $this->u['apply']($this->root, $this->open('2.0.1'), Tar::files($this->open('2.0.0')));
        file_put_contents($this->updates . '/owa_2.0.1_packaged.tar', 'not the tarball', FILE_APPEND);

        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertSame(self::OLD['Core/Env.php'], file_get_contents($this->root . '/Core/Env.php'));
        $this->assertFileExists($this->root . '/modules/Base/Added.php');
    }

    public function testAMarkerThatCannotBeReadStopsEveryRun(): void
    {
        file_put_contents($this->u['marker']($this->root), "<?php exit; ?>\nnot json\n");

        $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertTrue($this->u['dropsBeacon']($this->root));
    }

    /** Until the new release has booted: the copy, and the runs that try to boot it. */
    public function testTheEntryPointsAnswer503UntilTheNewReleaseBoots(): void
    {
        $this->marker('copied');

        $page = (string) $this->serve(false);
        $this->assertStringContainsString('OWA is updating. This takes a minute or two.', $page);
        $this->assertStringNotContainsString('may be stuck', $page);

        $rest = json_decode((string) $this->serve(true), true);
        $this->assertSame(503, $rest['httpResponse']['status_code']);
        $this->assertSame('busy', $rest['error'][0]['code']);

        $this->marker('copied', ['since' => time() - 7201]);
        $page = (string) $this->serve(false);
        $this->assertStringContainsString('may be stuck', $page);
        $this->assertStringNotContainsString($this->updates, $page, 'it names no path');

        // Booted: OWA's pending schema updates stop what they stop, and beacons queue.
        $this->marker('booted');
        $this->assertFalse($this->serve(false));
        $this->assertFalse($this->u['dropsBeacon']($this->root));
    }

    /** The marker is a PHP file that exits: a request for it shows nothing. */
    public function testTheMarkerShowsNothingOverHttp(): void
    {
        $this->marker('copying');

        $this->assertSame('', (string) shell_exec(sprintf('%s -f %s', escapeshellarg(PHP_BINARY), escapeshellarg($this->u['marker']($this->root)))));
    }

    public function testTheLock(): void
    {
        $file = $this->data . '/updating';
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertFileDoesNotExist($file, 'asking creates nothing');

        $umask = umask(077);
        $hold  = $this->u['tryLock']($this->data, 'install 2.0.1');
        umask($umask);
        $this->assertNotNull($hold);
        $this->assertSame('0644', sprintf('%04o', fileperms($file) & 0777), 'readable, whatever the umask');
        $this->assertMatchesRegularExpression('/^install 2\.0\.1, pid \d+, since \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC, token [0-9a-f]{16}\n$/',
            (string) file_get_contents($file), 'the operator can read who holds it');

        $other = shell_exec(sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg(sprintf(
            '$u = require %s; var_export([$u["isLocked"](%2$s), $u["tryLock"](%2$s)]);',
            var_export($this->root . '/updater.php', true), var_export($this->data, true)))));
        $this->assertSame(var_export([true, null], true), $other, 'another process: held, and it cannot take it');

        touch($file, time() - 100);
        $this->u['touchLock']($hold);
        clearstatcache();
        $this->assertGreaterThan(time() - 50, filemtime($file), 'touchLock dates it: the holder is still moving');

        $this->u['unlock']($hold);
        $this->assertFileDoesNotExist($file, 'letting go deletes it');
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertSame([], glob($file . '.*'), 'no temporary file left');
    }

    /** A holder that dies leaves the file, unflocked: the next process takes it over. */
    public function testALockWhoseHolderDiedIsTakenOver(): void
    {
        $file = $this->data . '/updating';
        shell_exec(sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg(sprintf(
            '$u = require %s; $u["tryLock"](%s, "install 2.0.1"); exit(1);',
            var_export($this->root . '/updater.php', true), var_export($this->data, true)))));

        $this->assertFileExists($file, 'it died holding it');
        $left = (string) file_get_contents($file);
        $this->assertSame('left', $this->u['lockState']($file));
        $this->assertFalse($this->u['isLocked']($this->data));

        $inode = fileinode($file);
        $hold  = $this->u['tryLock']($this->data, 'release check');
        $this->assertNotNull($hold);
        $this->assertNotNull($hold['handle'], 'flocked');
        clearstatcache();
        $this->assertSame($inode, fileinode($file), 'taken over in place: updating is never missing');
        $this->assertNotSame($left, file_get_contents($file));
        $this->assertStringStartsWith('release check, pid ', (string) file_get_contents($file));

        $this->u['unlock']($hold);
        $this->assertSame([], glob($this->data . '/updating*'));
    }

    /** A link named updating is never opened, so whatever it names is never written: it is deleted, and the lock made new. */
    public function testALinkIsNeverTakenForTheLock(): void
    {
        $file   = $this->data . '/updating';
        $target = $this->root . '/owa-config.php';
        symlink($target, $file);

        $this->assertSame('left', $this->u['lockState']($file));
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1');

        $this->assertNotNull($hold);
        $this->assertFalse(is_link($file));
        $this->assertSame('<?php // the installation\'s', file_get_contents($target), 'what it named is untouched');
        $this->u['unlock']($hold);
    }

    /** Without flock(): taken by creating it, held while it is fresh, a stale one taken over and asked again a second later. */
    public function testTheLockWithoutFlock(): void
    {
        $file = $this->data . '/updating';
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1', true);
        $this->assertNotNull($hold);
        $this->assertNull($hold['handle'], 'nothing flocked');
        $this->assertNull($this->u['tryLock']($this->data, 'release check', true), 'held while it is fresh');

        touch($file, time() - 601);
        $started = microtime(true);
        $next    = $this->u['tryLock']($this->data, 'release check', true);
        $this->assertNotNull($next, 'a stale one is taken over');
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started, 'and asked again a second later');
        $this->assertStringStartsWith('release check, pid ', (string) file_get_contents($file));

        $this->u['unlock']($hold);
        $this->assertFileExists($file, 'the old holder does not delete its successor\'s');
        $this->u['unlock']($next);
        $this->assertSame([], glob($file . '*'), 'nothing left, nothing aside');
    }

    /** The second look: a holder whose line has gone from the file no longer holds the lock. */
    public function testStillMine(): void
    {
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1', true);
        $this->assertTrue($this->u['stillMine']($hold));

        file_put_contents($this->data . '/updating', "release check, pid 1, since 2026-11-22 10:00:00 UTC, token 0000000000000000\n");
        $this->assertFalse($this->u['stillMine']($hold), 'another process took it');
    }

    /** Where the filesystem has no flock(), the file's age says whether its holder is gone: 10 minutes. */
    public function testWithoutFlockTheFilesAgeSays(): void
    {
        $file = $this->data . '/updating';
        $this->assertSame(600, $this->u['lock_stale']);
        $this->assertSame('free', $this->u['lockState']($file, true));

        file_put_contents($file, "install 2.0.1, pid 1, since 2026-11-22 10:00:00 UTC, 0000000000000000\n");
        touch($file, time() - 590);
        $this->assertSame('held', $this->u['lockState']($file, true));

        touch($file, time() - 601);
        $this->assertSame('left', $this->u['lockState']($file, true));
    }

    // ---------------------------------------------------------------------

    /** A release's tarball and signature in the updates directory, as Step 1 leaves them. */
    private function release(string $v, array $files): void
    {
        $dir = $this->tmp . "/build-$v";
        $this->tree($dir, $files);
        $tar = $this->updates . "/owa_{$v}_packaged.tar";
        exec(sprintf('tar --format=gnu -C %s -cf %s ./ 2>&1', escapeshellarg($dir), escapeshellarg($tar)));
        file_put_contents("$tar.sig", Signature::sign($v, hash_file('sha256', $tar), $this->signingKey) . "\n");
    }

    /** @return resource */
    private function open(string $v)
    {
        return fopen($this->updates . "/owa_{$v}_packaged.tar", 'rb');
    }

    /** The install marker, as Install writes it, 2.0.0 to 2.0.1. */
    private function marker(string $phase, array $more = []): void
    {
        $this->u['writeMarker']($this->root, $more + [
            'format'   => 1,
            'from'     => '2.0.0',
            'to'       => '2.0.1',
            'phase'    => $phase,
            'since'    => time(),
            'lock'     => $this->data . '/updating',
            'updates'  => $this->updates,
            'from_tar' => $this->updates . '/owa_2.0.0_packaged.tar',
            'to_tar'   => $this->updates . '/owa_2.0.1_packaged.tar',
            // As Install writes them: the SHA-256 it proved of each.
            'from_sha256' => hash_file('sha256', $this->updates . '/owa_2.0.0_packaged.tar'),
            'to_sha256'   => hash_file('sha256', $this->updates . '/owa_2.0.1_packaged.tar'),
            'boots'    => 0,
        ]);
    }

    /** What serveMaintenance prints, or false when it lets OWA boot. */
    private function serve(bool $rest): string|false
    {
        ob_start();
        $served = $this->u['serveMaintenance']($this->root, $rest);
        $out    = (string) ob_get_clean();

        return $served ? $out : false;
    }

    /** Every file under the OWA directory but the marker and updater.php, with its contents. */
    private function snapshot(): array
    {
        $out = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $path => $info) {
            $rel = substr((string) $path, strlen($this->root) + 1);
            if ($info->isFile() && $rel !== 'owa-update.php') {
                $out[$rel] = file_get_contents((string) $path);
            }
        }
        ksort($out);

        return $out;
    }

    private function leftovers(): array
    {
        return array_values(array_filter(array_keys($this->snapshot()), static fn ($p) => str_ends_with($p, '.owa-update') || str_contains($p, '.tmp')));
    }

    private function failure(): array
    {
        return json_decode((string) file_get_contents($this->updates . '/last-failure.json'), true);
    }

    private function tree(string $dir, array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0755, true);
            file_put_contents($dir . '/' . $path, $content);
        }
    }
}
