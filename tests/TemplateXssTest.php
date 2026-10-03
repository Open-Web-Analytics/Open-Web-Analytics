<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Regression guard for stored-XSS in server-rendered admin templates.
 *
 * A security audit found several admin/reporting templates that echoed
 * attacker-controllable, tracker-sourced values (page URLs, page titles,
 * visitor user_name/user_email) RAW into HTML an admin views -- notably into
 * <a href="..."> attributes and an HTML notification email. The tracker's
 * makeUrlCanonical() runs html_entity_decode() on stored URLs and never
 * re-escapes, so a page URL like  https://x/?a=1"><script>...  keeps its raw
 * quote and breaks out of the href attribute when the report renders.
 *
 * The fix routes those sinks through \OWA\Core\Template::out() (htmlentities,
 * ENT_QUOTES). These tests render the ACTUAL template files with a breakout
 * payload and assert the dangerous sequence does not survive into the output.
 * They are DB-free: templates only need their view vars set directly.
 *
 * If any of these templates regresses to a raw `echo`, the payload's `"><`
 * (or `<script`) reappears verbatim and the matching assertion fails.
 */
final class TemplateXssTest extends TestCase
{
    /**
     * A URL payload that survives makeUrlCanonical() (verified: parse_url keeps
     * the raw quote) and breaks out of an href attribute + opens a tag.
     */
    private const URL_PAYLOAD = 'https://owa-xss.example.test/?a=1"><script>alert(1)</script>';

    /** A free-text payload (page title / visitor name). */
    private const TEXT_PAYLOAD = '"><img src=x onerror=alert(1)>';

    /**
     * Assert an escaped render: the raw attribute-breakout sequence `">` and a
     * raw `<script` / `<img` opener must NOT appear; the escaped entity form
     * (&quot; / &lt;) must. This is what \OWA\Core\Template::out() produces.
     */
    private function assertEscaped(string $html, string $context): void
    {
        $this->assertStringNotContainsString(
            '"><script', $html, "$context: raw attribute-breakout + <script survived"
        );
        $this->assertStringNotContainsString(
            '<script>alert(1)', $html, "$context: raw <script> survived"
        );
        $this->assertStringNotContainsString(
            '<img src=x onerror', $html, "$context: raw <img onerror> survived"
        );
        // Positive control: the payload WAS present, just entity-encoded.
        $this->assertMatchesRegularExpression(
            '/&quot;|&lt;|&#0*3[49];/', $html, "$context: expected HTML-entity-escaped output"
        );
    }

    private function renderBaseTemplate(string $file, array $vars): string
    {
        $t = new \OWA\Core\Template('base');
        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }
        $this->assertTrue($t->set_template($file), "could not locate template $file");
        return $t->fetch();
    }

    public function testNewSessionEmailEscapesVisitorFields(): void
    {
        // HTML notification email (announce_visitors + notice_email enabled).
        $html = $this->renderBaseTemplate('new_session_email.php', [
            'site'    => ['domain' => 'owa-e2e.example.test'],
            'session' => [
                'visitor_id' => '1785002347504724034',
                'user_name'  => self::TEXT_PAYLOAD,
                'user_email' => self::TEXT_PAYLOAD,
                'host'       => self::TEXT_PAYLOAD,
                'city'       => 'Portland',
                'country'    => 'US',
                'page_title' => self::TEXT_PAYLOAD,
                'page_url'   => self::URL_PAYLOAD,
            ],
        ]);
        $this->assertEscaped($html, 'new_session_email.php');
    }

    /* visitors-roster's template is gone with the report. */

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
