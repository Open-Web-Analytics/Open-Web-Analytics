<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Steps 2 and 3 of automatic updates: updater.php's hand-off and install.
 *
 * Each install runs in a process of its own, as cli.php runs it, against a
 * throwaway installed tree and staging: the INSTALLED tree's updater.php
 * hands off to the STAGED release's. The release's owa.php stands in for
 * Step 4: it defines Classes\AutoUpdate\Finish, which records that it ran
 * (or, for the release that does not boot, fails).
 */
final class InstallerTest extends TestCase
{
    private string $tmp = '';
    private string $root = '';
    private string $data = '';
    private string $updates = '';
    private int $now = 0;
    private string $out = '';

    /** @var array<string, \Closure> updater.php's closures, for the parts that need no process */
    private array $u = [];

    protected function setUp(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('needs the posix extension');
        }

        $this->tmp     = sys_get_temp_dir() . '/owa-installer-' . bin2hex(random_bytes(4));
        $this->root    = $this->tmp . '/owa';
        $this->data    = $this->tmp . '/data';
        $this->now     = time();
        $this->u       = require dirname(__DIR__) . '/updater.php';

        mkdir($this->root, 0755, true);
        mkdir($this->data, 0755, true);
        $this->updates = (string) $this->u['updatesDir']($this->data, true);

