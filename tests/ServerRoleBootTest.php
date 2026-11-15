<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The real entry points under a server role (Core\ServerRole): a request type
 * the role does not serve is refused with a short answer and one line in the
 * error log, and so is a configuration the role cannot run on.
 *
 * SUBPROCESS, because the role is a config-file constant read once at boot:
 * each case runs the entry point with its constants prepended
 * (fixtures/role_constants_prepend.php). The install's own owa-config.php
 * still loads, so the database constants are always there; what a role is
 * missing of them is ServerRoleTest's.
 *
 * No case reaches AWS: the collector's queue URL is only read, never sent to.
 */
final class ServerRoleBootTest extends TestCase
{
    private const PREPEND = __DIR__ . '/fixtures/role_constants_prepend.php';
    private const WEB     = __DIR__ . '/fixtures/web_entry_probe.php';

    private const COLLECTOR = array(
        'OWA_SERVER_ROLE'   => 'collector',
        'OWA_SQS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-role-probe',
        'OWA_SQS_KEY'       => 'AKIAPROBEEXAMPLE',
        'OWA_SQS_SECRET'    => 'probe-secret',
    );

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the entry points boot against the install\'s own config');
        }
    }

    /** @return array{code:int, stdout:string, stderr:string} */
    private function exec(array $constants, array $args): array
    {
        $cmd = array_merge(array(PHP_BINARY, '-d', 'auto_prepend_file=' . self::PREPEND), $args);
        $env = array_merge(getenv(), array('OWA_PROBE_CONSTANTS' => json_encode($constants)));

        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, dirname(__DIR__), $env);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return array('code' => proc_close($proc), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr);
    }

    /** @return array{status:int|false, body:string} */
    private function web(array $constants, string $entry): array
    {
        $out = $this->exec($constants, array(self::WEB, $entry))['stdout'];

        foreach (explode("\n", $out) as $line) {
            if (strpos($line, 'PROBE ') === 0) {
                return json_decode(substr($line, 6), true);
            }
        }

        $this->fail("the probe printed nothing:\n" . $out);
    }

    private function cli(array $constants, string ...$args): array
    {
        return $this->exec($constants, array_merge(array(dirname(__DIR__) . '/cli.php'), $args));
    }

    /** Lines in the error log that refuse a request of this type. */
    private function refusalsLogged(string $type): int
    {
        $file = (string) \OWA\Core\CoreAPI::getSetting('base', 'error_log_file');

        return is_file($file) ? substr_count((string) file_get_contents($file), "Refused a request of type $type: ") : 0;
    }

    /** @return iterable<string, array{string}> */
    public static function collectorRefuses(): iterable
    {
        yield 'index.php'     => array('index.php');
        yield 'api/index.php' => array('api/index.php');
        yield 'install.php'   => array('install.php');
    }

    /** @dataProvider collectorRefuses */
    public function testACollectorAnswersEveryOtherEntryPointWith404(string $entry): void
    {
        $r = $this->web(self::COLLECTOR, $entry);

        $this->assertSame(404, $r['status']);
        $this->assertSame('This server does not serve this page.', $r['body']);
    }

    public function testARefusalWritesOneLogLine(): void
    {
        $before = $this->refusalsLogged('ui');

        $this->web(self::COLLECTOR, 'index.php');

        $this->assertSame($before + 1, $this->refusalsLogged('ui'));
    }

    public function testAConsoleDoesNotServeTheApi(): void
    {
        $this->assertSame(404, $this->web(array('OWA_SERVER_ROLE' => 'console'), 'api/index.php')['status']);
    }

    public function testAnApiServerDoesNotServeTheScreens(): void
    {
        $this->assertSame(404, $this->web(array('OWA_SERVER_ROLE' => 'api'), 'index.php')['status']);
    }

    public function testACommandNotMarkedForTheRoleIsRefused(): void
    {
        $r = $this->cli(array('OWA_SERVER_ROLE' => 'console'), 'cmd=partition-status');

        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('cmd=partition-status does not run on this server: its role is console',
            $r['stderr']);
    }

    public function testACollectorRefusesEveryCommandNotMarkedForIt(): void
    {
        $r = $this->cli(self::COLLECTOR, 'cmd=schedule-run');

        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('its role is collector', $r['stderr']);
    }

    public function testACommandMarkedForTheRoleRuns(): void
    {
        $r = $this->cli(array('OWA_SERVER_ROLE' => 'console'), 'cmd=server-check');

        $this->assertSame(0, $r['code'], $r['stderr'] . $r['stdout']);
        $this->assertStringContainsString('Server role:      console', $r['stdout']);
        $this->assertStringContainsString('Serves:           ui, cli', $r['stdout']);
        $this->assertStringContainsString('Missing:          nothing', $r['stdout']);
    }

    public function testAnUnknownRoleIsRefusedEverywhere(): void
    {
        $web = $this->web(array('OWA_SERVER_ROLE' => 'colector'), 'index.php');

        $this->assertSame(503, $web['status']);
        $this->assertStringContainsString('not configured correctly', $web['body']);

        $cli = $this->cli(array('OWA_SERVER_ROLE' => 'colector'), 'cmd=server-check');

        $this->assertSame(1, $cli['code']);
        $this->assertStringContainsString('OWA_SERVER_ROLE names colector, which is not a role. The roles are full, collector, worker, console, api.',
            $cli['stderr']);
    }

    public function testACollectorWithoutAQueueIsRefused(): void
    {
        $r = $this->cli(array('OWA_SERVER_ROLE' => 'collector'), 'cmd=server-check');

        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('Role collector needs a queue backend to send beacons to: set OWA_SQS_QUEUE_URL.',
            $r['stderr']);
    }

    public function testACollectorRunsTheForwardWithoutTheUpdateGate(): void
    {
        $r = $this->cli(self::COLLECTOR, 'cmd=drain-tracker-ingest', 'forward_only=1', 'seconds=1');

        $this->assertStringNotContainsString('Updates required', $r['stdout'] . $r['stderr']);
        $this->assertSame(0, $r['code'], $r['stderr'] . $r['stdout']);
    }
}
