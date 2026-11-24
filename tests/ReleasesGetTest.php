<?php

use OWA\Module\Base\Classes\AutoUpdate\Releases;
use PHPUnit\Framework\TestCase;

/**
 * Releases::get() against a real http:// server on loopback: a body larger
 * than it may be is refused, whether or not its size is declared.
 * ReleaseCheckTest replaces get() with Releases::$fetcher, so its Guzzle call
 * is only reached here.
 */
final class ReleasesGetTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static string $base = '';
    private static string $docroot = '';

    public static function setUpBeforeClass(): void
    {
        self::$docroot = sys_get_temp_dir() . '/owa-releases-get-' . bin2hex(random_bytes(4));
        mkdir(self::$docroot);

        file_put_contents(self::$docroot . '/router.php', <<<'PHP'
<?php
$mb = (int) ($_GET['mb'] ?? 0);
if (isset($_GET['declared'])) {
    header('Content-Length: ' . ($mb << 20));
}
$chunk = str_repeat('x', 1 << 20);
for ($i = 0; $i < $mb; $i++) {
    echo $chunk;
    flush();
}
PHP
        );

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name  = stream_socket_get_name($probe, false);
        fclose($probe);

        self::$base   = 'http://' . $name;
        self::$server = proc_open(
            [PHP_BINARY, '-S', $name, self::$docroot . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes);

        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', (int) substr($name, strrpos($name, ':') + 1), $errno, $errstr, 0.1);

            if ($sock) {
                fclose($sock);
                return;
            }

            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        foreach (glob(self::$docroot . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir(self::$docroot);
    }

    protected function setUp(): void
    {
        if (!is_resource(self::$server) || !proc_get_status(self::$server)['running']) {
            $this->markTestSkipped('could not start a loopback php -S server');
        }

        if (!defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $this->markTestSkipped('no curl extension: nothing caps the size');
        }
    }

    public function testABodyWithinTheLimitIsRead(): void
    {
        $this->assertSame(2 << 20, strlen(Releases::get(self::$base . '/?mb=2', 20)));
    }

    public function testADeclaredBodyOverTheLimitIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/maximum (allowed )?file size/i');
        Releases::get(self::$base . '/?mb=9&declared=1', 20);
    }

    public function testAnUndeclaredBodyIsStoppedAtTheLimit(): void
    {
        if (version_compare(curl_version()['version'], '8.4.0', '<')) {
            $this->markTestSkipped('libcurl before 8.4 only refuses a declared size');
        }

        $this->expectExceptionMessageMatches('/maximum (allowed )?file size/i');
        Releases::get(self::$base . '/?mb=' . ((Releases::MAX_BODY >> 20) + 1), 20);
    }
}
