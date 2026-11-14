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

    /** Every caller in the code means the root path, and the default is it. */
    public function testTheDefaultPathIsTheRoot(): void
    {
        $param = (new ReflectionMethod(CoreAPI::class, 'createCookie'))->getParameters()[3];

        $this->assertSame('path', $param->getName());
        $this->assertSame('/', $param->getDefaultValue());
    }
}
