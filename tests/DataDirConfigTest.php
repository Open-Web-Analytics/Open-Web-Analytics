<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA_DATA_DIR in owa-config.php moves what OWA writes (Core\Env::dataDir()):
 * the error log, the file event queue with its archive and dead letters, the
 * collector's fallback queue, GeoIP data and downloaded user-agent patterns.
 *
 * SUBPROCESS, because the constants are read once at boot: each case runs
 * fixtures/data_dir_probe.php with its constants prepended
 * (fixtures/role_constants_prepend.php), as ServerRoleBootTest runs the entry
 * points. The install's own owa-config.php still loads. The SQS constants make
 * the file queue the fallback rather than the intake; no case reaches AWS.
 */
final class DataDirConfigTest extends TestCase
{
    private const PREPEND = __DIR__ . '/fixtures/role_constants_prepend.php';
    private const PROBE   = __DIR__ . '/fixtures/data_dir_probe.php';

    private const SQS = [
        'OWA_SQS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-data-dir-probe',
        'OWA_SQS_KEY'       => 'AKIAPROBEEXAMPLE',
        'OWA_SQS_SECRET'    => 'probe-secret',
    ];

    /**
     * The runner's own config may pin the error log and the file queue (the
     * CI harness does); defined first, these leave both to their defaults.
     */
    private const UNPINNED = [
        'OWA_ERROR_LOG_FILE' => '',
        'OWA_ASYNC_LOG_DIR'  => '',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the probe boots against the install\'s own config');
        }

        $this->dir = sys_get_temp_dir() . '/owa-data-dir-' . getmypid() . '-' . bin2hex(random_bytes(4));

        // logs/ writable by all, as the web server writes it; the rest only
        // by this process, which owns OWA's files here, so the user-agent
        // patterns in conf/ are used (Core\Overrides).
        $old = umask(022);
        mkdir($this->dir . '/conf/ua-parser', 0755, true);
        mkdir($this->dir . '/logs', 0777);
        chmod($this->dir . '/logs', 0777);

        // A stand-in: only its location is under test.
        file_put_contents($this->dir . '/conf/ua-parser/regexes.php', "<?php return array();\n");
        umask($old);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** @return array<string, mixed> */
    private function probe(array $constants, string ...$args): array
    {
        $cmd = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . self::PREPEND, self::PROBE], $args);
        $env = array_merge(getenv(), ['OWA_PROBE_CONSTANTS' => json_encode($constants)]);

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        foreach (explode("\n", $out) as $line) {
            if (strpos($line, 'PROBE ') === 0) {
                return json_decode(substr($line, 6), true);
            }
        }

        $this->fail("the probe printed nothing:\n" . $out . $err);
    }

    public function testTheDataDirectoryMovesEverythingWrittenUnderIt(): void
    {
        $d = $this->probe(self::UNPINNED + self::SQS);

        // No trailing slash: the setting adds one.
        $r       = $this->probe(['OWA_DATA_DIR' => $this->dir] + self::UNPINNED + self::SQS, 'log');
        $data    = $this->dir . '/';
        $owaData = \OWA\Core\Env::root() . '/owa-data/';

        $this->assertSame($data, $r['data_dir']);
        $this->assertSame('OWA_DATA_DIR', $r['constant']);
        $this->assertSame($data . 'maxmind/', $r['maxmind_dir']);
        $this->assertSame($data . 'conf/ua-parser/regexes.php', $r['ua_regexes']);

        foreach (['error_log_file', 'async_log_dir', 'queue_dir', 'archive_dir', 'dead_letter', 'fallback_dir'] as $key) {
            $this->assertStringStartsWith($owaData, (string) $d[$key], "$key defaults to under owa-data/");
            $this->assertSame($data . substr((string) $d[$key], strlen($owaData)), $r[$key], "$key follows the data directory");
        }

        $this->assertSame($data . 'logs/' . \OWA\Core\PrivateFiles::name('errors', '.txt'), $r['error_log_file'],
            'the error log is named with the install\'s hash');

        $this->assertFileExists($r['error_log_file']);
        $this->assertStringContainsString('data dir probe', (string) file_get_contents($r['error_log_file']));
    }

    public function testDefiningItRaisesNoWarning(): void
    {
        $r = $this->probe(['OWA_DATA_DIR' => $this->dir]);

        $this->assertSame([], $r['warnings']);
    }

    public function testWithNothingSetEverythingStaysInOwaData(): void
    {
        $r    = $this->probe(self::SQS);
        $data = \OWA\Core\Env::root() . '/owa-data/';

        $this->assertSame($data, $r['data_dir']);
        $this->assertSame('', $r['constant']);
        // The logs and the file queue sit under owa-data/: logs/ by default,
        // or wherever under it the runner's config pins them.
        foreach (['error_log_file', 'queue_dir', 'fallback_dir'] as $key) {
            $this->assertStringStartsWith($data, (string) $r[$key], $key);
        }
        $this->assertSame($r['queue_dir'], $r['fallback_dir'], 'the fallback queue sits with the file queue');
        $this->assertSame($data . 'maxmind/', $r['maxmind_dir']);
        $this->assertSame([], $r['warnings']);
    }
}
