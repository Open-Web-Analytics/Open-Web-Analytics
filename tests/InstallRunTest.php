<?php

use PHPUnit\Framework\TestCase;

/**
 * Install::run(), the copy at the end of a core-update or core-install
 * process, which exits: run in a child (tests/fixtures/install_run_child.php)
 * with updater.php's closures stood in for, so each way it can end is reached
 * and what it called, printed and left behind is checked.
 */
final class InstallRunTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/owa-install-run-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array{exit: int, calls: list<array>, out: string, err: string} */
    private function install(array $plan = []): array
    {
        $p = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/install_run_child.php', $this->root, json_encode((object) $plan)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $stdout = (string) stream_get_contents($pipes[1]);
        $err    = (string) stream_get_contents($pipes[2]);
        $exit   = proc_close($p);

        $calls = [];
        $out   = '';

        foreach (explode("\n", $stdout) as $line) {
            $call = json_decode($line, true);

            if (is_array($call)) {
                $calls[] = $call;
            } elseif ($line !== '') {
                $out .= $line . "\n";
            }
        }

        $this->assertNotContains(['returned'], $calls, 'run() returned instead of exiting');

        return ['exit' => $exit, 'calls' => $calls, 'out' => $out, 'err' => $err];
    }

    /** @return list<string> the calls by name, a marker write with its phase and a copy with its tarball */
    private static function steps(array $r): array
    {
        return array_map(fn ($c) => in_array($c[0], ['writeMarker', 'apply'], true) ? "$c[0] $c[1]" : $c[0], $r['calls']);
    }

    private function marker(): ?array
    {
        $f = $this->root . '/owa-update.php';

        return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }

    private static function failure(array $r): string
    {
        foreach ($r['calls'] as $c) {
            if ($c[0] === 'recordFailure') {
                return $c[3];
            }
        }

        return '';
    }

    public function testTheCopy(): void
    {
        $r = $this->install();

        $this->assertSame(0, $r['exit'], $r['err']);
        $this->assertSame(['writeMarker waiting', 'alone', 'backup', 'writeMarker copying', 'apply to', 'writeMarker copied', 'unlock'], self::steps($r));
        $this->assertSame([300], array_slice($r['calls'][1], 1), 'it waits Install::WAIT seconds for other runs');
        $this->assertSame([$this->root . '/owa-data/updates-test', ['Core/Env.php', 'Core/Added.php']], array_slice($r['calls'][2], 1), 'what the plan writes is backed up, in the updates directory');
        $this->assertSame('copied', $this->marker()['phase']);
        $this->assertSame([$this->root . '/owa-data/updates-test/backup-test', ['Core/Env.php' => ['sha256' => str_repeat('c', 64), 'mode' => 0644]], ['Core/Added.php']],
            [$this->marker()['backup'], $this->marker()['saved'], $this->marker()['created']], 'the marker carries what the put-back checks');
        $this->assertSame(['Release check (by hand), OWA 2.0.1 installed.', 'Waiting up to 300 seconds for other runs of cli.php to end.',
            'Backed up the 1 files the copy overwrites or deletes in ' . $this->root . '/owa-data/updates-test/backup-test.',
            'Copying OWA 2.0.2\'s files over 2.0.1\'s.', 'Copied. The next run of cli.php starts it.'],
            array_map(fn ($l) => preg_replace('/^\S+ \S+ UTC /', '', $l), $this->marker()['log']), 'the marker carries the install\'s log');
        $this->assertStringContainsString("OWA 2.0.2's files are in place.", $r['out']);
        $this->assertSame('', $r['err']);
    }

    public function testAQuietCopySaysNothing(): void
    {
        $r = $this->install(['quiet' => true]);

        $this->assertSame(0, $r['exit']);
        $this->assertSame('', $r['out'] . $r['err']);
    }

    /** Whatever it would load would be the new release's. */
    public function testNothingIsLoadedOnceTheCopyBegins(): void
    {
        $r = $this->install(['load' => true]);

        $refused = array_values(array_filter($r['calls'], fn ($c) => $c[0] === 'refused'));
        $this->assertCount(1, $refused, 'a class was loaded during the copy');
        $this->assertStringContainsString('Vcs was to be loaded after the copy began', $refused[0][1]);
    }

    public function testAMarkerThatCannotBeWrittenInstallsNothing(): void
    {
        foreach (['waiting' => ['writeMarker waiting'], 'copying' => ['writeMarker waiting', 'alone', 'backup', 'writeMarker copying']] as $phase => $before) {
            $r = $this->install(['marker_fails' => [$phase]]);

            $this->assertSame(1, $r['exit'], $phase);
            $this->assertSame(array_merge($before, ['removeBackups', 'recordFailure', 'unlock']), self::steps($r), $phase);
            $this->assertSame("The marker cannot be written ($phase). Nothing was installed.", self::failure($r));
            $this->assertNull($this->marker(), "$phase: the marker stayed, which would stop every run");
            $this->assertSame("Installing OWA 2.0.2 failed: The marker cannot be written ($phase). Nothing was installed.\n", $r['err']);
        }
    }

    /** Nothing is recorded: the next release check tries again. */
    public function testWhenOtherRunsGoOnNothingIsInstalled(): void
    {
        $r = $this->install(['alone' => false]);

        $why = 'Other runs of cli.php were still going after 300 seconds, so OWA 2.0.2 was not installed; the next release check tries again.';
        $this->assertSame(75, $r['exit']);
        $this->assertSame(['writeMarker waiting', 'alone', 'unlock', 'notice'], self::steps($r));
        $this->assertSame($why, $r['calls'][3][1]);
        $this->assertNull($this->marker());
        $this->assertSame("$why\n", $r['err']);

        $r = $this->install(['alone' => false, 'quiet' => true]);
        $this->assertSame(0, $r['exit'], 'the scheduler\'s run does not fail for it');
        $this->assertSame('', $r['err']);
    }

    /** No room for the backup: nothing is copied, and nothing is left. */
    public function testABackupThatFailsInstallsNothing(): void
    {
        $r = $this->install(['backup_fails' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertSame(['writeMarker waiting', 'alone', 'backup', 'removeBackups', 'recordFailure', 'unlock'], self::steps($r));
        $this->assertStringStartsWith('Backing up the files the copy replaces needs about 40 MB free', self::failure($r));
        $this->assertStringEndsWith('Nothing was installed.', self::failure($r));
        $this->assertNull($this->marker());
    }

    public function testAFailedCopyIsPutBack(): void
    {
        $r = $this->install(['apply_fails' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertSame(['writeMarker waiting', 'alone', 'backup', 'writeMarker copying', 'apply to', 'restore', 'recordFailure', 'unlock'], self::steps($r));
        $this->assertSame($this->root . '/owa-data/updates-test/backup-test', $r['calls'][5][1], 'from the backup the marker names');
        $this->assertSame('Could not write Core/Env.php. OWA 2.0.1 was put back.', self::failure($r));
        $this->assertNull($this->marker());
        $this->assertSame("Installing OWA 2.0.2 failed: Could not write Core/Env.php. OWA 2.0.1 was put back.\n", $r['err']);
    }

    /** The marker stays, saying copying: the next run of cli.php puts the installed release back. */
    public function testWhenPuttingBackFailsTooTheMarkerStays(): void
    {
        $r = $this->install(['apply_fails' => true, 'restore_fails' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertSame(['writeMarker waiting', 'alone', 'backup', 'writeMarker copying', 'apply to', 'restore', 'recordFailure', 'unlock'], self::steps($r));
        $this->assertSame('Could not write Core/Env.php. Putting OWA 2.0.1 back failed too: 1 of the 1 files backed up before the copy are missing or changed.', self::failure($r));
        $this->assertSame('copying', $this->marker()['phase']);
    }

    /** Still saying copying with the lock let go, which the next run puts back: a failure, not "the next run finishes it". */
    public function testAMarkerThatCannotSayCopiedIsAFailure(): void
    {
        $r = $this->install(['marker_fails' => ['copied'], 'quiet' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertSame(['writeMarker waiting', 'alone', 'backup', 'writeMarker copying', 'apply to', 'writeMarker copied', 'unlock'], self::steps($r));
        $this->assertSame('copying', $this->marker()['phase']);
        $this->assertSame('', $r['out']);
        $this->assertSame("OWA 2.0.2's files are in place, but the install marker could not say so: The marker cannot be written (copied). The next run of cli.php puts OWA 2.0.1 back.\n", $r['err']);
    }
}