        // The installed release, 2.0.0, and what belongs to this installation.
        $this->tree($this->root, [
            'cli.php'                          => 'old cli',
            'owa_env.php'                      => 'env',
            'Core/Env.php'                     => "<?php\nfinal class Env {\n    const VERSION = '2.0.0';\n}\n",
            'modules/Base/Module.php'          => 'old module',
            'modules/Base/Retired.php'         => 'a file 2.0.1 no longer has',
            'modules/Hello/Module.php'         => 'a module OWA retired',
            'modules/ThirdParty/Module.php'    => 'someone else\'s module',
            'public/base/old.js'               => 'a built asset 2.0.1 does not ship',
            'public/tracker/OWA-site.js'       => 'a tracking bundle',
            'notes.txt'                        => 'a file left at the top level',
            'same.php'                         => 'unchanged',
            'owa-data/logs/errors.txt'         => 'the default data directory',
        ]);
        chmod($this->root . '/cli.php', 0700);
        copy(dirname(__DIR__) . '/updater.php', $this->root . '/updater.php');
        file_put_contents($this->root . '/owa-config.php', "<?php\ndefine('OWA_DATA_DIR', '{$this->data}/');\n");
        file_put_contents($this->root . '/owa.php', '<?php // the installed release');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            exec('chmod -R u+w ' . escapeshellarg($this->tmp) . '; rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testTheUpdatesDirectoryIsFoundNotGuessed(): void
    {
        $this->assertMatchesRegularExpression('#/data/updates-[0-9a-f]{32}$#', $this->updates);
        $this->assertSame('0711', sprintf('%04o', fileperms($this->updates) & 0777), 'others open a name they know, list nothing');
        $this->assertSame($this->updates, $this->u['updatesDir']($this->data), 'found again, never derived');
        $this->assertSame($this->updates, $this->u['updatesDir']($this->data, true), 'created once');

        mkdir($this->data . '/updates-' . str_repeat('c', 32), 0700);
        $this->assertNull($this->u['updatesDir']($this->data), 'two: neither is guessed at');
        $this->assertNull($this->u['updatesDir']($this->data, true), 'nor a third made');
    }

    public function testTheConfigIsIncludedAsBootIncludesIt(): void
    {
        $out = shell_exec(sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg(sprintf(
            '$c = (require %s)["config"](%s); echo json_encode($c), defined("OWA_DATA_DIR") ? " defined" : "";',
            var_export($this->root . '/updater.php', true), var_export($this->root, true)))));
        $this->assertSame(json_encode(['data' => $this->data, 'public' => $this->root . '/public']) . ' defined', $out);

        file_put_contents($this->root . '/owa-config.php', "<?php\n\$this->x = 1;\n");
        $out = shell_exec(sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg(sprintf(
            'var_export((require %s)["config"](%s));', var_export($this->root . '/updater.php', true), var_export($this->root, true)))));
        $this->assertSame('NULL', $out, 'a file using $this is refused, not run');
    }

    /** A config naming an OWA class would fatal before OWA loads, or load the old class into the new release's process. */
    public function testAConfigNamingAnOwaClassIsRefused(): void
    {
        foreach (["define('X', \\OWA\\Core\\Env::root());", "use OWA\\Core\\Env;\ndefine('X', Env::root());"] as $line) {
            file_put_contents($this->root . '/owa-config.php', "<?php\n" . $line . "\n");
            $this->assertNotNull($this->u['configRefused']($this->root), $line);
        }

        $this->stage('2.0.1');

        $this->assertSame(99, $this->handoff(), 'no fatal: the run boots normally - ' . $this->out);
        $this->assertFileDoesNotExist($this->updates . '/install.json', 'and installs nothing');
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.1']));
        $this->assertStringContainsString('owa-config.php uses OWA\\Core\\Env.', $this->out, 'by hand, it says why');

        file_put_contents($this->root . '/owa-config.php', "<?php\ndefine('OWA_DATA_DIR', '{$this->data}/');\n// \\OWA\\Core\\Env in a comment\n");
        $this->assertNull($this->u['configRefused']($this->root), 'a comment is not code');
    }

    public function testTheBackOff(): void
    {
        $this->assertSame([60, 180, 600, 900, 7200, 86400, 604800, 604800], array_map($this->u['backoff'], [1, 2, 3, 4, 5, 6, 7, 12]));
    }

    public function testWhatIsDue(): void
    {
        $due = $this->u['due'];

        $this->assertNull($due($this->updates, $this->now), 'nothing staged');

        $this->stage('2.0.1');
        $this->assertSame('2.0.1', $due($this->updates, $this->now), 'no window: at once');

        // 03:00 on a Saturday in London.
        $sat3 = (new DateTimeImmutable('2026-11-21 03:00', new DateTimeZone('Europe/London')))->getTimestamp();
        $this->stage('2.0.1', ['days' => [6, 7], 'start' => '02:00', 'end' => '06:00', 'timezone' => 'Europe/London']);
        $this->assertSame('2.0.1', $due($this->updates, $sat3));
        $this->assertNull($due($this->updates, $sat3 + 3 * 3600), 'after the window');
        $this->assertNull($due($this->updates, $sat3 - 2 * 86400), 'not on a Thursday');

        // 23:00-02:00 starting Saturday: 01:00 Sunday is still Saturday's window.
        $this->stage('2.0.1', ['days' => [6], 'start' => '23:00', 'end' => '02:00', 'timezone' => 'Europe/London']);
        $this->assertSame('2.0.1', $due($this->updates, $sat3 + 22 * 3600), '01:00 Sunday');
        $this->assertNull($due($this->updates, $sat3 - 2 * 3600), '01:00 Saturday belongs to Friday');
    }

    /** An unfinished install comes first, on its back-off, whatever staged.json says; nothing new starts meanwhile. */
    public function testAnUnfinishedInstallComesFirst(): void
    {
        $this->stage('2.0.2');
        $this->stage('2.0.1', null, false);
        $this->unfinished('2.0.1', $this->now + 60);

        $this->assertNull($this->u['due']($this->updates, $this->now));
        $this->assertSame('2.0.1', $this->u['due']($this->updates, $this->now + 60));
    }

    public function testNothingStagedBootsNormally(): void
    {
        $this->assertSame(99, $this->handoff());
    }

    /** Copied (cli.php last, installed modes kept), what it does not ship deleted, what is the installation's kept, then Step 4. */
    public function testAnInstall(): void
    {
        $this->stage('2.0.1');

        $this->assertSame(0, $this->handoff(), $this->out);

        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
        $this->assertSame('0700', sprintf('%04o', fileperms($this->root . '/cli.php') & 0777), 'the installed mode stays');
        $this->assertSame('new module', file_get_contents($this->root . '/modules/Base/Module.php'));
        $this->assertSame('added', file_get_contents($this->root . '/modules/Base/Added.php'));
        $this->assertSame('2.0.1', file_get_contents($this->root . '/finished.txt'), 'Step 4 ran, in the new code');
        $this->assertFileDoesNotExist($this->data . '/updating', 'the lock is let go when the install is done');

        foreach (['modules/Base/Retired.php', 'modules/Hello', 'public/base/old.js', 'notes.txt'] as $gone) {
            $this->assertFileDoesNotExist($this->root . '/' . $gone, "$gone is not shipped and not the installation's");
        }

        foreach (['owa-config.php', 'owa-data/logs/errors.txt', 'public/tracker/OWA-site.js', 'modules/ThirdParty/Module.php'] as $kept) {
            $this->assertFileExists($this->root . '/' . $kept, "$kept is the installation's");
        }

        $install = $this->install();
        $this->assertSame(['installing', '2.0.1', 1, true, true], [$install['state'], $install['version'], $install['attempts'], $install['copied'], $install['booted']]);
        $this->assertEqualsWithDelta($this->now + 60, $install['retry_after'], 5, 'set at the claim, so a process that dies retries a minute later');
        $this->assertSame('old cli', file_get_contents($this->updates . '/backup/cli.php'));
        $this->assertSame('a file 2.0.1 no longer has', file_get_contents($this->updates . '/backup/modules/Base/Retired.php'), 'what it deleted is backed up too');
        $this->assertFileDoesNotExist($this->updates . '/backup/same.php', 'an unchanged file is not touched');
    }

    /** A copy error is undone at once, counted, and the old release boots; what it added waits for the next copy. */
    public function testACopyErrorIsUndone(): void
    {
        $this->stage('2.0.1', null, true, ['modules/Locked/New.php' => 'cannot be written']);
        mkdir($this->root . '/modules/Locked', 0555);

        $this->assertSame(99, $this->handoff(), $this->out);

        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $this->assertSame('old module', file_get_contents($this->root . '/modules/Base/Module.php'));
        $this->assertFileExists($this->root . '/modules/Base/Retired.php');
        $this->assertFileDoesNotExist($this->root . '/finished.txt');

        $install = $this->install();
        $this->assertSame([1, false, false], [$install['attempts'], $install['copied'], $install['booted']]);
        $this->assertStringContainsString('Could not write modules/Locked/New.php', $install['error']);
        $this->assertStringContainsString('The previous release was put back', $install['error']);
        $this->assertFileDoesNotExist($this->data . '/updating', 'a failed attempt lets the lock go too');
        $this->assertSame('0644', sprintf('%04o', fileperms($this->updates . '/install.json') & 0777), 'readable by the Updates screen');
        $this->assertSame('0700', sprintf('%04o', fileperms($this->updates . '/backup') & 0777), 'the originals: not readable');

        $this->assertSame(99, $this->handoff(), 'not due again until its retry_after');

        chmod($this->root . '/modules/Locked', 0755);
        $this->assertSame(0, $this->handoff([], 60), $this->out);
        $this->assertSame(2, $this->install()['attempts']);
        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
    }

    /** A file the copy backed up and then could not replace is unchanged: nothing to put back, and the undo says the release was. */
    public function testAFileTheCopyCouldNotReplaceIsNotPutBack(): void
    {
        $this->tree($this->root, ['modules/Locked/Old.php' => 'old']);
        $this->stage('2.0.1', null, true, ['modules/Locked/Old.php' => 'new']);
        chmod($this->root . '/modules/Locked', 0555);

        try {
            $this->assertSame(99, $this->handoff(), $this->out);
        } finally {
            chmod($this->root . '/modules/Locked', 0755);
        }

        $this->assertSame('old', file_get_contents($this->root . '/modules/Locked/Old.php'));
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $error = $this->install()['error'];
        $this->assertStringContainsString('Could not write modules/Locked/Old.php', $error);
        $this->assertStringContainsString('The previous release was put back', $error, 'nothing failed to go back');
    }

    /** A release that does not boot is undone from the shutdown: no schema can have changed. */
    public function testAReleaseThatDoesNotBootIsUndone(): void
    {
        $this->stage('2.0.1', null, true, [], "<?php\nthrow new \\Error('this release does not boot');\n");

        $this->handoff();

        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $this->assertFileExists($this->root . '/modules/Base/Retired.php');
        $this->assertSame('<?php // the installed release', file_get_contents($this->root . '/owa.php'));

        $install = $this->install();
        $this->assertStringContainsString('stopped before it booted: Uncaught Error: this release does not boot', $install['error']);
        $this->assertFalse($install['copied']);
        $this->assertFalse($this->u['isLocked']($this->data), 'a process that died holds nothing: its file is left over');
    }

    /** An unfinished install whose staging is gone is undone; the old release runs and Step 1 stages it again. */
    public function testAnUnfinishedInstallWithoutItsStagingIsUndone(): void
    {
        mkdir($this->updates . '/backup', 0700, true);
        file_put_contents($this->updates . '/backup/cli.php', 'old cli');
        file_put_contents($this->root . '/cli.php', 'half copied');
        $this->unfinished('2.0.1', 0);

        $this->assertSame(99, $this->handoff());
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $this->assertStringContainsString('Its staging is gone', $this->install()['error']);
    }

    public function testNothingRunsFromAStagingOthersCanWrite(): void
    {
        $this->stage('2.0.1');
        chmod($this->updates . '/staged', 0770);
        $this->assertSame(99, $this->handoff());

        chmod($this->updates . '/staged', 0700);
        chmod($this->updates . '/staged/updater.php', 0666);
        $this->assertSame(99, $this->handoff());
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
    }

    /** It deletes what the release does not ship, so it refuses a tree that is not OWA. */
    public function testItRefusesATreeThatIsNotOwa(): void
    {
        $this->stage('2.0.1');
        unlink($this->root . '/owa_env.php');

        $this->assertSame(1, $this->handoff());
        $this->assertStringContainsString('is not an OWA directory', $this->out);
        $this->assertFileExists($this->root . '/notes.txt');
    }

    /** While an install holds the lock, every other run exits: schedule-run quietly. */
    /** The lock file exists while one process holds it, says who, and is gone when it lets go. */
    public function testTheLock(): void
    {
        $file = $this->data . '/updating';
        $this->assertFalse($this->u['isLocked']($this->data));
        $this->assertFileDoesNotExist($file, 'asking creates nothing');

        $umask = umask(077);
        $hold  = $this->u['tryLock']($this->data, 'install 2.0.1');
        umask($umask);
        $this->assertNotNull($hold);
        $this->assertSame('0644', sprintf('%04o', fileperms($file) & 0777), 'the web user reads it, whatever the umask');
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

    /** The phase at the end of the line: what the entry points read before OWA loads. */
    public function testThePhase(): void
    {
        $hold = $this->u['tryLock']($this->data, 'install 2.0.1');
        $this->assertNull($this->u['lockStatus']($this->data)['phase']);

        $hold = $this->u['setLockPhase']($hold, 'copying');
        $this->assertStringEndsWith(', copying', trim((string) file_get_contents($this->data . '/updating')));
        $this->assertSame('copying', $this->u['lockStatus']($this->data)['phase']);

        $hold = $this->u['setLockPhase']($hold, 'copied');
        $this->assertSame('copied', $this->u['lockStatus']($this->data)['phase']);
        $this->assertStringNotContainsString('copying', (string) file_get_contents($this->data . '/updating'), 'one phase at a time');
        $this->assertTrue($this->u['stillMine']($hold), 'a new phase is the same holder');

        $this->u['unlock']($hold);
        $this->assertNull($this->u['lockStatus']($this->data));
        $this->assertFileDoesNotExist($this->data . '/updating');
    }

    /** Step 4 runs with the copy done and marked so: log.php boots again from there. */
    public function testStep4RunsMarkedCopied(): void
    {
        $this->stage('2.0.1', null, true, [], "<?php\nnamespace OWA\\Module\\Base\\Classes\\AutoUpdate;\nclass Finish {\n    public static function run(array \$c) {\n"
            . "        file_put_contents(" . var_export($this->root . '/finished.txt', true) . ", (string) file_get_contents(" . var_export($this->data . '/updating', true) . "));\n"
            . "        return 0;\n    }\n}\n");

        $this->assertSame(0, $this->handoff(), $this->out);
        $this->assertMatchesRegularExpression('/^install 2\.0\.1, pid \d+, since .+ UTC, token [0-9a-f]{16}, copied\n$/', (string) file_get_contents($this->root . '/finished.txt'));
    }

    /** index.php and the REST API before OWA loads: 503 while the lock is held, and a stuck install says who holds it. */
    public function testTheMaintenanceAnswers(): void
    {
        $serve = function (bool $rest): string {
            return (string) shell_exec(sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg(sprintf(
                '$u = require %s; var_export($u["serveMaintenance"](%s, %s)); echo "|";',
                var_export($this->root . '/updater.php', true), var_export($this->root, true), var_export($rest, true)))));
        };

        $this->assertSame('false|', $serve(false), 'no lock: OWA boots');

        $hold = $this->u['tryLock']($this->data, 'install 2.0.1');
        $hold = $this->u['setLockPhase']($hold, 'copied');

        $page = $serve(false);
        $this->assertStringContainsString('OWA is updating. This takes a minute or two.', $page);
        $this->assertStringNotContainsString('may be stuck', $page);
        $this->assertStringEndsWith('true|', $page);

        $rest = json_decode(substr($serve(true), 0, -5), true);
        $this->assertSame(503, $rest['httpResponse']['status_code']);
        $this->assertSame('busy', $rest['error'][0]['code']);

        touch($this->data . '/updating', time() - 7201);
        $this->assertStringContainsString('may be stuck. The lock file, owa-data/updating, says: <code>install 2.0.1, pid ', $serve(false));

        $this->u['unlock']($hold);
    }

    /** log.php drops a beacon only while an install copies files; at any other time OWA boots. */
    public function testLogPhpDropsBeaconsOnlyDuringTheCopy(): void
    {
        $drops = fn (): string => (string) shell_exec(sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg(sprintf(
            'var_export((require %s)["dropsBeacon"](%s));', var_export($this->root . '/updater.php', true), var_export($this->root, true)))));

        $this->assertSame('false', $drops());

        $hold = $this->u['tryLock']($this->data, 'release check');
        $this->assertSame('false', $drops(), 'Step 1 writing: the files are whole');

        $hold = $this->u['setLockPhase']($hold, 'copying');
        $this->assertSame('true', $drops());

        $hold = $this->u['setLockPhase']($hold, 'copied');
        $this->assertSame('false', $drops(), 'the schema update: OWA boots, and queues');

        $this->u['unlock']($hold);
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

    /** Step 1 holding the lock to write: an install that is due waits for a later run, and then installs. */
    public function testAnInstallWaitsForStep1(): void
    {
        $this->stage('2.0.1');
        $hold = $this->u['tryLock']($this->data);

        try {
            $this->assertSame(0, $this->handoff());
            $this->assertFileDoesNotExist($this->updates . '/install.json', 'nothing claimed');
        } finally {
            $this->u['unlock']($hold);
        }

        $this->assertSame(0, $this->handoff(), $this->out);
        $this->assertSame('2.0.1', file_get_contents($this->root . '/finished.txt'));
    }

    /** An install starts only for a release newer than the installed 2.0.0; by hand, --force goes back. */
    public function testOnlyANewerReleaseInstalls(): void
    {
        $this->stage('2.0.0');
        $this->assertSame(99, $this->handoff(), 'the same release: nothing installs, OWA boots');
        $this->assertFileDoesNotExist($this->updates . '/install.json');

        $this->stage('1.9.0', null, false);
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=1.9.0']));
        $this->assertStringContainsString('1.9.0 is not newer than the installed 2.0.0; add --force to install it anyway.', $this->out);
        $this->assertFileDoesNotExist($this->updates . '/install.json', 'nothing claimed');

        $this->assertSame(0, $this->handoff(['cmd=self-update-install', 'version=1.9.0', '--force']), $this->out);
        $this->assertSame('1.9.0', file_get_contents($this->root . '/finished.txt'), 'going back, by hand');
        $this->assertTrue($this->install()['forced'], 'its retries carry on');
    }

    /** 2.0.1 staged for the window, 2.0.2 installed by hand before it: the hand-off deletes 2.0.1, and its unfinished install. */
    public function testAStagingTheInstalledReleaseWentPastIsDropped(): void
    {
        $this->stage('2.0.1');
        $this->unfinished('2.0.1', $this->now - 1);
        mkdir($this->updates . '/backup', 0700);
        file_put_contents($this->root . '/Core/Env.php', "<?php\nfinal class Env {\n    const VERSION = '2.0.2';\n}\n");

        $this->assertSame(99, $this->handoff(), 'OWA boots: ' . $this->out);
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'), 'nothing copied: no downgrade');
        $this->assertFileDoesNotExist($this->updates . '/staged.json');
        $this->assertFileDoesNotExist($this->updates . '/install.json');
        $this->assertDirectoryDoesNotExist($this->updates . '/backup');
        $this->assertDirectoryDoesNotExist($this->updates . '/staged');
        $this->assertFileDoesNotExist($this->data . '/updating', 'the lock is let go');
    }

    /** By hand, a release that is not newer is refused and nothing is deleted: --force is still there to add. */
    public function testByHandNothingIsDropped(): void
    {
        $this->stage('2.0.0', null, false);
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.0']));
        $this->assertDirectoryExists($this->updates . '/staged');
    }

    /** A forced downgrade's retries carry on while Core/Env.php still says the newer release. */
    public function testAForcedInstallsRetriesCarryOn(): void
    {
        $this->stage('1.9.0', null, false);
        file_put_contents($this->updates . '/install.json', json_encode([
            'state' => 'installing', 'version' => '1.9.0', 'attempts' => 1, 'retry_after' => $this->now - 1, 'forced' => true,
        ]));

        $this->assertSame(0, $this->handoff(), $this->out);
        $this->assertSame('1.9.0', file_get_contents($this->root . '/finished.txt'));
    }

    /** A retry carries on although the copy already replaced Core/Env.php with the release's. */
    public function testAnUnfinishedInstallCarriesOnWhateverEnvSays(): void
    {
        $this->stage('2.0.1');
        $this->unfinished('2.0.1', $this->now - 1);
        file_put_contents($this->root . '/Core/Env.php', "<?php\nfinal class Env {\n    const VERSION = '2.0.1';\n}\n");

        $this->assertSame(0, $this->handoff(), $this->out);
        $this->assertSame('2.0.1', file_get_contents($this->root . '/finished.txt'));
    }

    /** An installed version that cannot be read (a checkout says master) starts nothing, unless forced. */
    public function testAnUnreadableInstalledVersionStartsNothing(): void
    {
        file_put_contents($this->root . '/Core/Env.php', "<?php\nfinal class Env {\n    const VERSION = 'master';\n}\n");
        $this->stage('2.0.1');

        $this->assertSame(99, $this->handoff());
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.1']));
        $this->assertStringContainsString('The installed version cannot be read from Core/Env.php; add --force to install 2.0.1 anyway.', $this->out);
    }

    /** staged.json says automatic false (staged by hand, or waiting): the hand-off never installs it on its own; self-update-install does. */
    public function testOnlyAReleaseThatInstallsItselfIsDue(): void
    {
        $this->stage('2.0.1', null, false);

        $this->assertSame(99, $this->handoff(), 'not due');
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));

        $this->assertSame(0, $this->handoff(['cmd=self-update-install', 'version=2.0.1']), $this->out);
        $this->assertSame('2.0.1', file_get_contents($this->root . '/finished.txt'));
    }

    public function testEveryOtherRunWaitsForAnInstall(): void
    {
        $h = fopen($this->data . '/updating', 'c');
        flock($h, LOCK_EX);

        try {
            $this->assertSame(75, $this->handoff(['cmd=self-update']));
            $this->assertStringContainsString('installing an update', $this->out);
            $this->assertSame(0, $this->handoff(['cmd=schedule-run']));
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public function testInstallByHand(): void
    {
        $this->assertSame(2, $this->handoff(['cmd=self-update-install']));
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.1']));
        $this->assertStringContainsString('2.0.1 is not staged: stage it first (cmd=self-update-stage version=2.0.1).', $this->out);

        // An unfinished install of another version comes first.
        $this->stage('2.0.2', null, false);
        $this->unfinished('2.0.1', $this->now + 600000);
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.2']));
        $this->assertStringContainsString('An install of OWA 2.0.1 is unfinished', $this->out);

        // The same version starts over with fresh attempts, whatever its retry_after.
        $this->stage('2.0.1', null, false);
        $this->unfinished('2.0.1', $this->now + 600000, 5);
        $this->assertSame(0, $this->handoff(['cmd=self-update-install', 'version=2.0.1']), $this->out);
        $this->assertSame(1, $this->install()['attempts']);

        file_put_contents($this->root . '/.git', 'gitdir: elsewhere');
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.1']));
        $this->assertStringContainsString('version control checkout', $this->out);
    }

    // ---------------------------------------------------------------------

    /**
     * The installed tree's updater.php `handoff`, in a process of its own as
     * cli.php runs it: its exit code, 99 for "boot normally". $later moves
     * install.json's retry_after back by that many seconds, as the clock
     * moving on would.
     */
    private function handoff(array $argv = ['cmd=schedule-run'], int $later = 0): int
    {
        if ($later && is_file($this->updates . '/install.json')) {
            $i = $this->install();
            $i['retry_after'] -= $later;
            file_put_contents($this->updates . '/install.json', json_encode($i));
        }

        $script = sprintf('$h = (require %s)["handoff"]; $r = $h(%s, %s); exit($r === null ? 99 : $r);',
            var_export($this->root . '/updater.php', true), var_export($this->root, true), var_export(array_merge(['cli.php'], $argv), true));

        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        $this->out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return proc_close($proc);
    }

    /** The one staged release, as Step 1 leaves it, installing itself; $point false: as staged by hand, installed only by self-update-install. */
    private function stage(string $v, ?array $window = null, bool $point = true, array $extra = [], ?string $owa = null): void
    {
        $dir = $this->updates . '/staged';
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir, 0700, true);

        $owa ??= "<?php\nnamespace OWA\\Module\\Base\\Classes\\AutoUpdate;\nclass Finish {\n    public static function run(array \$c) {\n"
            . "        (\$c['record'])(['booted' => true]);\n        file_put_contents(\$c['root'] . '/finished.txt', \$c['version']);\n        return 0;\n    }\n}\n";

        $this->tree($dir, $extra + [
            'cli.php'                 => 'new cli',
            'owa_env.php'             => 'env',
            'Core/Env.php'            => "<?php\nfinal class Env {\n    const VERSION = '$v';\n}\n",
            'modules/Base/Module.php' => 'new module',
            'modules/Base/Added.php'  => 'added',
            'public/base/new.js'      => 'a built asset',
            'same.php'                => 'unchanged',
            'release.json'            => json_encode(['version' => $v]),
            'owa.php'                 => $owa,
        ]);
        chmod($dir . '/cli.php', 0755);
        copy(dirname(__DIR__) . '/updater.php', $dir . '/updater.php');
        chmod($dir . '/updater.php', 0644);

        file_put_contents($this->updates . '/staged.json', json_encode(['format' => 1, 'version' => $v, 'window' => $window,
            'sha256' => str_repeat('a', 64), 'staged_at' => $this->now, 'automatic' => $point, 'by_hand' => ! $point]));
    }

    private function unfinished(string $v, int $retry_after, int $attempts = 1): void
    {
        file_put_contents($this->updates . '/install.json', json_encode([
            'state' => 'installing', 'version' => $v, 'attempts' => $attempts, 'retry_after' => $retry_after, 'error' => 'before',
        ]));
    }

    private function tree(string $dir, array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0755, true);
            file_put_contents($dir . '/' . $path, $content);
        }
    }

    private function install(): array
    {
        return json_decode((string) file_get_contents($this->updates . '/install.json'), true);
    }
}
