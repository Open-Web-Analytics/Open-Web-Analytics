<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA_DATA_DIR and OWA_CACHE_DIR in owa-config.php move what OWA writes
 * (Core\Env::dataDir(), Core\Env::cacheDir()): the error log, the file event
 * queue with its archive and dead letters, the collector's fallback queue, the
 * file cache, GeoIP data and downloaded user-agent patterns.
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

    private const SQS = array(
        'OWA_SQS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-data-dir-probe',
        'OWA_SQS_KEY'       => 'AKIAPROBEEXAMPLE',
        'OWA_SQS_SECRET'    => 'probe-secret',
    );

    private string $dir = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the probe boots against the install\'s own config');
        }

        $this->dir = sys_get_temp_dir() . '/owa-data-dir-' . getmypid() . '-' . bin2hex(random_bytes(4));

        foreach (array('logs', 'caches', 'ua-parser') as $sub) {
            mkdir($this->dir . '/' . $sub, 0777, true);
        }

        // A stand-in: only its location is under test.
        file_put_contents($this->dir . '/ua-parser/regexes.php', "<?php return array();\n");
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
        $cmd = array_merge(array(PHP_BINARY, '-d', 'auto_prepend_file=' . self::PREPEND, self::PROBE), $args);
        $env = array_merge(getenv(), array('OWA_PROBE_CONSTANTS' => json_encode($constants)));

        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, dirname(__DIR__), $env);
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
        // No trailing slash: the setting adds one.
        $r    = $this->probe(array('OWA_DATA_DIR' => $this->dir) + self::SQS, 'log');
        $data = $this->dir . '/';

        $this->assertSame($data, $r['data_dir']);
        $this->assertSame('OWA_DATA_DIR', $r['constant']);
        $this->assertSame($data . 'caches/', $r['cache_dir']);
        $this->assertSame($data . 'caches/', $r['file_cache_dir']);
        $this->assertStringStartsWith($data . 'logs/errors_', $r['error_log_file']);
        $this->assertSame($data . 'logs/', $r['async_log_dir']);
        $this->assertSame($data . 'logs/', $r['queue_dir']);
        $this->assertSame($data . 'logs/archive/', $r['archive_dir']);
        $this->assertSame($data . 'logs/dead-letter/', $r['dead_letter']);
        $this->assertSame($data . 'logs/', $r['fallback_dir'], 'the collector\'s fallback queue');
        $this->assertSame($data . 'maxmind/', $r['maxmind_dir']);
        $this->assertSame($data . 'ua-parser/regexes.php', $r['ua_regexes']);

        $this->assertFileExists($r['error_log_file']);
        $this->assertStringContainsString('data dir probe', (string) file_get_contents($r['error_log_file']));
    }

    public function testDefiningItRaisesNoWarning(): void
    {
        $r = $this->probe(array('OWA_DATA_DIR' => $this->dir, 'OWA_CACHE_DIR' => $this->dir . '/caches/'));

        $this->assertSame(array(), $r['warnings']);
    }

    public function testTheCacheDirectoryMovesOnlyTheCache(): void
    {
        $cache = $this->dir . '/elsewhere/';
        mkdir($cache);

        $r = $this->probe(array('OWA_CACHE_DIR' => rtrim($cache, '/')));

        $this->assertSame($cache, $r['cache_dir']);
        $this->assertSame($cache, $r['file_cache_dir']);
        $this->assertSame(\OWA\Core\Env::root() . '/owa-data/', $r['data_dir']);
    }

    public function testWithNothingSetEverythingStaysInOwaData(): void
    {
        $r    = $this->probe(self::SQS);
        $data = \OWA\Core\Env::root() . '/owa-data/';

        $this->assertSame($data, $r['data_dir']);
        $this->assertSame('', $r['constant']);
        $this->assertSame($data . 'caches/', $r['cache_dir']);
        $this->assertSame($data . 'caches/', $r['file_cache_dir']);
        $this->assertStringStartsWith($data . 'logs/errors_', $r['error_log_file']);
        $this->assertSame($data . 'logs/', $r['queue_dir']);
        $this->assertSame($data . 'logs/', $r['fallback_dir']);
        $this->assertSame($data . 'maxmind/', $r['maxmind_dir']);
        $this->assertSame(array(), $r['warnings']);
    }
}
