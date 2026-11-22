<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\ReleaseManifest;

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
    private int $now = 0;

    /** @var array<string, \Closure> updater.php's closures, for the parts that need no process */
    private array $u = [];

    protected function setUp(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('needs the posix extension');
        }

        $this->tmp  = sys_get_temp_dir() . '/owa-installer-' . bin2hex(random_bytes(4));
        $this->root = $this->tmp . '/owa';
        $this->data = $this->tmp . '/data';
        $this->now  = time();
        $this->u    = require dirname(__DIR__) . '/updater.php';

        mkdir($this->root . '/modules/Base', 0755, true);
        mkdir($this->data, 0755, true);
        mkdir($this->data . '/updates', 0700);

        // The installed release: 2.0.0.
        $this->tree($this->root, [
            'cli.php'                  => 'old cli',
            'modules/Base/Module.php'  => 'old module',
            'modules/Base/Retired.php' => 'a file 2.0.1 no longer has',
            'same.php'                 => 'unchanged',
        ]);
        chmod($this->root . '/cli.php', 0700);
        copy(dirname(__DIR__) . '/updater.php', $this->root . '/updater.php');
        file_put_contents($this->root . '/manifest.json', json_encode(ReleaseManifest::build($this->root)));
        file_put_contents($this->root . '/owa-config.php', "<?php\ndefine('OWA_DATA_DIR', '{$this->data}/');\n");
        file_put_contents($this->root . '/owa.php', '<?php // the installed release');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            exec('chmod -R u+w ' . escapeshellarg($this->tmp) . '; rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testTheDataDirectoryIsReadWithoutOwa(): void
    {
        $this->assertSame($this->data . '/', $this->u['dataDir']($this->root));

        file_put_contents($this->root . '/owa-config.php', "<?php\ndefine('OWA_DATA_DIR', getenv('X'));\n");
        $this->assertSame($this->root . '/owa-data/', $this->u['dataDir']($this->root), 'not a literal: the default');
    }

    public function testTheBackOff(): void
    {
        $this->assertSame([900, 7200, 86400, 604800, 604800], array_map($this->u['backoff'], [1, 2, 3, 4, 9]));
    }

    public function testWhatIsDue(): void
    {
        $due = $this->u['due'];
        $dir = $this->data . '/updates';

        $this->assertNull($due($dir, $this->now), 'nothing staged');

        $this->stage('2.0.1');
        $this->assertSame(['version' => '2.0.1', 'retry' => false], $due($dir, $this->now), 'no window: at once');

        // 03:00 on a Saturday in London.
        $sat3 = (new DateTimeImmutable('2026-11-21 03:00', new DateTimeZone('Europe/London')))->getTimestamp();
        $this->stage('2.0.1', ['days' => [6, 7], 'start' => '02:00', 'end' => '06:00', 'timezone' => 'Europe/London']);
        $this->assertNotNull($due($dir, $sat3));
        $this->assertNull($due($dir, $sat3 + 3 * 3600), 'after the window');
        $this->assertNull($due($dir, $sat3 - 2 * 86400), 'not on a Thursday');

        // 23:00-02:00 starting Saturday: 01:00 Sunday is still Saturday's window.
        $this->stage('2.0.1', ['days' => [6], 'start' => '23:00', 'end' => '02:00', 'timezone' => 'Europe/London']);
        $this->assertNotNull($due($dir, $sat3 + 22 * 3600), '01:00 Sunday');
        $this->assertNull($due($dir, $sat3 - 2 * 3600), '01:00 Saturday belongs to Friday');

        $this->stage('2.0.1', null, ['preflight' => ['passed' => false]]);
        $this->assertNull($due($dir, $this->now), 'not while its checks fail');
        $this->stage('2.0.1', null, ['manual_install' => [['module' => 'base', 'sequence' => 96, 'manual_install' => 'by hand']]]);
        $this->assertNull($due($dir, $this->now), 'nor when it must be installed by hand');
    }

    /** A failing install is retried on its back-off, whatever staged.json says, and nothing new starts meanwhile. */
    public function testARetryComesFirst(): void
    {
        $due = $this->u['due'];
        $this->stage('2.0.2');
        $this->stage('2.0.1', null, [], false);
        file_put_contents($this->data . '/updates/2.0.1/install.json', json_encode(['state' => 'installing', 'retry_after' => $this->now + 60]));

        $this->assertNull($due($this->data . '/updates', $this->now));
        $this->assertSame(['version' => '2.0.1', 'retry' => true], $due($this->data . '/updates', $this->now + 60));
    }

    public function testNothingStagedBootsNormally(): void
    {
        $this->assertSame(99, $this->handoff());
    }

    /** Backed up, copied (cli.php last, installed modes kept), the retired file removed, then Step 4. */
    public function testAnInstall(): void
    {
        $this->stage('2.0.1');

        $this->assertSame(0, $this->handoff(), $this->out);

        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
        $this->assertSame('0700', sprintf('%04o', fileperms($this->root . '/cli.php') & 0777), 'the installed mode stays');
        $this->assertSame('new module', file_get_contents($this->root . '/modules/Base/Module.php'));
        $this->assertSame('added', file_get_contents($this->root . '/modules/Base/Added.php'));
        $this->assertFileDoesNotExist($this->root . '/modules/Base/Retired.php');
        $this->assertSame(json_decode(file_get_contents($this->data . '/updates/2.0.1/release/manifest.json'), true),
            json_decode(file_get_contents($this->root . '/manifest.json'), true), 'the next install reads it');
        $this->assertSame('2.0.1', file_get_contents($this->root . '/finished.txt'), 'Step 4 ran, in the new code');

        $install = $this->install('2.0.1');
        $this->assertSame(['installing', 1, true, true], [$install['state'], $install['attempts'], $install['copied'], $install['booted']]);
        $this->assertSame($this->now + 900, $install['retry_after'], 'set at the claim, so a process that dies has waited its turn');
        $this->assertSame('old cli', file_get_contents($this->data . '/updates/2.0.1/backup/cli.php'));
        $this->assertFileDoesNotExist($this->data . '/updates/2.0.1/backup/same.php', 'an unchanged file is not touched');
        $this->assertSame(['modules/Base/Added.php'], json_decode(file_get_contents($this->data . '/updates/2.0.1/backup/added.json'), true));
    }

    /** A copy error is restored at once, counted, and the old release boots. */
    public function testACopyErrorIsRestored(): void
    {
        $this->stage('2.0.1', null, [], true, ['modules/Locked/New.php' => 'cannot be written']);
        mkdir($this->root . '/modules/Locked', 0555);

        $this->assertSame(99, $this->handoff(), $this->out);

        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $this->assertSame('old module', file_get_contents($this->root . '/modules/Base/Module.php'));
        $this->assertFileExists($this->root . '/modules/Base/Retired.php');
        $this->assertFileDoesNotExist($this->root . '/modules/Base/Added.php', 'what it added is removed');
        $this->assertFileDoesNotExist($this->root . '/finished.txt');

        $install = $this->install('2.0.1');
        $this->assertSame([1, false, false], [$install['attempts'], $install['copied'], $install['booted']]);
        $this->assertStringContainsString('Could not write modules/Locked/New.php', $install['error']);
        $this->assertStringContainsString('The previous release was restored', $install['error']);

        $this->assertSame(99, $this->handoff(), 'not due again until its retry_after');

        chmod($this->root . '/modules/Locked', 0755);
        $this->assertSame(0, $this->handoff([], $this->now + 900), $this->out);
        $this->assertSame(2, $this->install('2.0.1')['attempts']);
        $this->assertSame('new cli', file_get_contents($this->root . '/cli.php'));
    }

    /** A release that does not boot is restored from the shutdown: no schema can have changed. */
    public function testAReleaseThatDoesNotBootIsRestored(): void
    {
        $this->stage('2.0.1', null, [], true, [], "<?php\nthrow new \\Error('this release does not boot');\n");

        $this->handoff();

        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
        $this->assertFileExists($this->root . '/modules/Base/Retired.php');
        $this->assertFileDoesNotExist($this->root . '/modules/Base/Added.php');
        $this->assertSame('<?php // the installed release', file_get_contents($this->root . '/owa.php'));

        $install = $this->install('2.0.1');
        $this->assertStringContainsString('stopped before it booted: Uncaught Error: this release does not boot', $install['error']);
        $this->assertFalse($install['copied']);
    }

    /** A staging changed since Step 1 checked it is deleted, and Step 1 stages it again. */
    public function testAStagingThatChangedIsDeleted(): void
    {
        $this->stage('2.0.1');
        file_put_contents($this->data . '/updates/2.0.1/release/modules/Base/Module.php', 'tampered');

        $this->assertSame(99, $this->handoff());
        $this->assertDirectoryDoesNotExist($this->data . '/updates/2.0.1');
        $this->assertFileDoesNotExist($this->data . '/updates/staged.json');
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
    }

    public function testNothingRunsFromAStagingOthersCanWrite(): void
    {
        $this->stage('2.0.1');
        chmod($this->data . '/updates/2.0.1', 0770);
        $this->assertSame(99, $this->handoff());

        chmod($this->data . '/updates/2.0.1', 0700);
        file_put_contents($this->data . '/updates/2.0.1/release/updater.php', "<?php return ['install' => fn () => 7];");
        $this->assertSame(99, $this->handoff(), 'an updater.php that is not the one its manifest lists');
        $this->assertSame('old cli', file_get_contents($this->root . '/cli.php'));
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

        $this->stage('2.0.1', null, ['preflight' => ['passed' => false]], false);
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.1']));
        $this->assertStringContainsString('not staged and checked', $this->out);

        // A failing install starts over with fresh attempts, whatever its retry_after.
        $this->stage('2.0.1', null, [], false);
        file_put_contents($this->data . '/updates/2.0.1/install.json', json_encode(['state' => 'installing', 'attempts' => 5, 'retry_after' => $this->now + 600000, 'error' => 'before']));
        $this->assertSame(0, $this->handoff(['cmd=self-update-install', 'version=2.0.1']), $this->out);
        $this->assertSame(1, $this->install('2.0.1')['attempts']);

        file_put_contents($this->root . '/.git', 'gitdir: elsewhere');
        $this->assertSame(1, $this->handoff(['cmd=self-update-install', 'version=2.0.1']));
        $this->assertStringContainsString('version control checkout', $this->out);
    }

    // ---------------------------------------------------------------------

    private string $out = '';

    /**
     * The installed tree's updater.php `handoff`, in a process of its own as
     * cli.php runs it: its exit code, 99 for "boot normally".
     */
    private function handoff(array $argv = ['cmd=schedule-run'], ?int $now = null): int
    {
        $script = sprintf('$h = (require %s)["handoff"]; $r = $h(%s, %s); exit($r === null ? 99 : $r);',
            var_export($this->root . '/updater.php', true), var_export($this->root, true), var_export(array_merge(['cli.php'], $argv), true));
        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr'];

        // The clock, for a retry_after in the future: faketime is not to be relied on, so a later
        // claim is made by moving retry_after back instead.
        if ($now !== null) {
            foreach (glob($this->data . '/updates/*/install.json') as $file) {
                $i = json_decode(file_get_contents($file), true);
                $i['retry_after'] = $i['retry_after'] - ($now - $this->now);
                file_put_contents($file, json_encode($i));
            }
        }

        $proc = proc_open(array_merge($cmd, ['-r', $script]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        $this->out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return proc_close($proc);
    }

    /**
     * A staged release, as Step 1 leaves it: release/ with its manifest,
     * stage.json, and staged.json pointing at it (unless $point is false).
     */
    private function stage(string $v, ?array $window = null, array $stage = [], bool $point = true, array $extra = [], ?string $owa = null): void
    {
        $dir = $this->data . "/updates/$v";
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir . '/release', 0700, true);

        $owa ??= "<?php\nnamespace OWA\\Module\\Base\\Classes\\AutoUpdate;\nclass Finish {\n    public static function run(array \$c) {\n"
            . "        (\$c['record'])(['booted' => true]);\n        file_put_contents(\$c['root'] . '/finished.txt', \$c['version']);\n        return 0;\n    }\n}\n";

        $this->tree($dir . '/release', $extra + [
            'cli.php'                 => 'new cli',
            'modules/Base/Module.php' => 'new module',
            'modules/Base/Added.php'  => 'added',
            'same.php'                => 'unchanged',
            'owa.php'                 => $owa,
        ]);
        chmod($dir . '/release/cli.php', 0755);
        copy(dirname(__DIR__) . '/updater.php', $dir . '/release/updater.php');
        file_put_contents($dir . '/release/manifest.json', json_encode(ReleaseManifest::build($dir . '/release')));
        file_put_contents($dir . '/stage.json', json_encode($stage + [
            'version' => $v, 'manifest_checked' => true, 'preflight' => ['passed' => true], 'manual_install' => [],
        ]));

        if ($point) {
            file_put_contents($this->data . '/updates/staged.json', json_encode(['format' => 1, 'version' => $v, 'window' => $window]));
        }
    }

    private function tree(string $dir, array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0755, true);
            file_put_contents($dir . '/' . $path, $content);
        }
    }

    private function install(string $v): array
    {
        return json_decode((string) file_get_contents($this->data . "/updates/$v/install.json"), true);
    }
}
