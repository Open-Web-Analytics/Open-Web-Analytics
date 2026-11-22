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
        $this->assertSame('0700', sprintf('%04o', fileperms($this->updates) & 0777));
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

        foreach (['modules/Base/Retired.php', 'modules/Hello', 'public/base/old.js', 'notes.txt'] as $gone) {
            $this->assertFileDoesNotExist($this->root . '/' . $gone, "$gone is not shipped and not the installation's");
        }

        foreach (['owa-config.php', 'owa-data/logs/errors.txt', 'public/tracker/OWA-site.js', 'modules/ThirdParty/Module.php'] as $kept) {
            $this->assertFileExists($this->root . '/' . $kept, "$kept is the installation's");
        }

        $install = $this->install();
        $this->assertSame(['installing', '2.0.1', 1, true, true], [$install['state'], $install['version'], $install['attempts'], $install['copied'], $install['booted']]);
        $this->assertEqualsWithDelta($this->now + 60, $install['retry_after'], 5, 'set at the claim, so a process that dies retries a minute later');
        $this->assertSame('old cli', file_get_contents($this->updates . '/install/backup/cli.php'));
        $this->assertSame('a file 2.0.1 no longer has', file_get_contents($this->updates . '/install/backup/modules/Base/Retired.php'), 'what it deleted is backed up too');
        $this->assertFileDoesNotExist($this->updates . '/install/backup/same.php', 'an unchanged file is not touched');
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

        $this->assertSame(99, $this->handoff(), 'not due again until its retry_after');

        chmod($this->root . '/modules/Locked', 0755);
        $this->assertSame(0, $this->handoff([], 60), $this->out);
        $this->assertSame(2, $this->install()['attempts']);
        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
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
    }

    /** An unfinished install whose staging is gone is undone; the old release runs and Step 1 stages it again. */
    public function testAnUnfinishedInstallWithoutItsStagingIsUndone(): void
    {
        mkdir($this->updates . '/install/backup', 0700, true);
        file_put_contents($this->updates . '/install/backup/cli.php', 'old cli');
        file_put_contents($this->root . '/cli.php', 'half copied');
        $this->unfinished('2.0.1', 0);

        $this->assertSame(99, $this->handoff());
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $this->assertStringContainsString('Its staging is gone', $this->install()['error']);
    }

    public function testNothingRunsFromAStagingOthersCanWrite(): void
    {
        $this->stage('2.0.1');
        chmod($this->updates . '/2.0.1', 0770);
        $this->assertSame(99, $this->handoff());

        chmod($this->updates . '/2.0.1', 0700);
        chmod($this->updates . '/2.0.1/release/updater.php', 0666);
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
        $this->assertStringContainsString('stage the release first', $this->out);

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
        if ($later && is_file($this->updates . '/install/install.json')) {
            $i = $this->install();
            $i['retry_after'] -= $later;
            file_put_contents($this->updates . '/install/install.json', json_encode($i));
        }

        $script = sprintf('$h = (require %s)["handoff"]; $r = $h(%s, %s); exit($r === null ? 99 : $r);',
            var_export($this->root . '/updater.php', true), var_export($this->root, true), var_export(array_merge(['cli.php'], $argv), true));

        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        $this->out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return proc_close($proc);
    }

    /** A staged release, as Step 1 leaves it, and staged.json pointing at it (unless $point is false). */
    private function stage(string $v, ?array $window = null, bool $point = true, array $extra = [], ?string $owa = null): void
    {
        $dir = $this->updates . "/$v";
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir . '/release', 0700, true);

        $owa ??= "<?php\nnamespace OWA\\Module\\Base\\Classes\\AutoUpdate;\nclass Finish {\n    public static function run(array \$c) {\n"
            . "        (\$c['record'])(['booted' => true]);\n        file_put_contents(\$c['root'] . '/finished.txt', \$c['version']);\n        return 0;\n    }\n}\n";

        $this->tree($dir . '/release', $extra + [
            'cli.php'                 => 'new cli',
            'owa_env.php'             => 'env',
            'modules/Base/Module.php' => 'new module',
            'modules/Base/Added.php'  => 'added',
            'public/base/new.js'      => 'a built asset',
            'same.php'                => 'unchanged',
            'release.json'            => json_encode(['version' => $v]),
            'owa.php'                 => $owa,
        ]);
        chmod($dir . '/release/cli.php', 0755);
        copy(dirname(__DIR__) . '/updater.php', $dir . '/release/updater.php');
        chmod($dir . '/release/updater.php', 0644);

        if ($point) {
            file_put_contents($this->updates . '/staged.json', json_encode(['format' => 1, 'version' => $v, 'window' => $window, 'sha256' => str_repeat('a', 64)]));
        }
    }

    private function unfinished(string $v, int $retry_after, int $attempts = 1): void
    {
        @mkdir($this->updates . '/install', 0700);
        file_put_contents($this->updates . '/install/install.json', json_encode([
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
        return json_decode((string) file_get_contents($this->updates . '/install/install.json'), true);
    }
}
