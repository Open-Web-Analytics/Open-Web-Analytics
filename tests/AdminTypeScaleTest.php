<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The type scale: one base, the root's font size, a percentage so the
 * browser's text size setting multiplies it, raised on a phone. Every text
 * size in OWA's stylesheets is in rem; the admin screens' five are declared
 * once, on :root in owa.report.css, and referred to by role.
 */
final class AdminTypeScaleTest extends TestCase
{
    private function css(string $file): string
    {
        return (string) file_get_contents(\OWA\Core\Env::root() . '/modules/Base/css/' . $file);
    }

    private const STYLESHEETS = array('owa.css', 'owa.admin.css', 'owa.report.css', 'owa.grid.css', 'owa.shell.css', 'owa.widgets.css');

    /** @return array<string,float> each size in px at a 16px root */
    private function scale(): array
    {
        preg_match('/:root \{([^}]*)\}/', $this->css('owa.report.css'), $root);
        preg_match_all('/--owa-text-([a-z]+):\s*([\d.]+)rem/', $root[1] ?? '', $m, PREG_SET_ORDER);

        $out = array();
        foreach ($m as $token) {
            $out[$token[1]] = (float) $token[2] * 16;
        }

        return $out;
    }

    /** At the default root a desktop renders as it did in px: 18, 15, 13, 12 and 11. */
    public function testTheScaleIsTheDesktopPixelsAtTheDefaultRoot(): void
    {
        $this->assertSame(array('xl' => 18.0, 'lg' => 15.0, 'md' => 13.0, 'sm' => 12.0, 'xs' => 11.0), $this->scale());
        $this->assertMatchesRegularExpression('/\bbody \{[^}]*font-size:\s*0\.75rem/', $this->css('owa.css'));
    }

    public function testTheRootIsAPercentageRaisedOnAPhone(): void
    {
        $css = $this->css('owa.css');

        $this->assertMatchesRegularExpression('/^html \{ font-size: 100%; \}/m', $css);
        $this->assertSame(1, preg_match('/@media \(max-width: 600px\) \{\s*html \{ font-size: ([\d.]+)%; \}/', $css, $m));
        $this->assertGreaterThan(100, (float) $m[1]);
    }

    /**
     * No text size is in px, so none is deaf to the root. A px value inside
     * max() is a floor, as a phone's 16px fields are.
     */
    public function testNoTextSizeIsInPixels(): void
    {
        $fixed = array();

        foreach (self::STYLESHEETS as $file) {
            $css = preg_replace('#/\*.*?\*/#s', '', $this->css($file));

            preg_match_all('/(?<![-\w])font-size:\s*([^;}]+)/', $css, $m);

            foreach ($m[1] as $value) {
                if (preg_match('/\dpx/', $value) && ! preg_match('/^max\(\s*[\d.]+px\s*,\s*[\d.]+rem\s*\)/', trim($value))) {
                    $fixed[] = "$file: font-size: " . trim($value);
                }
            }
        }

        $this->assertSame(array(), $fixed);
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
