<?php

use PHPUnit\Framework\TestCase;

/**
 * Every unserialize() in OWA's own code names allowed_classes, so a stored
 * value is read back as data and never as an object.
 *
 * Third-party code under vendor/ and node_modules/ is not checked.
 */
final class UnserializeAllowedClassesTest extends TestCase
{
    private const ROOTS = ['Core', 'modules'];

    /** @return array<string, string> relative path => source */
    private static function sources(): array
    {
        $root = dirname(__DIR__);
        $out  = [];

        foreach (self::ROOTS as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                $rel = substr($file->getPathname(), strlen($root) + 1);

                if ($file->getExtension() !== 'php' || preg_match('#(^|/)(vendor|node_modules)/#', $rel)) {
                    continue;
                }

                $out[$rel] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /**
     * Each call of the function unserialize(), as the source between its
     * parentheses. A method of that name is not a call of it.
     *
     * @return string[]
     */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $calls  = [];

        foreach ($tokens as $i => $t) {
            $name = is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                ? ltrim(strtolower($t[1]), '\\') : '';

            if ($name !== 'unserialize' || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $before = $tokens[$i - 1] ?? null;

            if (is_array($before) && in_array($before[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $depth = 0;
            $args  = '';

            for ($j = $i + 1; $j < count($tokens); $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')' && --$depth === 0) {
                    break;
                }

                $args .= $text;
            }

            $calls[] = $args;
        }

        return $calls;
    }

    public function testTheCheckFindsACallWithoutIt(): void
    {
        $this->assertSame(['($row[\'value\']'], self::calls('<?php $a = unserialize( $row[\'value\'] );'));
        $this->assertSame([], self::calls('<?php function unserialize( $x ) {} $c->unserialize( $x );'));
    }

    public function testEveryUnserializeNamesAllowedClasses(): void
    {
        $missing = [];

        foreach (self::sources() as $rel => $source) {
            foreach (self::calls($source) as $args) {
                if (strpos($args, 'allowed_classes') === false) {
                    $missing[] = $rel . ': unserialize' . $args . ')';
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
