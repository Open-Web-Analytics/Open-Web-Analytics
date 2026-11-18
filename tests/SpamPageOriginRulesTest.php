<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Spam\Hosts;
use OWA\Module\Base\Classes\Spam\Rules\GhostPageHost;
use OWA\Module\Base\Classes\Spam\Rules\RefererMismatch;
use OWA\Module\Base\Classes\Spam\Rules\NoPageLocation;
use OWA\Module\Base\Classes\Spam\Rules\NoReferer;
use OWA\Module\Base\Classes\Spam\Score;

/**
 * The page_origin rule set: did the beacon come from a page of the site.
 */
final class SpamPageOriginRulesTest extends TestCase
{
    private function event(string $page, string $type = 'page_view'): object
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType($type);

        if ($page !== '') {
            $event->set('page_location', $page);
        }

        return $event;
    }

    /** GHOST_PAGE_HOST against a Profile whose hosts the test names. */
    private function ghost(string $page, string $domain): bool
    {
        $rule = new class($domain) extends GhostPageHost {
            private $domain;
            public function __construct($domain) { $this->domain = $domain; }
            protected function profileHosts($site_id) { return Hosts::profileHosts($this->domain); }
        };

        return $rule->test($this->event($page), []);
    }

    // ---- host comparison --------------------------------------------------

    public function testHostsCompareWithoutCaseWwwOrPort(): void
    {
        $this->assertSame('example.com', Hosts::of('https://WWW.Example.com:8443/a?b=c'));
        $this->assertSame('example.com', Hosts::of('example.com'));
        $this->assertSame('example.com', Hosts::of('www.example.com/path'));
        $this->assertSame('example.com', Hosts::of('http://example.com'));
        $this->assertSame('blog.example.com', Hosts::of('https://blog.example.com/'));
        $this->assertSame('', Hosts::of(''));
        $this->assertSame('', Hosts::of('/relative/only'));
    }

    public function testAProfilesHostIsItsDomain(): void
    {
        $this->assertSame(['example.com'], Hosts::profileHosts('https://www.example.com'));
        $this->assertSame([], Hosts::profileHosts(''), 'no domain, no hosts');
    }

    // ---- GHOST_PAGE_HOST --------------------------------------------------

    public function testAPageOnTheProfilesDomainDoesNotFire(): void
    {
        $this->assertFalse($this->ghost('https://www.example.com/a', 'www.example.com'));
    }

    public function testWwwAndTheBareDomainAreOneHost(): void
    {
        $this->assertFalse($this->ghost('https://example.com/a', 'www.example.com'));
        $this->assertFalse($this->ghost('https://www.example.com/a', 'example.com'));
    }

    public function testAPortIsIgnored(): void
    {
        $this->assertFalse($this->ghost('https://example.com:8080/a', 'example.com'));
    }

    public function testAnotherHostFires(): void
    {
        $this->assertTrue($this->ghost('https://spam.example/landing', 'example.com'));
        $this->assertTrue($this->ghost('https://example.com.evil.example/', 'example.com'), 'the domain inside another host');
    }

    /** A subdomain of the domain is on the site, whichever side carries www. */
    public function testASubdomainOfTheDomainIsOnTheSite(): void
    {
        foreach (['example.com', 'www.example.com', 'https://www.example.com'] as $domain) {
            $this->assertFalse($this->ghost('https://shop.example.com/cart', $domain), "shop. under $domain");
            $this->assertFalse($this->ghost('https://www.example.com/', $domain), "www. under $domain");
            $this->assertFalse($this->ghost('https://a.b.example.com/', $domain), "a deeper subdomain under $domain");
        }
    }

    /** A host that only ends in the same letters is not a subdomain. */
    public function testASuffixThatOnlyLooksLikeASubdomainFires(): void
    {
        $this->assertTrue($this->ghost('https://badexample.com/', 'example.com'));
        $this->assertTrue($this->ghost('https://www.badexample.com/', 'www.example.com'));

        $this->assertFalse(Hosts::isWithin('badexample.com', ['example.com']));
        $this->assertTrue(Hosts::isWithin('shop.example.com', ['example.com']));
        $this->assertTrue(Hosts::isWithin('example.com', ['example.com']));
        $this->assertFalse(Hosts::isWithin('example.com', ['shop.example.com']), 'the parent is not the subdomain');
        $this->assertFalse(Hosts::isWithin('example.com', []));
    }

    public function testAProfileWithNoDomainNeverFires(): void
    {
        $this->assertFalse($this->ghost('https://spam.example/landing', ''));
    }

    public function testNoPageLocationIsNotAGhost(): void
    {
        $this->assertFalse($this->ghost('', 'example.com'));
    }

    /**
     * Against a stored Profile: only its domain and the domain's subdomains are
     * its hosts. Another domain fires, whatever an installation still holds
     * under domain_aliases.
     */
    public function testAStoredProfilesHostsAreItsDomainAndItsSubdomainsOnly(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('reads the Profile\'s domain');
        }

        $site    = 'owa-ghost-host-site';
        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $aliases = \OWA\Core\CoreAPI::getSetting('base', 'domain_aliases');

        $db->query('DELETE FROM owa_site WHERE site_id = ?', [$site]);
        $db->query(sprintf("INSERT INTO owa_site (id, site_id, property_id, name, domain) VALUES (%s, ?, 0, 'Ghost host fixture', 'www.example.com')",
            \OWA\Core\CoreAPI::entityFactory('base.site')->generateId($site)), [$site]);

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'domain_aliases', 'example.org');

            $rule = new GhostPageHost();
            $test = function (string $page) use ($rule, $site): bool {
                $event = $this->event($page);
                $event->setSiteId($site);

                return $rule->test($event, []);
            };

            $this->assertFalse($test('https://example.com/a'));
            $this->assertFalse($test('https://www.example.com/a'));
            $this->assertFalse($test('https://shop.example.com/a'));
            $this->assertTrue($test('https://example.org/a'), 'another domain is not the Profile\'s');
            $this->assertTrue($test('https://blog.example.org/a'));
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'domain_aliases', $aliases);
            $db->query('DELETE FROM owa_site WHERE site_id = ?', [$site]);
        }
    }

    // ---- REFERER_MISMATCH and NO_REFERER ----------------------------------

    public function testTheBeaconsRefererAgainstThePage(): void
    {
        $rule = new RefererMismatch();
        $page = $this->event('https://www.facesofopensource.com/portrait');

        $this->assertFalse($rule->test($page, ['referer' => 'https://www.facesofopensource.com/portrait']));
        $this->assertFalse($rule->test($page, ['referer' => 'https://facesofopensource.com/']), 'www and bare');
        $this->assertFalse($rule->test($page, ['referer' => 'https://www.facesofopensource.com:443/']), 'a port');
        $this->assertTrue($rule->test($page, ['referer' => 'https://m.baidu.com/from=844b/bd_page_type=1']));
        $this->assertFalse($rule->test($page, ['referer' => '']), 'no Referer is NO_REFERER\'s');
        $this->assertFalse($rule->test($this->event(''), ['referer' => 'https://m.baidu.com/']), 'no page is NO_PAGE_LOCATION\'s');
    }

    public function testNoRefererAtAll(): void
    {
        $rule = new NoReferer();

        $this->assertTrue($rule->test($this->event('https://example.com/'), []));
        $this->assertTrue($rule->test($this->event('https://example.com/'), ['referer' => '  ']));
        $this->assertFalse($rule->test($this->event('https://example.com/'), ['referer' => 'https://example.com/']));
    }

    // ---- NO_PAGE_LOCATION -------------------------------------------------

    public function testAnEventSentFromAPageWithoutOne(): void
    {
        $rule = new NoPageLocation();

        foreach (['page_view', 'click', 'scroll', 'purchase'] as $type) {
            $this->assertTrue($rule->test($this->event('', $type), []), $type);
            $this->assertFalse($rule->test($this->event('https://example.com/', $type), []), $type);
        }
    }

    /** A custom event may come from a server, and a materialized one from no beacon at all. */
    public function testAnEventWithNoPageNeverFires(): void
    {
        $rule = new NoPageLocation();

        foreach (['signup_completed', 'session_start', 'first_visit'] as $type) {
            $this->assertFalse($rule->test($this->event('', $type), []), $type);
        }
    }

    // ---- the set as a whole -----------------------------------------------

    public function testTheSetsRulesAndPoints(): void
    {
        $found = [];

        foreach (\OWA\Module\Base\Classes\Spam\RuleSets::SETS['page_origin'] as $class) {
            $rule = new $class();
            $this->assertSame('page_origin', $rule->set());
            $found[$rule->name()] = $rule->points();
        }

        $this->assertSame(['GHOST_PAGE_HOST' => 5.0, 'REFERER_MISMATCH' => 3.0, 'NO_PAGE_LOCATION' => 5.0, 'NO_REFERER' => 1.0], $found);
    }

    private function realBrowser(string $page, string $referer): object
    {
        $event = $this->event($page);

        foreach ([
            'HTTP_USER_AGENT'            => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
            'request_sec_fetch_mode'     => 'no-cors',
            'request_sec_ch_ua'          => '"Not/A)Brand";v="99", "Chromium";v="148"',
            'request_sec_ch_ua_platform' => '"Windows"',
            'request_sec_ch_ua_mobile'   => '?0',
            'request_accept_encoding'    => 'gzip, deflate, br, zstd',
            'request_accept_language'    => 'en-US,en;q=0.9',
            'request_referer'            => $referer,
        ] as $property => $value) {
            $event->set($property, $value);
        }

        return $event;
    }

    public function testARealBrowserBeaconFromTheSiteScoresZero(): void
    {
        $verdict = Score::of($this->realBrowser('https://www.facesofopensource.com/portrait',
            'https://www.facesofopensource.com/portrait'), 'strict');

        $this->assertSame(0.0, $verdict['score'], $verdict['rules']);
    }

    /** Baidu's mobile transcoder: the page is the site's, the Referer is Baidu's. Below the threshold. */
    public function testABaiduTranscodedViewScoresThree(): void
    {
        $verdict = Score::of($this->realBrowser('https://www.facesofopensource.com/portrait',
            'https://m.baidu.com/from=844b/bd_page_type=1/ssid=0/uid=0'), 'strict');

        $this->assertSame('REFERER_MISMATCH=3.0', $verdict['rules']);
        $this->assertSame(3.0, $verdict['score']);
        $this->assertFalse(Score::blocks($verdict['score'], 5.0));
    }
}
