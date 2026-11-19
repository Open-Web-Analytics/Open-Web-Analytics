<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * log.php answers before it works (PLAN 2.30.7): the pixel is sent and the
 * response ended before OWA is booted, so a visitor's browser never waits on
 * ingest or the database.
 *
 * Read from the source, because the server API that makes it real --
 * PHP-FPM's fastcgi_finish_request() -- is not what CI runs PHP under.
 */
final class LogEndpointTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/log.php');
    }

    public function testTheResponseIsEndedBeforeOwaIsBooted(): void
    {
        $src    = $this->source();
        $finish = strpos($src, 'fastcgi_finish_request();');
        $boot   = strpos($src, "require_once(__DIR__ . '/owa.php')");

        $this->assertNotFalse($finish, 'PHP-FPM is told the response is complete');
        $this->assertNotFalse($boot);
        $this->assertLessThan($boot, $finish, 'and told before anything is loaded');
        $this->assertStringContainsString('litespeed_finish_request', $src);
    }

    /**
     * A POST beacon is answered 204, with nothing to follow, and nothing
     * before the response ends reads a setting. Run under php-cgi, which
     * prints the response's headers.
     */
    public function testAPostIsAnswered204WithNoLocation(): void
    {
        $cgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));

        if ($cgi === '') {
            $this->markTestSkipped('php-cgi is not installed');
        }

        $root = dirname(__DIR__);
        $env  = [
            'REQUEST_METHOD'  => 'POST',
            'SCRIPT_FILENAME' => $root . '/log.php',
            'REDIRECT_STATUS' => '200',
            'CONTENT_LENGTH'  => '0',
            'HTTP_HOST'       => 'collector.example.com',
            'REQUEST_URI'     => '/owa/log.php',
            'PATH'            => (string) getenv('PATH'),
        ];

        $proc = proc_open([$cgi, '-d', 'display_errors=0', '-d', 'error_log=/dev/null'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $headers = strstr($out, "\r\n\r\n", true) ?: $out;

        $this->assertMatchesRegularExpression('/^Status: 204\b/mi', $headers);
        $this->assertDoesNotMatchRegularExpression('/^Location:/mi', $headers);
    }

    /** Nothing ahead of the finish call boots OWA or reads a setting. */
    public function testNothingBeforeTheFinishReadsASetting(): void
    {
        $src    = $this->source();
        $before = substr($src, 0, (int) strpos($src, 'fastcgi_finish_request();'));

        $this->assertStringNotContainsString('\\OWA\\', $before);
        $this->assertStringNotContainsString('blank.php', $src);
    }

    /** The declared length is the image's: a wrong one is dropped, and the response goes out chunked. */
    public function testTheContentLengthIsThePixels(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('header("Content-Length: " . strlen( $pixel ));', $src);
        $this->assertDoesNotMatchRegularExpression('/Content-Length: \d+/', $src);

        preg_match("/sprintf\\(\\s*'((?:%c)+)',\\s*([\\d,\\s]+)\\)/", $src, $m);
        $this->assertSame(43, substr_count($m[1], '%c'));
        $this->assertCount(43, array_filter(array_map('trim', explode(',', $m[2])), 'strlen'), 'one byte per %c');
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
