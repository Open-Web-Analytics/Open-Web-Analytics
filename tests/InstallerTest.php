<?php

use OWA\Module\Base\Classes\AutoUpdate\Signature;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Automatic updates before OWA loads, and the copy: updater.php's closures
 * (the copy, the install marker, preboot, putting a release back, the lock,
 * the entry points' answers).
 *
 * Against a throwaway installed tree, 2.0.0, and 2.0.1's signed tarball as
 * the packager makes it. A failed copy is put back from the backup the copy
 * makes first, never from a tarball.
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
        'public/base/chunks/a.js' => 'a directory 2.0.1 does not ship',
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
            'modules/ThirdParty/Module.php' => 'a module put in OWA\'s modules/',
            'extensions/Acme/Module.php'    => 'someone else\'s module',
            '.htpasswd'                     => 'the operator\'s',
            'robots.txt'                    => 'the operator\'s',
            '.well-known/security.txt'      => 'the operator\'s',
            'api/extra.php'                 => 'in a directory 2.0.1 does not ship',
            'Core/Planted.php'              => 'in a directory 2.0.1 ships',
        ]);
        chmod($this->root . '/cli.php', 0700);
        copy(dirname(__DIR__) . '/updater.php', $this->root . '/updater.php');

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

    /**
     * 2.0.1 over 2.0.0: what differs is written, and in each directory 2.0.1
     * ships the files it does not ship go, with the directories that leaves
     * empty: modules/ is OWA's alone, so a module it does not ship goes too.
     * Nothing else is touched: files at the top, directories it does not
     * ship, extensions/, owa-data/, the tracking bundles.
     */
    public function testTheCopyMirrorsTheReleaseInItsDirectories(): void
    {
        touch($this->root . '/same.php', 1700000000);

        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));

        foreach (self::NEW as $path => $content) {
            $this->assertSame($content, file_get_contents($this->root . '/' . $path), $path);
        }

        clearstatcache();
        $this->assertSame(1700000000, filemtime($this->root . '/same.php'), 'a file that is already the release\'s is left as it is');
        $this->assertSame('0700', sprintf('%04o', fileperms($this->root . '/cli.php') & 0777), 'a replaced file keeps its mode');

        foreach (['modules/Base/Retired.php', 'public/base/old.js', 'Core/Planted.php', 'modules/Gone/Module.php', 'modules/ThirdParty/Module.php'] as $gone) {
            $this->assertFileDoesNotExist($this->root . '/' . $gone, '2.0.1 does not ship it, in a directory it ships');
        }
        foreach (['public/base/chunks', 'modules/Gone', 'modules/ThirdParty'] as $dir) {
            $this->assertDirectoryDoesNotExist($this->root . '/' . $dir, 'the directory it leaves empty goes too');
        }

        foreach (['owa-config.php', 'robots.txt', '.htpasswd', '.well-known/security.txt', 'updater.php', 'owa-data/logs/errors.txt',
            'public/tracker/OWA-site.js', 'extensions/Acme/Module.php', 'api/extra.php'] as $kept) {
            $this->assertFileExists($this->root . '/' . $kept, 'outside the directories 2.0.1 ships, or kept: never deleted');
        }

        $this->assertSame([], $this->leftovers(), 'no temporary file left');
    }

    /** What the mirror deletes, decided before anything is written: a link is deleted, never followed; a FIFO is left; the data directory and the bundles, wherever OWA_DATA_DIR and OWA_PUBLIC_PATH put them, are kept. */
    public function testThePlan(): void
    {
        $victim = $this->tmp . '/victim';
        mkdir($victim);
        file_put_contents($victim . '/file.php', 'the cron user\'s');
        symlink($victim, $this->root . '/Core/linked');
        symlink($victim . '/file.php', $this->root . '/public/base/linked.js');
        posix_mkfifo($this->root . '/Core/Env.php.owa-update', 0644); // no release's file: left as it is
        $this->tree($this->root, [
            'Core/data/logs/errors.txt'       => 'OWA_DATA_DIR in a directory the release ships',
            'public/alt/tracker/OWA-site.js'  => 'a bundle under OWA_PUBLIC_PATH',
            'public/alt/other.js'             => 'not a bundle',
        ]);

        $plan = $this->u['plan']($this->root, $this->open('2.0.1'), $this->u['keeps']($this->root, $this->root . '/Core/data', $this->root . '/public/alt'));

        $this->assertSame(['Core/Planted.php', 'Core/linked', 'modules/Base/Retired.php', 'modules/Gone/Module.php', 'modules/ThirdParty/Module.php',
            'public/alt/other.js', 'public/base/chunks/a.js', 'public/base/linked.js', 'public/base/old.js'], $plan['deletes'], 'never extensions/');
        $this->assertEqualsCanonicalizing(['cli.php', 'Core/Env.php', 'modules/Base/Module.php', 'modules/Base/Added.php', 'public/base/new.js', 'owa.php'], $plan['writes'],
            'only what differs');
        $this->assertSame(array_sum(array_map(fn ($p) => strlen(self::OLD[$p] ?? ''), ['cli.php', 'Core/Env.php', 'modules/Base/Module.php', 'owa.php', 'modules/Base/Retired.php', 'public/base/old.js', 'public/base/chunks/a.js', 'modules/Gone/Module.php']))
            + strlen('in a directory 2.0.1 ships') + strlen('not a bundle') + strlen('a module put in OWA\'s modules/'), $plan['bytes'], 'what the backup holds; a link holds nothing');

        unlink($this->root . '/Core/Env.php.owa-update');
        $this->u['apply']($this->root, $this->open('2.0.1'), $plan);
        $this->assertSame('the cron user\'s', file_get_contents($victim . '/file.php'), 'what a link names is untouched');
        $this->assertFileExists($this->root . '/Core/data/logs/errors.txt');
        $this->assertFileExists($this->root . '/public/alt/tracker/OWA-site.js');

        // The data directory as the OWA directory itself: nothing is deleted.
        $this->assertSame([], $this->u['plan']($this->root, $this->open('2.0.1'), $this->u['keeps']($this->root, $this->root, null))['deletes']);
    }

    /** extensions/ is third-party code: a tarball that holds it writes nothing there, and the mirror never deletes there. */
    public function testTheCopyNeverTouchesExtensions(): void
    {
        $this->release('2.0.2', self::NEW + ['extensions/Acme/Module.php' => 'not the extension\'s', 'extensions/Shipped/Module.php' => 'shipped']);

        $plan = $this->plan('2.0.2');
        $this->assertSame([], preg_grep('#^extensions/#', array_merge($plan['writes'], $plan['deletes'])));
        $this->u['apply']($this->root, $this->open('2.0.2'), $plan);

        $this->assertSame('someone else\'s module', file_get_contents($this->root . '/extensions/Acme/Module.php'));
        $this->assertFileDoesNotExist($this->root . '/extensions/Shipped/Module.php');
    }

    /** The copy changes only what the plan backed up: a file that changed after it stops the copy. */
    public function testTheCopyChangesOnlyWhatWasPlanned(): void
    {
        $plan = $this->plan('2.0.1');
        file_put_contents($this->root . '/same.php', 'changed after the plan');

        try {
            $this->u['apply']($this->root, $this->open('2.0.1'), $plan);
            $this->fail('the copy wrote a file it had not backed up');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('same.php changed after the install was prepared', $e->getMessage());
        }
        $this->assertSame('changed after the plan', file_get_contents($this->root . '/same.php'));
    }

    /** Every file the copy overwrites or deletes, copied into a new directory in the updates directory, its SHA-256 and mode for the marker; what it creates, and the directories it makes, named. */
    public function testTheBackup(): void
    {
        $plan  = $this->plan('2.0.1');
        $taken = $this->u['backup']($this->root, $this->updates, $plan);

        $this->assertMatchesRegularExpression('#^' . preg_quote($this->updates, '#') . '/backup-[0-9a-f]{32}$#', $taken['backup']);
        $this->assertSame('0700', sprintf('%04o', fileperms($taken['backup']) & 0777));
        $this->assertEqualsCanonicalizing(['cli.php', 'Core/Env.php', 'modules/Base/Module.php', 'owa.php', 'Core/Planted.php', 'modules/Base/Retired.php', 'public/base/chunks/a.js', 'public/base/old.js',
            'modules/Gone/Module.php', 'modules/ThirdParty/Module.php'],
            array_keys($taken['saved']));
        foreach ($taken['saved'] as $rel => $entry) {
            $this->assertSame(hash_file('sha256', $this->root . '/' . $rel), $entry['sha256'], $rel);
            $this->assertSame(file_get_contents($this->root . '/' . $rel), file_get_contents($taken['backup'] . '/' . $rel), $rel);
            $this->assertSame('0600', sprintf('%04o', fileperms($taken['backup'] . '/' . $rel) & 0777), $rel);
        }
        $this->assertSame(0700, $taken['saved']['cli.php']['mode']);
        $this->assertEqualsCanonicalizing(['modules/Base/Added.php', 'public/base/new.js'], $taken['created']);
        $this->assertSame([], $taken['made'], 'every directory 2.0.1 ships is there');

        // An earlier install's backup goes when the next is made.
        $next = $this->u['backup']($this->root, $this->updates, $plan);
        $this->assertDirectoryDoesNotExist($taken['backup']);
        $this->assertSame([$next['backup']], glob($this->updates . '/backup-*'));
    }

    /** No room for the backup: refused before anything is made. */
    public function testABackupWithoutRoomIsRefused(): void
    {
        $plan = ['bytes' => PHP_INT_MAX >> 1] + $this->plan('2.0.1');

        try {
            $this->u['backup']($this->root, $this->updates, $plan);
            $this->fail('a backup larger than the disk was made');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/^Backing up the files the copy replaces needs about \d+ MB free on the disk holding .+; \d+ MB are\.$/', $e->getMessage());
        }
        $this->assertSame([], glob($this->updates . '/backup-*'));

        $this->assertMatchesRegularExpression('/^Backing up the files the copy replaces needs about \d+ MB free/',
            \OWA\Module\Base\Classes\AutoUpdate\Preflight::backup(PHP_INT_MAX >> 1, $this->updates)[0], 'and the installer refuses it before the copy');
        $this->assertSame([], \OWA\Module\Base\Classes\AutoUpdate\Preflight::backup(1024, $this->updates));
    }

    /** A backup left over is deleted following no link: whoever can write the data directory cannot point the deletion elsewhere. */
    public function testALeftoverBackupIsDeletedFollowingNoLink(): void
    {
        $victim = $this->tmp . '/victim';
        mkdir($victim);
        file_put_contents($victim . '/keep.php', 'the cron user\'s');
        $left = $this->updates . '/backup-' . str_repeat('a', 32);
        mkdir($left . '/Core', 0700, true);
        file_put_contents($left . '/Core/Env.php', 'left over');
        symlink($victim, $left . '/linked');
        symlink($victim, $this->updates . '/backup-' . str_repeat('b', 32));

        $this->u['removeBackups']($this->updates);

        $this->assertSame([], glob($this->updates . '/backup-*'));
        $this->assertSame('the cron user\'s', file_get_contents($victim . '/keep.php'));
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

        $plan              = $this->plan('2.0.2');
        $plan['deletes'][] = 'owa-data/logs/errors.txt';
        $this->u['apply']($this->root, $this->open('2.0.2'), $plan);

        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
        $this->assertSame('the cron user\'s', file_get_contents($this->tmp . '/victim.php'));
        $this->assertSame([], array_diff((array) scandir($this->tmp . '/elsewhere'), ['.', '..']));
        $this->assertFileDoesNotExist($this->root . '/owa-data/index.php', 'not written');
        $this->assertFileExists($this->root . '/owa-data/logs/errors.txt', 'not deleted, though a plan names it');
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

    /** A copy that cannot write is put back from the backup: exactly the files it changed, what it created deleted, the backup gone. */
    public function testAFailedCopyIsPutBack(): void
    {
        $before = $this->snapshot();
        $m      = $this->backedUp('copying');
        chmod($this->root . '/modules/Base', 0555);

        try {
            $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));
            $this->fail('the copy wrote into a directory it cannot write');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not write modules/Base/', $e->getMessage());
        } finally {
            chmod($this->root . '/modules/Base', 0755);
        }

        $this->u['restore']($this->root, $m);

        $this->assertSame($before, $this->snapshot(), 'every file as it was');
        $this->assertFileDoesNotExist($this->root . '/public/base/new.js', 'what 2.0.1 added before the failure goes');
        $this->assertDirectoryDoesNotExist($m['backup'], 'the backup goes once it is put back');
        $this->assertSame([], $this->leftovers());
    }

    /** Putting back writes only what differs from the backup, and a directory the copy made goes. */
    public function testAPutBackWritesOnlyWhatChanged(): void
    {
        $this->release('2.0.2', self::NEW + ['modules/Base/New/Deep.php' => 'in directories 2.0.0 does not have']);
        $before = $this->snapshot();
        $m      = $this->backedUp('copying', '2.0.2');
        $this->assertContains('modules/Base/New', $m['made']);
        $this->u['apply']($this->root, $this->open('2.0.2'), $this->plan('2.0.2'));
        touch($this->root . '/same.php', 1700000000);

        $this->u['restore']($this->root, $m);

        clearstatcache();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1700000000, filemtime($this->root . '/same.php'), 'not in the backup: not written');
        $this->assertSame('0700', sprintf('%04o', fileperms($this->root . '/cli.php') & 0777), 'put back with its mode');
        $this->assertDirectoryDoesNotExist($this->root . '/modules/Base/New');
        $this->assertDirectoryExists($this->root . '/public/base/chunks', 'a directory the copy emptied is made again');
    }

    public function testTheCopyRefusesATreeThatIsNotOwa(): void
    {
        unlink($this->root . '/owa_env.php');

        $this->expectExceptionMessage('is not an OWA directory');
        $this->u['apply']($this->root, $this->open('2.0.1'), ['writes' => [], 'deletes' => []]);
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

    /** The record says where the install stopped: its stage from the marker's phase, Finish's step, and the install's log with the failure last. */
    /**
     * The web server can rename the updates directory and put a link in its
     * place, or a FIFO at last-failure.json: nothing is written through the
     * link, and the FIFO is not read (which would hang the run holding the lock).
     */
    public function testAFailureIsNotRecordedThroughALinkNorReadFromAFifo(): void
    {
        $target = $this->data . '/elsewhere';
        mkdir($target);
        $link = $this->data . '/updates-link';
        symlink($target, $link);

        $this->u['recordFailure']($link, ['from' => '2.0.0', 'to' => '2.0.1', 'since' => 1], 'a test');

        $this->assertSame([], glob($target . '/*'), 'nothing written where the link points');

        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->updates . '/last-failure.json', 0644);

            $this->u['recordFailure']($this->updates, ['from' => '2.0.0', 'to' => '2.0.1', 'since' => 1], 'a test');

            $this->assertSame('a test', json_decode((string) file_get_contents($this->updates . '/last-failure.json'), true)['error'],
                'recorded over it, without reading it first');
        }

        unlink($link);
        rmdir($target);
    }

    public function testAFailureRecordsWhereTheInstallStopped(): void
    {
        $m = $this->u['logged'](['from' => '2.0.0', 'to' => '2.0.1', 'since' => 1], 'Release check (by hand), OWA 2.0.0 installed.');
        $m = $this->u['logged']($m, 'Copying OWA 2.0.1\'s files over 2.0.0\'s.');

        foreach ([['waiting', 'copy', null], ['copying', 'copy', null], ['copied', 'boot', null]] as [$phase, $stage, $step]) {
            $this->u['recordFailure']($this->updates, ['phase' => $phase] + $m, 'it failed');
            $this->assertSame([$stage, $step], [$this->failure()['stage'], $this->failure()['step']], $phase);
        }

        $this->u['recordFailure']($this->updates, ['phase' => 'booted', 'step' => 'trackers'] + $m, 'the bundles were not published', false, true);
        $this->assertSame(['finish', 'trackers'], [$this->failure()['stage'], $this->failure()['step']]);

        $log = $this->failure()['log'];
        $this->assertCount(3, $log);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC Release check \(by hand\)/', $log[0]);
        $this->assertStringEndsWith('UTC Failed: the bundles were not published', $log[2]);
    }

    /** The log keeps its last 100 lines, one line each. */
    public function testTheLogIsBounded(): void
    {
        $m = [];

        for ($i = 1; $i <= 105; $i++) {
            $m = $this->u['logged']($m, "step $i\nwith a second line");
        }

        $this->assertCount(100, $m['log']);
        $this->assertStringEndsWith('UTC step 6 with a second line', $m['log'][0]);
    }

    /** Booted, Finish dying: run again twice, then the failure is recorded and OWA runs with its schema updates pending. */
    public function testFinishIsRunThreeTimesInAll(): void
    {
        $m = $this->backedUp('booted');
        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));

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
        $this->assertDirectoryDoesNotExist($m['backup'], 'so the backup goes');
        $this->assertSame([true, null], [$this->failure()['booted'], $this->failure()['retry_after']]);
        $this->assertStringContainsString('stopped three times', $this->failure()['error']);
        $this->assertSame(['finish', 'schema'], [$this->failure()['stage'], $this->failure()['step']], 'a marker naming no step: the schema updates, the first');
        $this->assertCount(2, preg_grep('/Finishing the install of OWA 2\.0\.1 again \(try [12] of 2\)/', $this->failure()['log']), 'each run is in the log');
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

    /** The copy killed part-way: the next run of cli.php puts the installed release back from the backup, with no tarball, and what the copy left half written goes. */
    public function testAKilledCopyIsPutBack(): void
    {
        $before = $this->snapshot();
        $m      = $this->backedUp('copying');
        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));
        file_put_contents($this->root . '/modules/Base/Module.php.owa-update', 'half written when it was killed');
        array_map('unlink', glob($this->updates . '/owa_*'));

        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));

        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist($this->root . '/modules/Base/Added.php', 'what 2.0.1 shipped and 2.0.0 does not goes');
        $this->assertFileDoesNotExist($this->u['marker']($this->root));
        $this->assertDirectoryDoesNotExist($m['backup']);
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertSame(['version' => '2.0.1', 'from' => '2.0.0', 'error' => 'The copy stopped before it finished. OWA 2.0.0 was put back.', 'booted' => false, 'attempts' => 1],
            array_intersect_key($this->failure(), ['version' => 1, 'from' => 1, 'error' => 1, 'booted' => 1, 'attempts' => 1]));
        $this->assertGreaterThanOrEqual(time() + 21600 - 5, $this->failure()['retry_after'], 'tried again 6 hours on');
    }

    /** Copied: the next two runs boot it, counting; the third puts the installed release back. Booted: finish, uncounted. */
    public function testARunBootsTheCopiedReleaseTwiceThenPutsItBack(): void
    {
        $before = $this->snapshot();
        $this->backedUp('copied');
        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));

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

    /**
     * The backup is in the data directory, which the web user can write:
     * every file is checked against the marker's SHA-256 before any is
     * written. One changed, missing, a link or a FIFO, and nothing is: the
     * marker stays, the failure lists the files and names the tarball to
     * unpack by hand.
     */
    public function testATamperedBackupIsNotPutBack(): void
    {
        $m = $this->backedUp('copying');
        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));
        $after = $this->snapshot();
        $saved = $m['backup'] . '/modules/Base/Module.php';
        rename($saved, $saved . '.real');

        $cases = [
            'changed' => fn () => file_put_contents($saved, '<?php // the web user\'s'),
            'missing' => fn () => null,
            'a link'  => fn () => symlink($saved . '.real', $saved),
            'a FIFO'  => fn () => posix_mkfifo($saved, 0644),
        ];

        foreach ($cases as $case => $tamper) {
            $tamper();
            $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']), $case);
            $this->assertSame($after, $this->snapshot(), "$case: nothing written, not even the files that check");
            $this->assertFileExists($this->u['marker']($this->root), "$case: the marker stays, so OWA does not run on the mixed files");
            $this->assertFalse($this->u['isLocked']($this->data));
            $this->assertStringContainsString('1 of the 10 files backed up before the copy are missing or changed', $this->failure()['error'], $case);
            $this->assertStringContainsString(': modules/Base/Module.php.', $this->failure()['error'], "$case: the file is named");
            @unlink($saved);
        }

        rename($saved . '.real', $saved);
        $this->assertSame(0, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']), 'the backup as it was made');
        $this->assertSame(self::OLD['Core/Env.php'], file_get_contents($this->root . '/Core/Env.php'));
    }

    /** What the failure says to do when the backup cannot be put back: unpack the installed release's tarball by hand. */
    public function testABrokenPutBackNamesTheTarball(): void
    {
        $m = $this->backedUp('copying');
        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));
        exec('rm -rf ' . escapeshellarg($m['backup']));

        $err = $this->runPreboot();

        $this->assertStringContainsString('Putting OWA 2.0.0 back failed: ', $err);
        $this->assertStringContainsString('download https://updates.openwebanalytics.com/download/2.0.0/owa_2.0.0_packaged.tar, unpack it over ' . $this->root, $err);
        $this->assertStringContainsString('Putting OWA 2.0.0 back failed: 10 of the 10 files backed up before the copy are missing or changed', $this->failure()['error']);
        $this->assertFileExists($this->u['marker']($this->root), 'the maintenance page stays');
    }

    /** A marker that names no backup puts nothing back. */
    public function testAMarkerWithoutABackupPutsNothingBack(): void
    {
        $this->marker('copying');

        $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertFileExists($this->u['marker']($this->root));
        $this->assertStringContainsString('names no backup', $this->failure()['error']);
    }

    public function testAMarkerThatCannotBeReadStopsEveryRun(): void
    {
        file_put_contents($this->u['marker']($this->root), "<?php exit; ?>\nnot json\n");

        $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=schedule-run']));
        $this->assertTrue($this->u['dropsBeacon']($this->root));
        $this->assertNotFalse($this->serve(false), 'the web answers 503 too');
    }

    /** Each web entry point asks updater.php before it loads any other file: mid-copy, any other may be the new release's. */
    public function testEveryWebEntryPointAsksTheUpdaterFirst(): void
    {
        foreach (['index.php', 'install.php', 'log.php', 'api/index.php'] as $entry) {
            preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*;/', (string) file_get_contents(dirname(__DIR__) . '/' . $entry), $m);
            $this->assertNotEmpty($m[0], $entry);
            $this->assertStringContainsString("/updater.php')['", $m[0][0], "$entry loads something before updater.php");
            $this->assertMatchesRegularExpression("/\\['(serveMaintenance|dropsBeacon)'\\]/", $m[0][0], $entry);
        }
    }

    /** The 503 says when to come back, and is never cached. */
    public function testTheMaintenancePageSaysWhenToTryAgain(): void
    {
        $cgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
        if ($cgi === '') {
            $this->markTestSkipped('php-cgi is not installed');
        }

        $this->marker('copying');
        $script = $this->tmp . '/serve.php';
        file_put_contents($script, sprintf("<?php\n\$u = require %s;\n\$u['serveMaintenance'](%s);\n", var_export(dirname(__DIR__) . '/updater.php', true), var_export($this->root, true)));

        $proc = proc_open([$cgi, '-d', 'display_errors=0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp,
            ['REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $script, 'REDIRECT_STATUS' => '200', 'PATH' => (string) getenv('PATH')]);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $headers = (string) strstr($out, "\r\n\r\n", true);

        $this->assertStringContainsString('Status: 503', $headers);
        $this->assertStringContainsString('Retry-After: 60', $headers);
        $this->assertStringContainsString('Cache-Control: no-store', $headers);
    }

    /** Putting back waits for whoever holds the lock: nothing changes, and the run says try again. */
    public function testAPutBackWaitsForTheLock(): void
    {
        $this->backedUp('copied', '2.0.1', ['boots' => 2]);
        $this->u['apply']($this->root, $this->open('2.0.1'), $this->plan('2.0.1'));
        $after = $this->snapshot();
        $hold  = $this->u['tryLock']($this->data, 'another run');

        try {
            $this->assertSame(75, $this->u['preboot']($this->root, ['cli.php', 'cmd=core-update-status']));
            $this->assertSame($after, $this->snapshot());
            $this->assertFileExists($this->u['marker']($this->root));
        } finally {
            $this->u['unlock']($hold);
        }
    }

    /** A boot that cannot count itself stops: counting is what ends a release that never boots. */
    public function testABootThatCannotWriteTheMarkerStops(): void
    {
        $this->marker('copied');
        chmod($this->root, 0555);

        try {
            $this->assertSame(1, $this->u['preboot']($this->root, ['cli.php', 'cmd=core-update-status']));
        } finally {
            chmod($this->root, 0755);
        }

        $this->assertSame(0, $this->u['readMarker']($this->root)['boots']);
        $this->assertFalse($this->u['isLocked']($this->data));
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
        $file = $this->data . '/owa-update-lock.php';
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertFileDoesNotExist($file, 'asking creates nothing');

        $umask = umask(077);
        $hold  = $this->u['tryLock']($this->data, 'install 2.0.1');
        umask($umask);
        $this->assertNotNull($hold);
        $this->assertSame('0644', sprintf('%04o', fileperms($file) & 0777), 'readable, whatever the umask');
        $this->assertMatchesRegularExpression('/^<\?php exit; \?>\ninstall 2\.0\.1, pid \d+, since \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC, token [0-9a-f]{16}\n$/',
            (string) file_get_contents($file), 'the operator can read who holds it');
        $this->assertSame('', (string) shell_exec(sprintf('%s -f %s', escapeshellarg(PHP_BINARY), escapeshellarg($file))),
            'it is in the OWA directory: a request for it runs PHP that exits, and shows nothing');

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
        $file = $this->data . '/owa-update-lock.php';
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
        $this->assertSame($inode, fileinode($file), 'taken over in place: the lock is never missing');
        $this->assertNotSame($left, file_get_contents($file));
        $this->assertStringStartsWith("<?php exit; ?>\nrelease check, pid ", (string) file_get_contents($file));

        $this->u['unlock']($hold);
        $this->assertSame([], glob($this->data . '/owa-update-lock.php*'));
    }

    /** A link named updating is never opened, so whatever it names is never written: it is deleted, and the lock made new. */
    public function testALinkIsNeverTakenForTheLock(): void
    {
        $file   = $this->data . '/owa-update-lock.php';
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
        $file = $this->data . '/owa-update-lock.php';
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1', true);
        $this->assertNotNull($hold);
        $this->assertNull($hold['handle'], 'nothing flocked');
        $this->assertNull($this->u['tryLock']($this->data, 'release check', true), 'held while it is fresh');

        touch($file, time() - 601);
        $started = microtime(true);
        $next    = $this->u['tryLock']($this->data, 'release check', true);
        $this->assertNotNull($next, 'a stale one is taken over');
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started, 'and asked again a second later');
        $this->assertStringStartsWith("<?php exit; ?>\nrelease check, pid ", (string) file_get_contents($file));

        $this->u['unlock']($hold);
        $this->assertFileExists($file, 'the old holder does not delete its successor\'s');
        $this->u['unlock']($next);
        $this->assertSame([], glob($file . '*'), 'nothing left, nothing aside');
    }

    /** Without flock(): a stale lock taken over, then written by another process in the second before the second look, is that process's. */
    public function testATakeoverWithoutFlockThatAnotherProcessOvertakesIsGivenUp(): void
    {
        $file = $this->data . '/owa-update-lock.php';
        file_put_contents($file, "install 2.0.1, pid 1, since 2026-11-22 10:00:00 UTC, token 0000000000000000\n");
        touch($file, time() - 601);

        // Once the takeover's line is in the file, the other process writes its own.
        $other = proc_open([PHP_BINARY, '-r', sprintf(
            '$f = %s; for ($i = 0; $i < 300; $i++) { if (str_contains((string) @file_get_contents($f), "\nrelease check, ")) { file_put_contents($f, "another run, pid 2, since 2026-11-22 10:00:00 UTC, token 1111111111111111\n"); exit(0); } usleep(10000); } exit(1);',
            var_export($file, true)
        )], [], $pipes);

        $this->assertNull($this->u['tryLock']($this->data, 'release check', true));
        $this->assertSame(0, proc_close($other), 'the other process never saw the takeover');
        $this->assertStringStartsWith('another run, ', (string) file_get_contents($file), 'left as the other process wrote it');
    }

    /** The second look: a holder whose line has gone from the file no longer holds the lock. */
    public function testStillMine(): void
    {
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1', true);
        $this->assertTrue($this->u['stillMine']($hold));

        file_put_contents($this->data . '/owa-update-lock.php', "release check, pid 1, since 2026-11-22 10:00:00 UTC, token 0000000000000000\n");
        $this->assertFalse($this->u['stillMine']($hold), 'another process took it');
    }

    /** Where the filesystem has no flock(), the file's age says whether its holder is gone: 10 minutes. */
    public function testWithoutFlockTheFilesAgeSays(): void
    {
        $file = $this->data . '/owa-update-lock.php';
        $this->assertSame(600, $this->u['lock_stale']);
        $this->assertSame('free', $this->u['lockState']($file, true));

        file_put_contents($file, "install 2.0.1, pid 1, since 2026-11-22 10:00:00 UTC, 0000000000000000\n");
        touch($file, time() - 590);
        $this->assertSame('held', $this->u['lockState']($file, true));

        touch($file, time() - 601);
        $this->assertSame('left', $this->u['lockState']($file, true));
    }

    // ---------------------------------------------------------------------

    /** A release's tarball and signature in the updates directory, as the release check leaves them. */
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
            'lock'     => $this->data . '/owa-update-lock.php',
            'updates'  => $this->updates,
            'boots'    => 0,
        ]);
    }

    /** What the copy of $v changes, as Install::prepare() decides it. */
    private function plan(string $v): array
    {
        return $this->u['plan']($this->root, $this->open($v), $this->u['keeps']($this->root, $this->data, ''));
    }

    /** As Install::run() does before the copy: the backup, and the marker naming it. @return array the marker */
    private function backedUp(string $phase, string $v = '2.0.1', array $more = []): array
    {
        $m = $more + $this->u['backup']($this->root, $this->updates, $this->plan($v)) + ['format' => 1, 'from' => '2.0.0', 'to' => $v,
            'phase' => $phase, 'since' => time(), 'lock' => $this->data . '/owa-update-lock.php', 'updates' => $this->updates, 'boots' => 0];
        $this->u['writeMarker']($this->root, $m);

        return $m;
    }

    /** preboot in a child process, as cli.php runs it: what it says on STDERR. */
    private function runPreboot(): string
    {
        $code = sprintf('exit((int) (require %s)["preboot"](%s, ["cli.php", "cmd=schedule-run"]));',
            var_export($this->root . '/updater.php', true), var_export($this->root, true));
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);

        return $err;
    }

    /** What serveMaintenance prints, or false when it lets OWA boot. */
    private function serve(bool $rest): string|false
    {
        ob_start();
        $served = $this->u['serveMaintenance']($this->root, $rest);
        $out    = (string) ob_get_clean();

        return $served ? $out : false;
    }

    /** Every file under the OWA directory but the marker, with its contents. */
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
