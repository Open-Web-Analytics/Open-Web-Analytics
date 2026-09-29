<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every class a settings template uses is one a stylesheet defines.
 *
 * Custom Dimensions was written with field, field_help and submit, which no
 * stylesheet has, and rendered as bare browser defaults. The tracking-tag
 * textarea on Sites > Invocation was sized in columns and ran past its
 * container. Listed templates only: older ones carry JS hooks by class.
 */
final class TemplateClassesAreStyledTest extends TestCase
{
    public static function templates(): array
    {
        return [
            'custom dimensions' => ['custom_dimensions.php'],
            'custom dimension register' => ['custom_dimension_edit.php'],
            'invocation'        => ['invocation.php'],
        ];
    }

    /** @dataProvider templates */
    public function testEveryClassItUsesIsStyled(string $template): void
    {
        $markup = (string) file_get_contents(OWA_DIR . 'modules/Base/templates/' . $template);
        $css    = '';

        foreach ((array) glob(OWA_DIR . 'modules/Base/css/*.css') as $file) {
            $css .= file_get_contents($file);
        }

        preg_match_all('/class="([^"]+)"/', $markup, $m);

        $unstyled = [];

        foreach (array_unique(preg_split('/\s+/', implode(' ', $m[1]))) as $class) {
            if ($class !== '' && !preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css)) {
                $unstyled[] = $class;
            }
        }

        $this->assertSame([], $unstyled, 'classes no stylesheet defines');
    }

    /** A width in columns ignores the container; the tag must fit it. */
    public function testTheTrackingTagIsNotSizedInColumns(): void
    {
        $this->assertStringNotContainsString('cols=',
            (string) file_get_contents(OWA_DIR . 'modules/Base/templates/invocation.php'));
    }
}
