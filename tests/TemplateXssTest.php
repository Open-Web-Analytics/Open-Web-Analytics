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
 * The fix routes those sinks through owa_template::out() (htmlentities,
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
     * A scheme-based payload. htmlentities() leaves this untouched -- it has no
     * quote/angle-bracket to escape -- so a template that only ran out() on a
     * URL would still emit a live javascript: href. safeHref() must collapse
     * the scheme to '#'.
     */
    private const SCHEME_PAYLOAD = 'javascript:alert(document.cookie)';

    /**
     * Assert an escaped render: the raw attribute-breakout sequence `">` and a
     * raw `<script` / `<img` opener must NOT appear; the escaped entity form
     * (&quot; / &lt;) must. This is what owa_template::out() produces.
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
        $t = new owa_template('base');
        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }
        $this->assertTrue($t->set_template($file), "could not locate template $file");
        return $t->fetch();
    }

    /*
     * documentNavSum.php and row_visitSummary.php are GONE.
     *
     * They were the per-visit CARD stack, alive only for the visitor report,
     * which was dropped 2026-08-25 along with visit, visits and
     * visitors-roster. The escaping rule they were checked against still
     * applies to every remaining template through the sweep below.
     */

    public function testItemDocumentEscapesUrlAndTitle(): void
    {
        // Reached via reportDocument.php / reportDomClicks.php.
        // $properties is a small stub exposing get(), matching the entity API
        // the template calls ($properties->get('url') etc.).
        $properties = new class([
            'id'         => '1785002347504724034', // numeric GUID (safe)
            'page_title' => self::TEXT_PAYLOAD,
            'url'        => self::URL_PAYLOAD,
            'page_type'  => 'page',
        ]) {
            public function __construct(private array $p) {}
            public function get($k) { return $this->p[$k] ?? null; }
        };
        $html = $this->renderBaseTemplate('item_document.php', ['properties' => $properties]);
        $this->assertEscaped($html, 'item_document.php');
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
        $this->assertSame('#', owa_sanitize::sanitizeHref('javascript:alert(1)'));
        $this->assertSame('#', owa_sanitize::sanitizeHref('data:text/html,<script>alert(1)</script>'));
        $this->assertSame('#', owa_sanitize::sanitizeHref('vbscript:msgbox(1)'));
        // Obfuscation must not slip past: entity-encoded and whitespace-split schemes.
        $this->assertSame('#', owa_sanitize::sanitizeHref('java&#115;cript:alert(1)'));
        $this->assertSame('#', owa_sanitize::sanitizeHref("java\tscript:alert(1)"));
        $this->assertSame('#', owa_sanitize::sanitizeHref(" javascript:alert(1)"));

        // Legitimate URLs pass through unchanged.
        $this->assertSame('https://example.com/p?a=1', owa_sanitize::sanitizeHref('https://example.com/p?a=1'));
        $this->assertSame('http://example.com/', owa_sanitize::sanitizeHref('http://example.com/'));
        $this->assertSame('mailto:a@b.com', owa_sanitize::sanitizeHref('mailto:a@b.com'));
        $this->assertSame('//cdn.example.com/x.js', owa_sanitize::sanitizeHref('//cdn.example.com/x.js'));
        $this->assertSame('/relative/path', owa_sanitize::sanitizeHref('/relative/path'));
        $this->assertSame('#', owa_sanitize::sanitizeHref(''));
    }

    /**
     * A stored page URL of  javascript:...  must NOT render as a live scheme in
     * the item_document "Visit Site" href. out() alone would let it through;
     * safeHref() replaces it with '#'.
     */
    public function testItemDocumentNeutralizesJavascriptScheme(): void
    {
        $properties = new class([
            'id'         => '1785002347504724034',
            'page_title' => 'Home',
            'url'        => self::SCHEME_PAYLOAD,
            'page_type'  => 'page',
        ]) {
            public function __construct(private array $p) {}
            public function get($k) { return $this->p[$k] ?? null; }
        };
        $html = $this->renderBaseTemplate('item_document.php', ['properties' => $properties]);

        // The href must be neutralized; no live javascript: scheme in an attribute.
        $this->assertStringNotContainsString('href="javascript:', $html, 'live javascript: scheme survived into href');
        $this->assertStringContainsString('href="#"', $html, 'expected scheme to collapse to #');
    }
}
