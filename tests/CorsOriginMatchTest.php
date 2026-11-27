<?php

use PHPUnit\Framework\TestCase;

/**
 * Which Origins the REST API is willing to answer cross-origin: those the
 * overlays open pages on (ProfileDomains::originOf()), a live web Profile's
 * domain or a subdomain of it.
 *
 * Matching is on HOST, not on the stored string. Real installs store
 * `http://demo.openwebanalytics.com` while serving over https, so a browser
 * sends `Origin: https://demo.openwebanalytics.com` and an exact-string match
 * would leave CORS broken on exactly the installs that need it. Hosts are
 * compared case-insensitively and in full -- never as a prefix or substring,
 * which is how `evil-example.com` gets to impersonate `example.com`.
 *
 * The matcher is pure so these cases need no database and no request.
 */
final class CorsOriginMatchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** Site rows as getSitesList() returns them. */
    private function sites(array $domains): array
    {
        return array_map(static fn($d) => ['domain' => $d], $domains);
    }

    private function match(string $origin, array $domains)
    {
        return \OWA\Core\View\RestApi::matchAllowedOrigin($origin, $this->sites($domains));
    }

    /**
     * The defect, in the shape both production installs have: the site is
     * stored with an http:// scheme and served over https.
     */
    public function testASiteStoredAsHttpMatchesAnHttpsOrigin(): void
    {
        $this->assertSame(
            'https://demo.openwebanalytics.com',
            $this->match('https://demo.openwebanalytics.com', ['http://demo.openwebanalytics.com'])
        );
    }

    /**
     * The Origin is echoed back exactly as sent, never the stored form.
     */
    public function testTheOriginIsEchoedBackVerbatim(): void
    {
        $this->assertSame(
            'http://www.alice.example',
            $this->match('http://www.alice.example', ['http://www.alice.example'])
        );
    }

    /**
     * Hosts match in full. A site named as a suffix of the Origin's host must
     * not match -- this is the classic CORS allowlist bypass.
     */
    public function testASuffixHostDoesNotMatch(): void
    {
        $this->assertNull($this->match('https://evil-example.com', ['https://example.com']));
        $this->assertNull($this->match('https://example.com.evil.net', ['https://example.com']));
        $this->assertNull($this->match('https://notexample.com', ['https://example.com']));
    }

    /**
     * A subdomain of a Profile's domain is answered: the heatmap and the
     * player open pages there, and their fetch came back refused.
     */
    public function testASubdomainOfTheDomainIsAllowed(): void
    {
        $this->assertSame('https://shop.example.com', $this->match('https://shop.example.com', ['https://example.com']));
        $this->assertSame('https://a.b.example.com', $this->match('https://a.b.example.com', ['example.com']));
        $this->assertSame('https://shop.example.com', $this->match('https://shop.example.com', ['www.example.com']));
        $this->assertSame('https://www.sub.example.com', $this->match('https://www.sub.example.com', ['example.com']));
    }

    /** A parent of the Profile's domain is not on it. */
    public function testTheParentOfTheDomainIsNotAllowed(): void
    {
        $this->assertNull($this->match('https://example.com', ['shop.example.com']));
        $this->assertNull($this->match('https://other.example.com', ['shop.example.com']));
    }

    /**
     * `www.` and the bare domain are one site, whichever the Profile names:
     * a Profile stored as `potusphotobook.com` serving from
     * `www.potusphotobook.com` refused its own heatmap's clicks.
     */
    public function testWwwAndTheBareDomainAreOneSite(): void
    {
        $this->assertSame('https://www.example.com', $this->match('https://www.example.com', ['example.com']));
        $this->assertSame('https://example.com', $this->match('https://example.com', ['http://www.example.com']));
        $this->assertSame('https://WWW.Example.com', $this->match('https://WWW.Example.com', ['example.com']));
    }

    /** `www.` on a different domain, or before a single label, is refused. */
    public function testWwwOnAnotherDomainIsRefused(): void
    {
        $this->assertNull($this->match('https://www.evil-example.com', ['example.com']));
        $this->assertNull($this->match('https://wwwexample.com', ['example.com']));
        $this->assertNull($this->match('https://com', ['www.com']));
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        $this->assertSame(
            'https://WWW.Example.COM',
            $this->match('https://WWW.Example.COM', ['http://www.example.com'])
        );
    }

    /**
     * Sites whose domain carries no host at all -- `owa-test-site` exists on a
     * real install -- must never match anything, least of all an empty Origin.
     */
    public function testASiteWithNoHostMatchesNothing(): void
    {
        $this->assertNull($this->match('https://example.com', ['owa-test-site']));
        $this->assertNull($this->match('', ['owa-test-site']));
        $this->assertNull($this->match('https://example.com', ['']));
    }

    /**
     * A garbage or non-origin value is refused rather than coerced.
     */
    public function testMalformedOriginsAreRefused(): void
    {
        foreach (['null', 'not a url', 'javascript:alert(1)', '//example.com', 'https://'] as $origin) {
            $this->assertNull($this->match($origin, ['https://example.com']), "matched: $origin");
        }
    }

    /**
     * With several sites configured, the right one matches.
     */
    public function testTheCorrectSiteMatchesAmongSeveral(): void
    {
        $domains = ['http://a.example.com', 'https://b.example.com', 'owa-test-site'];

        $this->assertSame('https://b.example.com', $this->match('https://b.example.com', $domains));
        $this->assertSame('https://a.example.com', $this->match('https://a.example.com', $domains));
        $this->assertNull($this->match('https://c.example.com', $domains));
    }

    /** The port is not part of the rule, as it is not for the overlay launcher. */
    public function testThePortIsNotPartOfTheRule(): void
    {
        $this->assertSame('http://localhost:8080', $this->match('http://localhost:8080', ['localhost:8080']));
        $this->assertSame('http://localhost:9000', $this->match('http://localhost:9000', ['localhost:8080']));
        $this->assertSame('http://localhost', $this->match('http://localhost', ['localhost:8080']));
        $this->assertSame('https://example.com:8443', $this->match('https://example.com:8443', ['https://example.com']));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function launcherCases(): array
    {
        return [
            'the domain'              => ['https://example.com', ['example.com']],
            'www and the bare domain' => ['https://www.example.com', ['example.com']],
            'a subdomain'             => ['https://shop.example.com', ['http://www.example.com']],
            'a deeper subdomain'      => ['http://a.b.example.com', ['example.com']],
            'another port'            => ['https://example.com:8443', ['example.com']],
            'a suffix'                => ['https://evil-example.com', ['example.com']],
            'the domain as a label'   => ['https://example.com.evil.net', ['example.com']],
            'the parent'              => ['https://example.com', ['shop.example.com']],
            'www before one label'    => ['https://com', ['www.com']],
            'ftp'                     => ['ftp://example.com', ['example.com']],
            'no host'                 => ['https://', ['example.com']],
            'a hostless Profile'      => ['https://example.com', ['owa-test-site']],
        ];
    }

    /**
     * One rule: an Origin is answered exactly when the overlay launcher would
     * open a page of that origin (ProfileDomains::originOf()).
     *
     * @dataProvider launcherCases
     */
    public function testTheRuleIsTheOverlayLaunchers(string $origin, array $domains): void
    {
        $sites    = $this->sites($domains);
        $launches = \OWA\Module\Base\Classes\ProfileDomains::originOf(
            $origin . '/page', \OWA\Module\Base\Classes\ProfileDomains::ofSites($sites)) !== '';

        $this->assertSame($launches, \OWA\Core\View\RestApi::matchAllowedOrigin($origin, $sites) !== null);
    }

    /**
     * No sites configured means nothing is allowed.
     */
    public function testNoSitesMatchesNothing(): void
    {
        $this->assertNull($this->match('https://example.com', []));
    }

    /** Only live web Profiles grant an origin -- a bundle id is not a host. */
    public function testOnlyWebProfilesContributeCorsOrigins(): void
    {
        $origin = 'https://com.example.app';
        $match  = static fn(array $site) => \OWA\Core\View\RestApi::matchAllowedOrigin($origin, [$site + ['domain' => 'com.example.app']]);

        $this->assertSame($origin, $match(['stream_type' => 'web']));
        $this->assertSame($origin, $match([]), 'a Profile that predates stream_type is a website');
        $this->assertSame($origin, $match(['stream_type' => null]), 'a 1.x row\'s NULL is a website');

        $this->assertNull($match(['stream_type' => 'app']),
            'An app Profile\'s identifier is parsed as a host for CORS matching.');

        $this->assertNull($match(['stream_type' => 'web', 'archived_date' => '2026-01-01 00:00:00']),
            'An archived Profile still grants its origin, though it has stopped observing.');
    }
}
