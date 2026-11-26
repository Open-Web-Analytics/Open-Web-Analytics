<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * log.php answers before it works: the pixel is sent and the
 * response ended before OWA is booted, so a visitor's browser never waits on
 * ingest or the database.
 *
 * PHP-FPM's fastcgi_finish_request() is not what CI runs PHP under, so the
 * script is run with a stand-in for it that records what was loaded when the
 * response was ended.
 */
final class LogEndpointTest extends TestCase
{
    /**
     * Run log.php as a GET under the CLI, with $finisher (fastcgi_finish_request
     * or litespeed_finish_request) defined to record the moment it is called,
     * and the database connect disabled.
     *
     * @return array<string, array{files: list<string>, classes: list<string>, owa: bool}> keyed 'finish' and 'shutdown'
     */
    private function runLogPhp(string $finisher): array
    {
        $dir = sys_get_temp_dir() . '/owa_log_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700, true));

        $record  = $dir . '/record.json';
        $prepend = $dir . '/prepend.php';

        $shim = <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
function owa_log_test_record($at) {
    file_put_contents(getenv('OWA_LOG_TEST_RECORD'), json_encode([$at => [
        'files'   => get_included_files(),
        'classes' => array_values(array_filter(get_declared_classes(),
            static fn ($c) => strncmp($c, 'OWA\\', 4) === 0)),
        'owa'     => class_exists('owa', false),
    ]]) . "\n", FILE_APPEND);
}
register_shutdown_function(static function () { owa_log_test_record('shutdown'); });
PHP;

        file_put_contents($prepend, $shim . "\nfunction $finisher() { owa_log_test_record('finish'); return true; }\n");

        try {
            exec('OWA_LOG_TEST_RECORD=' . escapeshellarg($record) . ' ' . escapeshellarg(PHP_BINARY)
                . ' -d ' . escapeshellarg('auto_prepend_file=' . $prepend)
                . ' -d ' . escapeshellarg('disable_functions=mysqli_real_connect,mysqli_connect')
                . ' ' . escapeshellarg(dirname(__DIR__) . '/log.php') . ' > /dev/null 2>&1');

            $at = [];
            foreach (file_exists($record) ? file($record, FILE_IGNORE_NEW_LINES) : [] as $line) {
                $at += (array) json_decode($line, true);
            }

            return $at;
        } finally {
            @unlink($record);
            @unlink($prepend);
            @rmdir($dir);
        }
    }

    /** PHP-FPM is told the response is complete before owa.php is loaded, and OWA boots after. */
    public function testTheResponseIsEndedBeforeOwaIsBooted(): void
    {
        $owa = realpath(dirname(__DIR__) . '/owa.php');

        foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $finisher) {

            $at = $this->runLogPhp($finisher);

            $this->assertArrayHasKey('finish', $at, "$finisher is not called");
            $this->assertNotContains($owa, $at['finish']['files'], "$finisher is called after owa.php is loaded");
            $this->assertFalse($at['finish']['owa']);
            $this->assertContains($owa, $at['shutdown']['files'], 'OWA is booted once the response has ended');
        }
    }

    /**
     * A POST beacon is answered 204, with nothing to follow, and nothing
     * before the response ends reads a setting. Run under php-cgi, which
     * prints the response's headers.
     */
    public function testAPostIsAnswered204WithNoLocation(): void
    {
        list($headers) = $this->cgi('POST');

        $this->assertMatchesRegularExpression('/^Status: 204\b/mi', $headers);
        $this->assertDoesNotMatchRegularExpression('/^Location:/mi', $headers);
    }

    /**
     * log.php under php-cgi.
     *
     * @return array{0: string, 1: string} the headers, the body
     */
    private function cgi(string $method): array
    {
        $cgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));

        if ($cgi === '') {
            $this->markTestSkipped('php-cgi is not installed');
        }

        $root = dirname(__DIR__);
        $env  = [
            'REQUEST_METHOD'  => $method,
            'SCRIPT_FILENAME' => $root . '/log.php',
            'REDIRECT_STATUS' => '200',
            'CONTENT_LENGTH'  => '0',
            'HTTP_HOST'       => 'collector.example.com',
            'REQUEST_URI'     => '/owa/log.php',
            'PATH'            => (string) getenv('PATH'),
        ];

        $proc = proc_open([$cgi, '-d', 'display_errors=0', '-d', 'error_log=/dev/null',
            '-d', 'disable_functions=mysqli_real_connect,mysqli_connect'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $split = strpos($out, "\r\n\r\n");

        return $split === false ? [$out, ''] : [substr($out, 0, $split), substr($out, $split + 4)];
    }

    /** Nothing ahead of the finish call loads an OWA class, so nothing there reads a setting. */
    public function testNothingBeforeTheFinishReadsASetting(): void
    {
        $at = $this->runLogPhp('fastcgi_finish_request');

        $this->assertSame([], $at['finish']['classes']);
        $this->assertNotSame([], $at['shutdown']['classes'], 'the run reached the boot');
        $this->assertNotContains(realpath(dirname(__DIR__) . '/blank.php'), $at['shutdown']['files']);
    }

    /**
     * A GET is answered with the 43-byte pixel and a Content-Length of its
     * real length: a wrong one is dropped, and the response goes out chunked.
     * Run under php-cgi, which prints the response's headers.
     */
    public function testTheContentLengthIsThePixels(): void
    {
        list($headers, $body) = $this->cgi('GET');

        $this->assertSame(1, preg_match('/^Content-Length: (\d+)\r?$/mi', $headers, $m), $headers);
        $this->assertSame(43, strlen($body));
        $this->assertSame('GIF89a', substr($body, 0, 6));
        $this->assertSame(strlen($body), (int) $m[1]);
    }

    /** The tracking path does not ask whether updates are pending: nothing there acts on the answer. */
    public function testTheTrackingBootDoesNotCheckForUpdates(): void
    {
        $c    = \OWA\Core\CoreAPI::configSingleton();
        $was  = [$c->get('base', 'tracker_build'), $c->get('base', 'tracking_mode')];

        try {
            $c->set('base', 'tracker_build', 'an-older-build');

            $c->set('base', 'tracking_mode', true);
            $tracking = new \OWA\Module\Base\Classes\Service();
            $tracking->_loadModules();
            $this->assertSame([], (array) $tracking->getModulesNeedingUpdates());

            $c->set('base', 'tracking_mode', false);
            $admin = new \OWA\Module\Base\Classes\Service();
            $admin->_loadModules();
            $this->assertContains('base', (array) $admin->getModulesNeedingUpdates());
        } finally {
            $c->set('base', 'tracker_build', $was[0]);
            $c->set('base', 'tracking_mode', $was[1]);
        }
    }
}
