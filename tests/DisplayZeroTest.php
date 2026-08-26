<?php

use PHPUnit\Framework\TestCase;

/**
 * A zero is a value, and templates must print it.
 *
 * escapeForDisplay() guarded on truthiness -- `if ( $string )` -- so 0, '0' and
 * 0.0 all fell through it and returned null. out() echoes whatever it is given,
 * so every zero rendered through a template simply vanished: a funnel step
 * nobody reached printed "visitors" with no number in front of it, and any
 * count, total or metric that happened to be zero printed as blank rather than
 * as none.
 *
 * It is the same mistake as the entity write layer discarding a falsy value and
 * the query builder dropping an empty constraint: a falsy value tested for
 * presence. Blank reads as "no data", which is a different claim from "none",
 * and the reader cannot tell which they are looking at.
 */
final class DisplayZeroTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function escape($value)
    {
        return \OWA\Module\Base\Classes\Sanitize::escapeForDisplay($value);
    }

    /** @dataProvider zeroProvider */
    public function testAZeroIsRendered($value, string $expected): void
    {
        $this->assertSame($expected, $this->escape($value));
    }

    public static function zeroProvider(): array
    {
        return array(
            'integer zero' => array(0, '0'),
            'string zero'  => array('0', '0'),
            'float zero'   => array(0.0, '0'),
            'false'        => array(false, ''),
        );
    }

    /** Genuinely absent values still render as nothing. */
    public function testAnAbsentValueRendersAsNothing(): void
    {
        $this->assertSame('', $this->escape(null));
        $this->assertSame('', $this->escape(''));
    }

    /** The escaping this function exists for is unchanged. */
    public function testEscapingStillHappens(): void
    {
        $this->assertSame('&lt;b&gt;', $this->escape('<b>'));
        $this->assertStringNotContainsString('<script', $this->escape('<script>alert(1)</script>'));
    }

    /** Ordinary values are untouched. */
    public function testOrdinaryValuesSurvive(): void
    {
        $this->assertSame('5', $this->escape(5));
        $this->assertSame('hello', $this->escape('hello'));
    }

    /**
     * End to end through the helper a template actually calls, because that is
     * where the zero was being lost.
     */
    public function testOutPrintsAZero(): void
    {
        $template = new \OWA\Core\Template;

        ob_start();
        $template->out(0);
        $printed = ob_get_clean();

        $this->assertSame('0', $printed,
            'a template printing a zero must print the zero, not nothing');
    }

    public function testOutPrintsAZeroWithoutSanitizing(): void
    {
        $template = new \OWA\Core\Template;

        ob_start();
        $template->out(0, false);
        $printed = ob_get_clean();

        $this->assertSame('0', $printed);
    }
}
