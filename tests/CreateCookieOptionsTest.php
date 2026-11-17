<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\CoreAPI;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The options createCookie() sets a cookie with (CoreAPI::cookieOptions()).
 *
 * setcookie() writes no header under the CLI, so the options are asserted
 * where they are made.
 */
final class CreateCookieOptionsTest extends TestCase
{
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

    /** createCookie() sets the cookie with that answer, not with Lib::isHttps() alone. */
    public function testCreateCookieAsksIt(): void
    {
        $m   = new ReflectionMethod(CoreAPI::class, 'createCookie');
        $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        $this->assertStringContainsString('self::cookieIsSecure($secure)', $src);
        $this->assertStringNotContainsString('Lib::isHttps()', $src);
    }

    /** Every caller in the code means the root path, and the default is it. */
    public function testTheDefaultPathIsTheRoot(): void
    {
        $param = (new ReflectionMethod(CoreAPI::class, 'createCookie'))->getParameters()[3];

        $this->assertSame('path', $param->getName());
        $this->assertSame('/', $param->getDefaultValue());
    }
}
