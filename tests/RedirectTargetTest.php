<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RunsUnderPhpCgi.php';

/**
 * Redirect targets resolve within the installation.
 *
 * Some callers pass a destination that arrived on the request rather than one
 * the server constructed, so resolveRedirectUrl() decides where the browser
 * actually ends up. Anything that would leave the installation is replaced with
 * its base URL.
 */
final class RedirectTargetTest extends TestCase
{
    use RunsUnderPhpCgi;

    private const BASE = 'https://owa.example.test/owa/';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'public_url', self::BASE);
    }

    /** Destinations inside the installation are left alone. */
    public static function internalTargets(): array
    {
        return [
            'absolute, same origin' => ['https://owa.example.test/owa/index.php?owa_do=base.sites'],
            'relative'              => ['index.php?owa_do=base.sites'],
            'root relative'         => ['/owa/index.php'],
            'query only'            => ['?owa_do=base.reportDashboard'],
            // Login decodes its target once more, so a space can arrive.
            'a decoded space'       => ['index.php?owa_do=base.report&owa_constraints=pagePath==/a b'],
        ];
    }

    /** @dataProvider internalTargets */
    public function testInternalTargetsAreKept($url)
    {
        $this->assertSame($url, \OWA\Core\Lib::resolveRedirectUrl($url));
    }

    /**
     * Each of these resolves to a different host in a browser. The
     * protocol-relative and backslash forms carry no scheme of their own, and a
     * suffix lookalike shares a prefix with the real host but is not it.
     */
    public static function externalTargets(): array
    {
        return [
            'absolute offsite'   => ['https://example.com/'],
            'with query'         => ['https://example.com/?x=1'],
            'protocol relative'  => ['//example.com/'],
            'backslash'          => ['\\\\/example.com/'],
            'suffix lookalike'   => ['https://owa.example.test.evil.com/'],
            'prefix lookalike'   => ['https://evil-owa.example.test/'],
            'port mismatch'      => ['https://owa.example.test:8443/owa/'],
            'userinfo confusion' => ['https://owa.example.test@example.com/'],
            'empty'              => [''],
            // A browser drops a tab or newline inside a URL: these are //example.com.
            'tab between slashes'     => ["/\t/example.com/"],
            'newline between slashes' => ["/\n/example.com/"],
            'encoded tab, decoded'    => [urldecode('/%09/example.com/')],
            // Another scheme than the page's makes what follows the host.
            'scheme without slashes'  => ['http:example.com'],
            'https without slashes'   => ['https:example.com/x'],
        ];
    }

    /** @dataProvider externalTargets */
    public function testExternalTargetsFallBackToTheInstallation($url)
    {
        $this->assertSame(
            self::BASE,
            \OWA\Core\Lib::resolveRedirectUrl($url),
            sprintf('%s should not be used as a redirect target', $url ?: '(empty)')
        );
    }

    /**
     * A scheme like javascript: parses with no host at all, so it must be judged
     * on its scheme rather than being mistaken for a relative path.
     */
    public static function nonPageSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data'       => ['data:text/html,<script>1</script>'],
            'file'       => ['file:///etc/passwd'],
        ];
    }

    /** @dataProvider nonPageSchemes */
    public function testNonPageSchemesAreRefused($url)
    {
        $this->assertSame(self::BASE, \OWA\Core\Lib::resolveRedirectUrl($url));
    }

    /**
     * The cases above exercise the resolver directly, so they stay green even if
     * redirectBrowser() stops calling it -- which is the failure that would
     * actually matter. It emits headers, so it is run under php-cgi.
     */
    public function testRedirectBrowserResolvesItsTarget()
    {
        $this->assertMatchesRegularExpression('#^Location: https://owa\.example\.test/owa/\r?$#m',
            $this->cgiHeaders('tests/fixtures/redirect_browser_probe.php', ['PROBE_URL' => 'https://elsewhere.example.com/phish']),
            'redirectBrowser() must resolve its target before emitting the Location header');

        $this->assertMatchesRegularExpression('#^Location: https://owa\.example\.test/owa/index\.php\?owa_do=base\.reportDashboard\r?$#m',
            $this->cgiHeaders('tests/fixtures/redirect_browser_probe.php',
                ['PROBE_URL' => 'https://owa.example.test/owa/index.php?owa_do=base.reportDashboard']),
            'a target within the installation is kept');
    }
}
