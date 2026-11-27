<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Guard against stored XSS in server-rendered admin templates.
 *
 * Admin and reporting templates render attacker-controllable, tracker-sourced
 * values (page URLs, page titles) into HTML an admin views -- notably into
 * <a href="..."> attributes. An href goes through Sanitize::sanitizeHref(),
 * whose scheme whitelist is tested here. DB-free.
 */
final class TemplateXssTest extends TestCase
{
    /**
     * Unit-level guard for the scheme whitelist itself: only http/https/mailto/
     * ftp/tel (plus relative + scheme-relative) survive; everything else -> '#'.
     */
    public function testSanitizeHrefWhitelistsSchemes(): void
    {
        // Dangerous schemes collapse to '#'.
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('javascript:alert(1)'));
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('data:text/html,<script>alert(1)</script>'));
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('vbscript:msgbox(1)'));
        // Obfuscation must not slip past: entity-encoded and whitespace-split schemes.
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('java&#115;cript:alert(1)'));
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref("java\tscript:alert(1)"));
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref(" javascript:alert(1)"));

        // Legitimate URLs pass through unchanged.
        $this->assertSame('https://example.com/p?a=1', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('https://example.com/p?a=1'));
        $this->assertSame('http://example.com/', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('http://example.com/'));
        $this->assertSame('mailto:a@b.com', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('mailto:a@b.com'));
        $this->assertSame('//cdn.example.com/x.js', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('//cdn.example.com/x.js'));
        $this->assertSame('/relative/path', \OWA\Module\Base\Classes\Sanitize::sanitizeHref('/relative/path'));
        $this->assertSame('#', \OWA\Module\Base\Classes\Sanitize::sanitizeHref(''));
    }

}
