<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A setting changed and not explicitly saved must still be written, and must not
 * take the process down on its way out.
 *
 * WHY IT MATTERS
 * owa_settings tracks unsaved changes with is_dirty and used to flush them from
 * __destruct(). PHP destroys objects in no guaranteed order during shutdown, so
 * the database handle was frequently destroyed first and the flush then threw
 * "mysqli object is already closed" from inside a destructor. Two consequences,
 * both silent:
 *
 *   1. The setting was LOST. The write never reached the database and nothing
 *      reported that, because there is no caller left to return a failure to.
 *   2. The uncaught Error set the process exit status to 255. A CLI command that
 *      had done its work correctly reported failure to whatever ran it -- for a
 *      scheduled job, that is cron.
 *
 * The fix registers the save as a shutdown FUNCTION, which PHP runs before it
 * destroys objects, so the database is still reachable. This test is the reason
 * to keep it that way: it was found because a green PHPUnit run still exited
 * 255, which only the per-file isolation sweep surfaced.
 */
final class SettingsShutdownSaveTest extends TestCase
{
    private const PROBE = __DIR__ . '/fixtures/settings_shutdown_probe.php';
    private const KEY   = 'owa_settings_shutdown_probe';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; the probe cannot persist a setting.');
        }
    }

    protected function tearDown(): void
    {
        // Remove the probe's setting and persist that removal explicitly.
        owa_coreAPI::persistSetting('base', self::KEY, '');
        owa_coreAPI::configSingleton()->save();
    }

    /** @return array{status:int, stdout:string, stderr:string} */
    private function probe(string $mode, ?string $value = null): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $proc = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROBE)
            . ' ' . $mode . ' base ' . escapeshellarg(self::KEY)
            . ($value === null ? '' : ' ' . escapeshellarg($value)),
            $descriptors,
            $pipes,
            dirname(__DIR__)
        );

        $this->assertIsResource($proc, 'could not spawn the settings probe');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($proc),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    public function testAnUnsavedSettingSurvivesShutdown(): void
    {
        $value = 'probe-' . bin2hex(random_bytes(4));

        $result = $this->probe('write', $value);

        $this->assertStringContainsString('"dirtied":true', $result['stdout'],
            'the probe did not get as far as changing a setting');

        $this->assertStringContainsString('"dirty_at_shutdown":false', $result['stdout'],
            'The settings were still unsaved when shutdown began. The write must be '
            . 'registered as a shutdown function, which PHP runs while the database '
            . 'is still reachable, not left to a destructor that races it.');

        // Read it back from a THIRD process, which never saw the writer's memory.
        $read    = $this->probe('read');
        $decoded = json_decode(trim($read['stdout']), true);

        $this->assertIsArray($decoded, 'read probe did not emit JSON: ' . $read['stdout']);
        $this->assertSame($value, $decoded['value'] ?? null,
            'A setting changed without an explicit save() must still be written at '
            . 'shutdown. Losing it is silent: there is no caller left to report to.');
    }

    /**
     * ...and when the save genuinely cannot succeed, that is still not allowed
     * to change the exit status.
     *
     * This is not hypothetical. Registering the save as a shutdown function made
     * it run RELIABLY, which turned an intermittent failure on installations
     * with no config file into a consistent one: no config means no
     * OWA_AUTH_KEY, the cache read inside save() raises "Undefined constant",
     * and a green test suite exited 255 on PHP 8.2. A save that cannot happen
     * must be reported, not fatal.
     *
     * Driven directly rather than through a subprocess, because the failure has
     * to be guaranteed. Closing the database does not do it: the driver simply
     * reconnects on the next query.
     */
    public function testAFailingShutdownSaveIsLoggedRatherThanFatal(): void
    {
        $settings = (new ReflectionClass(OwaThrowingSettings::class))->newInstanceWithoutConstructor();

        $dirty = new ReflectionProperty($settings, 'is_dirty');
        $dirty->setAccessible(true);
        $dirty->setValue($settings, true);

        $log = tempnam(sys_get_temp_dir(), 'owa_settings_log_');
        $was = ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $settings->saveIfDirty();   // must return, not throw
        } finally {
            ini_set('error_log', $was === false ? '' : $was);
        }

        $this->assertSame(1, $settings->save_attempts, 'the save should have been attempted');

        $this->assertStringContainsString('could not save settings during shutdown',
            (string) file_get_contents($log),
            'The failure must leave a trace: a lost setting is worth a line in the log.');

        @unlink($log);
    }

    public function testShutdownDoesNotChangeTheExitStatus(): void
    {
        $result = $this->probe('write', 'probe-' . bin2hex(random_bytes(4)));

        $this->assertStringNotContainsString('already closed', $result['stdout'] . $result['stderr'],
            'the shutdown save ran after the database handle was destroyed');

        $this->assertSame(0, $result['status'],
            'A process that did its work must exit 0. An uncaught Error in shutdown '
            . 'makes it 255, which reports failure to cron for a command that succeeded.');
    }
}

/**
 * A settings object whose save() always fails, standing in for an installation
 * that cannot complete one during shutdown.
 */
final class OwaThrowingSettings extends \OWA\Module\Base\Classes\Settings
{
    public $save_attempts = 0;

    function save()
    {
        $this->save_attempts++;

        throw new \Error('Undefined constant "OWA\\Module\\Base\\Classes\\OWA_AUTH_KEY"');
    }
}
