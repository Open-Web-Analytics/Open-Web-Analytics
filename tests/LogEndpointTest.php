<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * log.php answers before it works (PLAN 2.30.7): the pixel is sent and the
 * response ended before OWA is booted, so a visitor's browser never waits on
 * ingest or the database.
 *
 * Read from the source, because the server API that makes it real --
 * PHP-FPM's fastcgi_finish_request() -- is not what CI runs PHP under. On
 * this install, through Apache and Varnish, a beacon's response went from
 * 55-90 ms (all of ingest) to 6-40 ms (blank.php's own time).
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
        $boot   = strpos($src, "require_once(OWA_BASE_DIR.'/owa.php')");

        $this->assertNotFalse($finish, 'PHP-FPM is told the response is complete');
        $this->assertNotFalse($boot);
        $this->assertLessThan($boot, $finish, 'and told before anything is loaded');
        $this->assertStringContainsString('litespeed_finish_request', $src);
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
        $was  = array($c->get('base', 'tracker_version'), $c->get('base', 'tracking_mode'));

        try {
            $c->set('base', 'tracker_version', \OWA\Module\Base\Module::requiredTrackerVersion() - 1);

            $c->set('base', 'tracking_mode', true);
            $tracking = new \OWA\Module\Base\Classes\Service();
            $tracking->_loadModules();
            $this->assertSame([], (array) $tracking->getModulesNeedingUpdates());

            $c->set('base', 'tracking_mode', false);
            $admin = new \OWA\Module\Base\Classes\Service();
            $admin->_loadModules();
            $this->assertContains('base', (array) $admin->getModulesNeedingUpdates());
        } finally {
            $c->set('base', 'tracker_version', $was[0]);
            $c->set('base', 'tracking_mode', $was[1]);
        }
    }
}
