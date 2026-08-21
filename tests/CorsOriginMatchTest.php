<?php

use PHPUnit\Framework\TestCase;

/**
 * Which Origins the REST API is willing to answer cross-origin.
 *
 * addCorsHeaders() has never emitted a header. It iterated getSitesList(),
 * which returns `SELECT * FROM owa_site` -- an array of *row arrays* -- and
 * compared each row against the Origin string:
 *
 *     foreach ( CoreAPI::getSitesList() as $allowedOrigin ) {
 *         if ( $allowedOrigin !== $HTTP_ORIGIN ) { continue; }
 *
 * An array is never identical to a string, so `continue` always fired, the
 * loop always fell through, and no Access-Control-Allow-Origin was ever sent.
 * Cross-origin REST calls had therefore never worked, which is why playback
 * depended on JSONP -- a transport that exists precisely to evade the
 * same-origin policy CORS is meant to satisfy properly. With this fixed and
 * covered end to end, JSONP has been removed from both the overlays and the
 * server.
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
            'http://www.peteradamsphoto.com',
            $this->match('http://www.peteradamsphoto.com', ['http://www.peteradamsphoto.com'])
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
     * A subdomain is a different origin and is not covered by its parent.
     */
    public function testASubdomainIsNotItsParent(): void
    {
        $this->assertNull($this->match('https://sub.example.com', ['https://example.com']));
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

    /**
     * No sites configured means nothing is allowed.
     */
    public function testNoSitesMatchesNothing(): void
    {
        $this->assertNull($this->match('https://example.com', []));
    }
}
