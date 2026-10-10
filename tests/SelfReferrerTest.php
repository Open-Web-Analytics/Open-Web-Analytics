<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * TrackingEventHelpers::deriveRefererHost() -- a referrer on the site's own
 * domain is no referring site, by the rule a click uses (isSiteHost()).
 *
 * No site id here, so the cookie domain is the default and nothing reads the
 * database; the configured domain is MigrateMoreSourcesTest's.
 */
final class SelfReferrerTest extends TestCase
{
    private function host(?string $referrer, ?string $page = 'https://www.site.example/now'): ?string
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->set('HTTP_REFERER', $referrer);
        $event->set('page_location', $page);

        return TrackingEventHelpers::deriveRefererHost(null, $event);
    }

    public function testAnotherSiteIsTheReferrer(): void
    {
        $this->assertSame('www.google.com', $this->host('https://www.google.com/search?q=x'));
        $this->assertSame('evilsite.example', $this->host('https://evilsite.example/'), 'a suffix is not a subdomain');
    }

    public function testTheSitesOwnPagesAreNoReferrer(): void
    {
        foreach (['https://www.site.example/before', 'https://site.example/', 'https://blog.site.example/post',
                  'https://WWW.Site.Example/caps', 'https://www.site.example./dot'] as $referrer) {
            $this->assertNull($this->host($referrer), $referrer);
        }
    }

    public function testNoReferrerIsNoHost(): void
    {
        $this->assertNull($this->host(null));
        $this->assertNull($this->host(''));
    }

    /** With no page to compare against, the referrer is kept rather than guessed away. */
    public function testWithNoPageTheReferrerIsKept(): void
    {
        $this->assertSame('www.site.example', $this->host('https://www.site.example/before', null));
    }
}
