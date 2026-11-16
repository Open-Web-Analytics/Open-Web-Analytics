<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A beacon naming a site id this installation does not have is dropped. The id
 * is whatever an anonymous request sent, so it is not written to the log at
 * notice level, and an id no site could have is not looked up.
 */
final class UnknownSiteIdLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    public function testASiteIdIsPrintableAndFitsItsColumn(): void
    {
        $this->assertTrue(\OWA\Core\CoreAPI::isSiteIdShaped(md5('example.com')));
        $this->assertTrue(\OWA\Core\CoreAPI::isSiteIdShaped('OWA-7322fd48966b35c5'));
        $this->assertTrue(\OWA\Core\CoreAPI::isSiteIdShaped(str_repeat('a', 255)));

        $this->assertFalse(\OWA\Core\CoreAPI::isSiteIdShaped(''));
        $this->assertFalse(\OWA\Core\CoreAPI::isSiteIdShaped(str_repeat('a', 256)));
        $this->assertFalse(\OWA\Core\CoreAPI::isSiteIdShaped("abc\nNOTICE forged line"));
        $this->assertFalse(\OWA\Core\CoreAPI::isSiteIdShaped("abc def"));
        $this->assertFalse(\OWA\Core\CoreAPI::isSiteIdShaped("abc\x00"));
    }

    public function testAnIdNoSiteCouldHaveIsNotRegisteredOrRemembered(): void
    {
        $id = str_repeat('x', 5000);

        $this->assertFalse(\OWA\Core\CoreAPI::siteRegistration($id));

        $memo = new ReflectionProperty(\OWA\Core\CoreAPI::class, 'registered_sites');

        $this->assertArrayNotHasKey($id, (array) $memo->getValue());
    }

    public function testALoggedSiteIdIsOneShortLine(): void
    {
        $logged = \OWA\Core\CoreAPI::siteIdForLog("abc\r\n[NOTICE] forged" . str_repeat('y', 500));

        $this->assertStringNotContainsString("\n", $logged);
        $this->assertStringNotContainsString("\r", $logged);
        $this->assertLessThanOrEqual(67, strlen($logged));
        $this->assertStringStartsWith('abc??[NOTICE]?forged', $logged);
    }

    /** @group needs-db */
    public function testTheDrainDoesNotLogAnUnknownSiteAtNotice(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }

        $log = (string) \OWA\Core\CoreAPI::getSetting('base', 'error_log_file');
        $id  = 'unknown-' . bin2hex(random_bytes(6));

        $before = is_file($log) ? filesize($log) : 0;

        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType('page_view');
        $event->set('site_id', $id);

        $this->assertTrue(\OWA\Module\Base\Classes\TrackerIngest::ingest($event));

        clearstatcache();
        $written = is_file($log) ? (string) file_get_contents($log, false, null, $before) : '';

        foreach (explode("\n", $written) as $line) {
            if (strpos($line, $id) !== false) {
                $this->assertStringNotContainsString('[NOTICE]', $line);
            }
        }
    }
}
