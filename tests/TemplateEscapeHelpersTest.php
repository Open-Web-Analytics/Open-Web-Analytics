<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The template helpers every template escapes with: escape() for HTML (what
 * out() prints) and scriptJson() for a value written into a <script> or an
 * attribute.
 */
final class TemplateEscapeHelpersTest extends TestCase
{
    private const PAYLOAD = '</script><script>alert("x\'s")</script>&';

    public function testEscapeIsWhatOutPrints(): void
    {
        $t = new \OWA\Core\Template('base');

        ob_start();
        $t->out(self::PAYLOAD);
        $printed = ob_get_clean();

        $this->assertSame($printed, \OWA\Core\Template::escape(self::PAYLOAD));
        $this->assertStringNotContainsString('<', $printed);
        $this->assertStringNotContainsString('"', $printed);
        $this->assertStringNotContainsString("'", $printed);
    }

    public function testEscapeKeepsAZeroAndDropsNothing(): void
    {
        $this->assertSame('0', \OWA\Core\Template::escape(0));
        $this->assertSame('', \OWA\Core\Template::escape(null));
        $this->assertSame('a &amp; b', \OWA\Core\Template::escape('a & b'));
    }

    public function testScriptJsonCannotCloseTheScriptOrTheAttribute(): void
    {
        $json = \OWA\Core\Template::scriptJson(array('v' => self::PAYLOAD));

        foreach (array('<', '>', '&', "'", '"x') as $raw) {
            $this->assertStringNotContainsString($raw, substr($json, 6), $raw);
        }

        $this->assertSame(array('v' => self::PAYLOAD), json_decode($json, true), 'the same value, once parsed');
    }

    public function testScriptJsonTakesMoreFlags(): void
    {
        $this->assertSame('{"0":"a"}', \OWA\Core\Template::scriptJson(array('a'), JSON_FORCE_OBJECT));
    }

    public function testATemplateWritesAStoredValueIntoItsScriptSafely(): void
    {
        $t = new \OWA\Core\Template('base');
        $t->set('realtime_property_id', '1');
        $t->set('realtime_timezone', self::PAYLOAD);
        $this->assertTrue($t->set_template('report_realtime.php'));

        $html = $t->fetch();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('\u003C\/script\u003E', $html);
    }
}
