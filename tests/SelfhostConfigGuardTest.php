<?php

use PHPUnit\Framework\TestCase;

/**
 * The self-hosted e2e runner acts on owa-config.php only when it is the regular
 * file selfhost_harness.php wrote: its php -S router runs nothing of OWA before
 * that, and `selfhost_harness.php owned`, which the teardown asks before it
 * unseeds, answers no. The Playwright config starts php -S through the router
 * with opcache and the realpath cache off.
 *
 * The router and harness are copied into a scratch tree whose index.php only
 * says it ran, so the answers depend on nothing but the config file each case
 * puts there.
 */
final class SelfhostConfigGuardTest extends TestCase
{
    private static string $root = '';
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/owa-selfhost-router-' . bin2hex(random_bytes(4));
        mkdir(self::$root . '/tests/e2e', 0700, true);

        foreach (['selfhost_router.php', 'selfhost_marker.php', 'selfhost_harness.php'] as $file) {
            copy(__DIR__ . '/e2e/' . $file, self::$root . '/tests/e2e/' . $file);
        }

        file_put_contents(self::$root . '/index.php', "<?php echo 'OWA RAN';");

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name  = stream_socket_get_name($probe, false);
        fclose($probe);

        self::$base   = 'http://' . $name;
        self::$server = proc_open(
            [PHP_BINARY, '-S', $name, '-t', self::$root, self::$root . '/tests/e2e/selfhost_router.php'],
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

        foreach (['owa-config.php', 'other-config.php', 'index.php', 'tests/e2e/selfhost_router.php', 'tests/e2e/selfhost_marker.php', 'tests/e2e/selfhost_harness.php'] as $file) {
            @unlink(self::$root . '/' . $file);
        }

        @rmdir(self::$root . '/tests/e2e');
        @rmdir(self::$root . '/tests');
        @rmdir(self::$root);
    }

    protected function setUp(): void
    {
        if (!is_resource(self::$server) || !proc_get_status(self::$server)['running']) {
            $this->markTestSkipped('could not start a loopback php -S server');
        }

        @unlink(self::$root . '/owa-config.php');
        @unlink(self::$root . '/other-config.php');
    }

    /** @return array{0:int,1:string} status, body */
    private function get(string $path): array
    {
        $body = (string) @file_get_contents(self::$base . $path, false,
            stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));

        preg_match('#^HTTP/\S+ (\d{3})#', (string) ($http_response_header[0] ?? ''), $m);

        return [(int) ($m[1] ?? 0), $body];
    }

    private function marker(): string
    {
        require_once __DIR__ . '/e2e/selfhost_marker.php';

        return SELFHOST_CONFIG_MARKER;
    }

    public function testTheReadinessUrlAnswersWithNoConfig(): void
    {
        $this->assertSame(200, $this->get('/__owa_e2e_selfhost')[0]);
    }

    public function testNothingRunsWithoutAConfig(): void
    {
        [$status, $body] = $this->get('/index.php');

        $this->assertSame(503, $status);
        $this->assertStringNotContainsString('OWA RAN', $body);
    }

    public function testAConfigTheHarnessDidNotWriteIsNotRun(): void
    {
        file_put_contents(self::$root . '/owa-config.php', "<?php\ndefine('OWA_DB_NAME', 'another_install');\n");

        [$status, $body] = $this->get('/index.php');

        $this->assertSame(503, $status);
        $this->assertStringNotContainsString('OWA RAN', $body);
    }

    /** A worktree's owa-config.php is a symlink to another install's: refused, marker or not. */
    public function testASymlinkedConfigIsNotRun(): void
    {
        file_put_contents(self::$root . '/other-config.php', "<?php\n" . $this->marker() . "\n");
        symlink(self::$root . '/other-config.php', self::$root . '/owa-config.php');

        [$status, $body] = $this->get('/index.php');

        $this->assertSame(503, $status);
        $this->assertStringNotContainsString('OWA RAN', $body);
    }

    public function testTheHarnessConfigIsServedAndARestoredOneIsNotAgain(): void
    {
        file_put_contents(self::$root . '/owa-config.php', "<?php\n" . $this->marker() . "\n");

        $this->assertSame([200, 'OWA RAN'], $this->get('/index.php'));

        // Teardown puts the stashed symlink back while php -S is still up.
        unlink(self::$root . '/owa-config.php');
        file_put_contents(self::$root . '/other-config.php', "<?php\n");
        symlink(self::$root . '/other-config.php', self::$root . '/owa-config.php');

        $this->assertSame(503, $this->get('/index.php')[0]);
    }

    /** Exit code of `selfhost_harness.php owned` in the scratch tree. */
    private function owned(): int
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$root . '/tests/e2e/selfhost_harness.php') . ' owned 2>&1', $out, $code);

        return $code;
    }

    public function testOwnedAnswersYesOnlyForTheHarnessConfig(): void
    {
        $this->assertSame(1, $this->owned(), 'no config');

        file_put_contents(self::$root . '/other-config.php', "<?php\n" . $this->marker() . "\n");
        symlink(self::$root . '/other-config.php', self::$root . '/owa-config.php');
        $this->assertSame(1, $this->owned(), 'a symlink');

        unlink(self::$root . '/owa-config.php');
        file_put_contents(self::$root . '/owa-config.php', "<?php\ndefine('OWA_DB_NAME', 'another_install');\n");
        $this->assertSame(1, $this->owned(), 'no marker');

        file_put_contents(self::$root . '/owa-config.php', "<?php\n" . $this->marker() . "\n");
        $this->assertSame(0, $this->owned(), 'the harness config');
    }

    /** The harness writes the marker into the config it generates. */
    public function testTheHarnessWritesTheMarker(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/e2e/selfhost_harness.php');

        $this->assertStringContainsString("require __DIR__ . '/selfhost_marker.php';", $src);
        $this->assertMatchesRegularExpression('/\$override = "\\\\n"\s*\.\s*SELFHOST_CONFIG_MARKER/', $src);
    }

    /** php -S is started through the router, with opcache and the realpath cache off, and probed at the router's URL. */
    public function testThePlaywrightConfigStartsTheServerThroughTheRouter(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/playwright.selfhost.config.js');

        $this->assertStringContainsString('-d opcache.enable=0', $src);
        $this->assertStringContainsString('-d realpath_cache_size=0', $src);
        $this->assertStringContainsString("path.join(__dirname, 'tests', 'e2e', 'selfhost_router.php')", $src);
        $this->assertStringContainsString("url: SERVER_URL + '__owa_e2e_selfhost'", $src);
    }
}
