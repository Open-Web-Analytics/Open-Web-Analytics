<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The admin screens' type scale: five sizes declared once, on :root in
 * owa.report.css, and referred to by role.
 */
final class AdminTypeScaleTest extends TestCase
{
    private function css(string $file): string
    {
        return (string) file_get_contents(OWA_DIR . 'modules/Base/css/' . $file);
    }

    /** @return array<string,int> */
    private function scale(): array
    {
        preg_match('/:root \{([^}]*)\}/', $this->css('owa.report.css'), $root);
        preg_match_all('/--owa-text-([a-z]+):\s*(\d+)px/', $root[1] ?? '', $m, PREG_SET_ORDER);

        $out = array();
        foreach ($m as $token) {
            $out[$token[1]] = (int) $token[2];
        }

        return $out;
    }

    private function roleOf(string $css, string $selector): ?string
    {
        $at = strpos($css, $selector);
        $this->assertNotFalse($at, "$selector is not in the stylesheet");

        $block = substr($css, $at, strpos($css, '}', $at) - $at);

        return preg_match('/font-size:\s*var\(--owa-text-([a-z]+)/', $block, $m) ? $m[1] : null;
    }

    public function testTheScaleHasFiveSizesLargestFirst(): void
    {
        $scale = $this->scale();

        $this->assertSame(array('xl', 'lg', 'md', 'sm', 'xs'), array_keys($scale));

        $sizes = array_values($scale);
        $sorted = $sizes;
        rsort($sorted);
        $this->assertSame($sorted, $sizes);
        $this->assertCount(5, array_unique($sizes));
    }

    /** The note beneath a field is quieter than the field's description. */
    public function testTheNoteIsSmallerThanTheDescription(): void
    {
        $scale = $this->scale();

        $note = $this->roleOf($this->css('owa.css'), '.setting .owa-inherit-note {');
        $desc = $this->roleOf($this->css('owa.report.css'), '.owa_hierarchyContent #panel .setting .description {');

        $this->assertSame('xs', $note);
        $this->assertSame('sm', $desc);
        $this->assertLessThan($scale[$desc], $scale[$note]);
    }

    /** A field's title outranks its description, and the page headline outranks both. */
    public function testThePaneReadsHeadlineTitleDescription(): void
    {
        $css   = $this->css('owa.report.css');
        $scale = $this->scale();

        $headline = $scale[$this->roleOf($css, '.owa_hierarchyContent .panel_headline {')];
        $title    = $scale[$this->roleOf($css, '.owa_hierarchyContent #panel .setting .title {')];
        $desc     = $scale[$this->roleOf($css, '.owa_hierarchyContent #panel .setting .description {')];

        $this->assertGreaterThan($title, $headline);
        $this->assertGreaterThan($desc, $title);
    }
}
