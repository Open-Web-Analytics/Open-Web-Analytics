<?php

use PHPUnit\Framework\TestCase;

/**
 * OWA's own templates read view data through $view and nothing else.
 *
 * fetch() no longer extract()s the view vars and includes each template from a
 * static closure (ViewScopeCompatTest), so a bare `$headline` is undefined and
 * `$this` does not exist. The template PHPStan gate reports an unguarded bare
 * read, but not one inside isset() or empty() -- and that is the read that now
 * fails silently, always false. This test finds both kinds.
 *
 * A variable counts as the template's own when the file assigns it, loops into
 * it, takes it as a parameter or lists it as a destructuring target. Anything
 * else it reads is a bare view var, unless it is listed in LOCALS_FROM_REQUIRE.
 */
final class TemplatesReadOnlyViewTest extends TestCase
{
    /** Variables a template defines by requiring a conf file that assigns them. */
    private const LOCALS_FROM_REQUIRE = [
        'install_defaults_entry.php' => ['timezones', 'countryCode2Name'],
    ];

    private const NOT_VIEW_DATA = ['view', '__owa_template_file', 'GLOBALS', '_GET', '_POST', '_SERVER',
        '_COOKIE', '_REQUEST', '_SESSION', '_FILES', '_ENV'];

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        $out = [];

        foreach (glob(dirname(__DIR__) . '/modules/*/templates/{,*/}*.php', GLOB_BRACE) as $file) {
            $out[substr($file, strlen(dirname(__DIR__)) + 1)] = [$file];
        }

        return $out;
    }

    /** @return array{0: string[], 1: string[]} [variables read, variables the file defines] */
    private static function variables(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source),
            fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

        $read = $defined = [];
        $depth = 0;           // inside list( ... ) or [ ... ] =
        $params = false;

        foreach ($tokens as $i => $t) {
            $text = is_array($t) ? $t[1] : $t;

            if (is_array($t) && $t[0] === T_FUNCTION || is_array($t) && $t[0] === T_FN) {
                $params = true;
            }
            if ($params && $text === '{' || $params && is_array($t) && $t[0] === T_DOUBLE_ARROW) {
                $params = false;
            }

            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }

            $name = substr($t[1], 1);
            $read[] = $name;

            // What follows the variable, skipping any [index] chain.
            $j = $i + 1;
            while (isset($tokens[$j]) && $tokens[$j] === '[') {
                for ($nest = 0; isset($tokens[$j]); $j++) {
                    $nest += $tokens[$j] === '[' ? 1 : ($tokens[$j] === ']' ? -1 : 0);
                    if ($nest === 0) { $j++; break; }
                }
            }
            $next = $tokens[$j] ?? null;
            $prev = $tokens[$i - 1] ?? null;

            $assigns = $next === '=' || (is_array($next) && in_array($next[0],
                [T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_COALESCE_EQUAL], true));
            $loops   = is_array($prev) && in_array($prev[0], [T_AS, T_DOUBLE_ARROW], true)
                && preg_match('/^\s*(\)|=>)/', is_array($next) ? $next[1] : (string) $next);
            $listed  = self::inDestructuring($tokens, $i);

            if ($assigns || $loops || $params || $listed || (is_array($prev) && in_array($prev[0], [T_GLOBAL, T_STATIC], true))) {
                $defined[] = $name;
            }
        }

        return [array_unique($read), array_unique($defined)];
    }

    /** Is the variable at $i inside list(...) or a [...] that is assigned to? */
    private static function inDestructuring(array $tokens, int $i): bool
    {
        for ($k = $i - 1, $nest = 0; $k >= 0; $k--) {
            $t = $tokens[$k];
            if ($t === ')' || $t === ']') { $nest++; continue; }
            if ($t === '(' || $t === '[') {
                if ($nest > 0) { $nest--; continue; }
                $before = $tokens[$k - 1] ?? null;
                if ($t === '(' && is_array($before) && $before[0] === T_LIST) {
                    return true;
                }
                if ($t === '[') {
                    // Find the matching ] and check for an = after it.
                    for ($m = $k, $n = 0; isset($tokens[$m]); $m++) {
                        $n += $tokens[$m] === '[' ? 1 : ($tokens[$m] === ']' ? -1 : 0);
                        if ($n === 0) { return ($tokens[$m + 1] ?? null) === '='; }
                    }
                }
                return false;
            }
            if ($t === ';' || $t === '{' || $t === '}') {
                return false;
            }
        }

        return false;
    }

    /** @dataProvider templates */
    public function testReadsViewDataOnlyThroughView(string $file): void
    {
        [$read, $defined] = self::variables(file_get_contents($file));

        $this->assertNotContains('this', $read, 'a template has no $this; use $view');

        $allowed = array_merge($defined, self::NOT_VIEW_DATA, self::LOCALS_FROM_REQUIRE[basename($file)] ?? []);
        $bare = array_values(array_diff($read, $allowed, ['this']));

        $this->assertSame([], $bare, 'read through $view instead: ' . implode(', ', $bare));
    }

    /** The scan finds what it is for, so a clean result means something. */
    public function testTheScanFindsBareReads(): void
    {
        [$read, $defined] = self::variables('<?php if (isset($caption)) { echo $caption; } foreach ($view->rows as $k => $row) { echo $row; } $n = 1; [$a, $b] = [1, 2]; ?>');

        $this->assertSame(['caption'], array_values(array_diff($read, $defined, ['view'])));
        $this->assertContains('this', self::variables('<?php echo $this->config; ?>')[0]);
    }
}
