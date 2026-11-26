<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\CoreAPI;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RunsUnderPhpCgi.php';

/**
 * The options createCookie() sets a cookie with (CoreAPI::cookieOptions()).
 *
 * setcookie() writes no header under the CLI, so the options are asserted
 * where they are made, and createCookie() itself is run under php-cgi.
 */
final class CreateCookieOptionsTest extends TestCase
{
    use RunsUnderPhpCgi;

    public function testThePathIsTheOneGiven(): void
    {
        $this->assertSame('/reports', CoreAPI::cookieOptions(0, '/reports', '', false, false)['path']);
        $this->assertSame('/', CoreAPI::cookieOptions(0, '/', '', false, false)['path']);
    }

    /** No path, or one written the 1.x way with SameSite on the end, reads as given up to the ;. */
    public function testAnEmptyOrSuffixedPathReadsAsThePathAlone(): void
    {
        $this->assertSame('/', CoreAPI::cookieOptions(0, '', '', false, false)['path']);
        $this->assertSame('/', CoreAPI::cookieOptions(0, '/; samesite=Lax', '', false, false)['path']);
        $this->assertSame('/owa', CoreAPI::cookieOptions(0, '/owa; samesite=Lax', '', false, false)['path']);
    }

    public function testTheOtherOptions(): void
    {
        $this->assertSame(
            ['expires' => 1800, 'path' => '/', 'samesite' => 'Lax', 'domain' => 'example.com', 'secure' => true, 'httponly' => true],
            CoreAPI::cookieOptions('1800', '/', 'example.com', true, true)
        );
    }

    /** HTTPS signals Lib::isHttps() reads, cleared for a test and put back after it. */
    private const HTTPS_KEYS = ['HTTPS', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PORT', 'SERVER_PORT'];

    private function overServer(array $server, callable $fn)
    {
        $was = [];

        foreach (self::HTTPS_KEYS as $k) {
            $was[$k] = $_SERVER[$k] ?? null;
            unset($_SERVER[$k]);
        }

        $_SERVER = $server + $_SERVER;

        try {
            return $fn();
        } finally {
            foreach ($was as $k => $v) {
                unset($_SERVER[$k]);
                if ($v !== null) {
                    $_SERVER[$k] = $v;
                }
            }
        }
    }

    /** $secure true marks the cookie Secure on a plain HTTP request. */
    public function testSecureWhenAskedOverHttp(): void
    {
        $this->overServer([], function () {
            $this->assertTrue(CoreAPI::cookieIsSecure(true));
            $this->assertFalse(CoreAPI::cookieIsSecure(false));
        });
    }

    /** On HTTPS every cookie is Secure, whatever $secure says. */
    public function testSecureOverHttpsWhateverAsked(): void
    {
        $this->overServer(['HTTPS' => 'on'], function () {
            $this->assertTrue(CoreAPI::cookieIsSecure(false));
            $this->assertTrue(CoreAPI::cookieIsSecure(true));
        });
    }

    /**
     * createCookie() sets the cookie with that answer, not with Lib::isHttps()
     * alone. Run under php-cgi, which prints the response's headers.
     */
    public function testCreateCookieAsksIt(): void
    {
        $this->assertMatchesRegularExpression('/; secure\b/i', $this->setCookieHeader(['HTTPS' => 'on'], false),
            'on HTTPS the cookie is Secure though $secure is false');
        $this->assertMatchesRegularExpression('/; secure\b/i', $this->setCookieHeader([], true),
            '$secure true marks it Secure over HTTP');
        $this->assertDoesNotMatchRegularExpression('/; secure\b/i', $this->setCookieHeader([], false));
    }

    /** The Set-Cookie header tests/fixtures/create_cookie_probe.php's request answers with. */
    private function setCookieHeader(array $server, bool $secure): string
    {
        $headers = $this->cgiHeaders('tests/fixtures/create_cookie_probe.php', $server + ['PROBE_SECURE' => $secure ? '1' : '0']);

        $this->assertSame(1, preg_match('/^Set-Cookie: [^\r\n]*probe=v[^\r\n]*/mi', $headers, $m), 'no cookie was set: ' . $headers);

        return $m[0];
    }

    /**
     * A sign-in cookie is host-only, on the install's path, and HttpOnly; and
     * the same name at cookie_domain and / is expired before it, as a browser
     * holding one there would send it first.
     */
    public function testASignInCookieIsSentOnlyToThisInstall(): void
    {
        $headers = $this->cgiHeaders('tests/fixtures/sign_in_cookie_probe.php', [
            'PROBE_PUBLIC_URL' => 'http://owa.example.com/owa/', 'PROBE_COOKIE_DOMAIN' => '.example.com']);

        $this->assertSame(2, preg_match_all('/^Set-Cookie: [^\r\n]*probe=[^\r\n]*/mi', $headers, $m), $headers);
        // The old scope's expiry first: after, a browser would take it for the new cookie and remove it.
        [$expired, $set] = $m[0];

        $this->assertMatchesRegularExpression('/probe=v;/', $set);
        $this->assertMatchesRegularExpression('/; path=\/owa\/(;|$)/i', $set);
        $this->assertDoesNotMatchRegularExpression('/; domain=/i', $set, 'host-only');
        $this->assertMatchesRegularExpression('/; httponly/i', $set);

        $this->assertMatchesRegularExpression('/probe=deleted;/', $expired);
        $this->assertMatchesRegularExpression('/; path=\/(;|$)/i', $expired);
        $this->assertMatchesRegularExpression('/; domain=\.?example\.com/i', $expired);
    }

    /** At the root of its host with no cookie_domain, the one scope is the old one too: nothing else is expired. */
    public function testAtTheRootWithNoCookieDomainOnlyTheCookieIsSet(): void
    {
        $headers = $this->cgiHeaders('tests/fixtures/sign_in_cookie_probe.php', [
            'PROBE_PUBLIC_URL' => 'http://owa.example.com/', 'PROBE_COOKIE_DOMAIN' => '']);

        $this->assertSame(1, preg_match_all('/^Set-Cookie: [^\r\n]*probe=v; [^\r\n]*path=\/(;|\r|$)/mi', $headers), $headers);
        $this->assertStringNotContainsString('probe=deleted', $headers);
    }

    public function testTheSignInPathIsThePublicUrlsPath(): void
    {
        $before = CoreAPI::getSetting('base', 'public_url');

        try {
            foreach (['https://a.example/owa/' => '/owa/', 'https://a.example/owa' => '/owa/',
                      'https://a.example/' => '/', 'https://a.example' => '/', '' => '/',
                      'https://a.example/x/y/' => '/x/y/'] as $url => $path) {
                CoreAPI::setSetting('base', 'public_url', $url);
                $this->assertSame($path, CoreAPI::signInCookiePath(), $url);
            }
        } finally {
            CoreAPI::setSetting('base', 'public_url', $before);
        }
    }

    /** Every caller in the code means the root path, and the default is it. */
    public function testTheDefaultPathIsTheRoot(): void
    {
        $param = (new ReflectionMethod(CoreAPI::class, 'createCookie'))->getParameters()[3];

        $this->assertSame('path', $param->getName());
        $this->assertSame('/', $param->getDefaultValue());
    }
}
