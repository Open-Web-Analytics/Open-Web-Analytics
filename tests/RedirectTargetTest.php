<?php

use PHPUnit\Framework\TestCase;

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
     * redirectBrowser() stops calling it -- which is the regression that would
     * actually matter. It emits headers, so it cannot be invoked under PHPUnit;
     * assert on the source instead.
     *
     * Single-quoted needle: a double-quoted one containing $url would interpolate
     * to something that is not in the file, and the assertion would pass whatever
     * the source said.
     */
    public function testRedirectBrowserResolvesItsTarget()
    {
        $source = (string) file_get_contents(__DIR__ . '/../Core/Lib.php');

        $this->assertMatchesRegularExpression(
            '/function redirectBrowser\s*\([^)]*\)\s*\{[^}]*resolveRedirectUrl/s',
            $source,
            'redirectBrowser() must resolve its target before emitting the Location header'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/header\s*\(\s*.Location: .\s*\.\s*\$url\s*\)/',
            $source,
            'the raw target must not reach the Location header'
        );
    }
}
