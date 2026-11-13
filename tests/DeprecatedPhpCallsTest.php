<?php

use PHPUnit\Framework\TestCase;

/**
 * OWA's own PHP uses nothing deprecated on the newest PHP it supports.
 *
 *   - get_class() with no argument (deprecated in 8.3): static::class or self::class.
 *   - ReflectionProperty/ReflectionMethod::setAccessible() (deprecated in 8.5,
 *     a no-op since 8.1).
 *   - the predefined response-header variable of the http wrapper (deprecated
 *     in 8.5): read the stream's wrapper_data.
 *
 * Third-party code under vendor/ and node_modules/ is not checked.
 */
final class DeprecatedPhpCallsTest extends TestCase
{
    private const ROOTS = ['Core', 'modules', 'tests', 'api', 'conf'];

    /** @return array<string, string> relative path => source */
    private static function sources(): array
    {
        $root = dirname(__DIR__);
        $out  = [];

        foreach (self::ROOTS as $dir) {
            if (! is_dir("$root/$dir")) {
                continue;
            }
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

    /** @var array<string, string[]>|null relative path => lines, comments and string literals blanked */
    private static ?array $code = null;

    /** @return array<string, string[]> */
    private static function code(): array
    {
        if (self::$code !== null) {
            return self::$code;
        }

        self::$code = [];
        foreach (self::sources() as $rel => $source) {
            $stripped = '';
            foreach (token_get_all($source) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) {
                    $stripped .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $stripped .= is_array($t) ? $t[1] : $t;
            }
            self::$code[$rel] = explode("\n", $stripped);
        }

        return self::$code;
    }

    /** @return string[] "file:line" for every code line matching $pattern */
    private static function hits(string $pattern): array
    {
        $found = [];

        foreach (self::code() as $rel => $lines) {
            foreach ($lines as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $found[] = $rel . ':' . ($i + 1);
                }
            }
        }

        return $found;
    }

    public function testNoArgumentlessGetClass(): void
    {
        $this->assertSame([], self::hits('/\bget_class\s*\(\s*\)/'));
    }

    public function testNoSetAccessible(): void
    {
        $this->assertSame([], self::hits('/->\s*setAccessible\s*\(/'));
    }

    public function testNoResponseHeaderVariable(): void
    {
        $this->assertSame([], self::hits('/\$http_response_header\b/'));
    }

    /** The scan itself finds what it is looking for. */
    public function testTheScanSeesCode(): void
    {
        $this->assertNotSame([], self::hits('/\bnew\s+\\\\?ReflectionProperty\b/'));
    }
}
