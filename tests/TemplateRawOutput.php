<?php

/**
 * What a template prints without escaping: the guard TemplateOutputGuardTest
 * runs over every template.
 *
 * An output is an echo, print, <?= or printf()/vprintf() statement, or an
 * out() told not to escape ($view->out( $x, false )). It is raw when a value
 * reaches the page that is not one of:
 *
 *   - a literal, a constant, or a class constant;
 *   - the result of a call that escapes for its context (SAFE_CALLS), or of a
 *     cast to a number;
 *   - a ternary's condition (it chooses the output; it is not printed).
 *
 * An output that prints trusted markup (a rendered template, HTML a class
 * builds and escapes itself) carries a comment on its lines with "trusted:"
 * and why.
 */
final class TemplateRawOutput
{
    /** Calls whose result is escaped for where a template puts it. */
    const SAFE_CALLS = [
        'escape', 'scriptJson', 'makeJson', 'makeLink', 'makeAbsoluteLink', 'createNonceFormField',
        'codeBlock', 'safeHref', 'makePaginationFromResultSet', 'number_format', 'intval', 'count',
        'htmlspecialchars',
    ];

    /** Calls that print what they are given: their arguments are read instead. */
    const PASS_THROUGH = [ 'sprintf', 'vsprintf', 'implode', 'trim' ];

    /** The marker a trusted output's comment carries. */
    const MARKER = 'trusted:';

    /**
     * @param  string $source a template's PHP source
     * @return list<array{line: int, what: string}> each raw output
     */
    public static function inTemplate(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), function ($t) {
            return !is_array($t) || $t[0] !== T_WHITESPACE;
        }));
        $found = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $statement = self::outputAt($tokens, $i);

            if ($statement === null) {
                continue;
            }

            [$expr, $end] = $statement;
            $line = self::lineOf($tokens, $i, false);
            $last = self::lineOf($tokens, $end, true);
            $raw = self::rawValues($expr);

            if ($raw && !self::marked($tokens, $line, $last)) {
                $found[] = ['line' => $line, 'what' => implode(', ', array_unique($raw))];
            }
        }

        return $found;
    }

    /**
     * The expression an output statement at $i prints, and the index of its
     * last token; null when none starts there.
     *
     * @return array{0: list<mixed>, 1: int}|null
     */
    private static function outputAt(array $tokens, int $i): ?array
    {
        $t = $tokens[$i];

        if (!is_array($t)) {
            return null;
        }

        if (in_array($t[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
            return self::expressionFrom($tokens, $i + 1);
        }

        $before = $tokens[$i - 1] ?? null;
        $isMethod = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

        if ($t[0] === T_STRING && in_array(strtolower($t[1]), ['printf', 'vprintf'], true) && !$isMethod
            && ($tokens[$i + 1] ?? null) === '(') {
            return self::expressionFrom($tokens, $i + 1);
        }

        // $view->out( $x, false ): printed as it is.
        if ($t[0] === T_STRING && $t[1] === 'out' && is_array($before) && $before[0] === T_OBJECT_OPERATOR
            && ($tokens[$i + 1] ?? null) === '(') {
            $close = self::closing($tokens, $i + 1);
            $args = self::splitArgs(array_slice($tokens, $i + 2, $close - $i - 2));

            if (count($args) >= 2 && self::isFalse($args[1])) {
                return [$args[0], $close];
            }
        }

        return null;
    }

    /** @return array{0: list<mixed>, 1: int} */
    private static function expressionFrom(array $tokens, int $j): array
    {
        $expr = [];
        $depth = 0;
        $n = count($tokens);

        for (; $j < $n; $j++) {
            $u = $tokens[$j];

            if ($u === ';' && $depth === 0) {
                break;
            }

            if (is_array($u) && $u[0] === T_CLOSE_TAG) {
                break;
            }

            if (in_array($u, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($u, [')', ']'], true)) {
                $depth--;
            }

            if (!is_array($u) || !in_array($u[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $expr[] = $u;
            }
        }

        return [$expr, min($j, $n - 1)];
    }

    /** @return list<string> each value printed unescaped */
    private static function rawValues(array $expr): array
    {
        // A segment is what lies between ( , : ? at one depth; on a ? the
        // segment before it is a condition and is dropped.
        $stack = [['done' => [], 'seg' => []]];
        $n = count($expr);
        $castNext = false;

        for ($i = 0; $i < $n; $i++) {
            $t = $expr[$i];
            $top = count($stack) - 1;

            if ($t === '(' || $t === '[') {
                $stack[] = ['done' => [], 'seg' => []];
                continue;
            }

            if ($t === ')' || $t === ']') {
                $inner = array_pop($stack);
                $top = count($stack) - 1;
                $stack[$top]['seg'] = array_merge($stack[$top]['seg'], $inner['done'], $inner['seg']);
                continue;
            }

            if ($t === ',' || $t === ':') {
                $stack[$top]['done'] = array_merge($stack[$top]['done'], $stack[$top]['seg']);
                $stack[$top]['seg'] = [];
                continue;
            }

            if ($t === '?') {
                // ?: prints its condition.
                if (($expr[$i + 1] ?? null) !== ':') {
                    $stack[$top]['seg'] = [];
                }
                continue;
            }

            if (is_array($t) && in_array($t[0], [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST], true)) {
                $castNext = true;
                continue;
            }

            if (is_array($t) && $t[0] === T_VARIABLE) {
                [$end, $call, $text] = self::chain($expr, $i);

                if (!$castNext && !in_array($call, self::SAFE_CALLS, true)) {
                    $stack[$top]['seg'][] = $text;
                }

                $castNext = false;
                $i = $end;
                continue;
            }

            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)) {
                $next = $expr[$i + 1] ?? null;
                $name = $t[1];
                $j = $i;

                if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                    $member = $expr[$i + 2] ?? null;

                    if (($expr[$i + 3] ?? null) !== '(') {
                        // A class constant.
                        $i += 2;
                        $castNext = false;
                        continue;
                    }

                    $name = is_array($member) ? $member[1] : '';
                    $j = $i + 2;
                } elseif ($next !== '(') {
                    // A constant: true, PHP_EOL, ...
                    $castNext = false;
                    continue;
                }

                $short = substr((string) strrchr('\\' . $name, '\\'), 1);

                if (in_array($short, self::PASS_THROUGH, true) && $j === $i) {
                    // Its arguments are read as a group of their own.
                    continue;
                }

                $close = self::closing($expr, $j + 1);

                // A method called on the result: the last call is what is printed.
                while (is_array($expr[$close + 1] ?? null) && in_array($expr[$close + 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                    && ($expr[$close + 3] ?? null) === '(') {
                    $short = is_array($expr[$close + 2]) ? $expr[$close + 2][1] : '';
                    $close = self::closing($expr, $close + 3);
                }

                if (!$castNext && !in_array($short, self::SAFE_CALLS, true)) {
                    $stack[$top]['seg'][] = self::text(array_slice($expr, $i, $close - $i + 1));
                }

                $castNext = false;
                $i = $close;
                continue;
            }

            if (is_array($t) && in_array($t[0], [T_NEW, T_FN, T_FUNCTION, T_INCLUDE, T_REQUIRE], true)) {
                $stack[$top]['seg'][] = self::text(array_slice($expr, $i));
                break;
            }
        }

        $raw = [];

        foreach ($stack as $level) {
            $raw = array_merge($raw, $level['done'], $level['seg']);
        }

        return $raw;
    }

    /**
     * A variable and what follows it: ->name, ->name(...), [...], (...).
     *
     * @return array{0: int, 1: string, 2: string} its last index, the method it
     *               ends calling ('' when none), its source
     */
    private static function chain(array $expr, int $i): array
    {
        $n = count($expr);
        $j = $i;
        $call = '';

        while ($j + 1 < $n) {
            $next = $expr[$j + 1];

            if (is_array($next) && in_array($next[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $member = $expr[$j + 2] ?? null;
                $j += 2;
                $call = '';

                if (($expr[$j + 1] ?? null) === '(') {
                    $call = is_array($member) ? $member[1] : '';
                    $j = self::closing($expr, $j + 1);
                }
                continue;
            }

            if ($next === '[') {
                $j = self::closing($expr, $j + 1);
                $call = '';
                continue;
            }

            if ($next === '(') {
                // A closure or callable in a variable.
                $j = self::closing($expr, $j + 1);
                $call = '';
                continue;
            }

            break;
        }

        return [$j, $call, self::text(array_slice($expr, $i, $j - $i + 1))];
    }

    /** The index of the bracket that closes the one at $open. */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);

        for ($k = $open; $k < $n; $k++) {
            if (in_array($tokens[$k], ['(', '['], true)) {
                $depth++;
            } elseif (in_array($tokens[$k], [')', ']'], true)) {
                $depth--;

                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $n - 1;
    }

    /** @return list<list<mixed>> a call's arguments */
    private static function splitArgs(array $tokens): array
    {
        $args = [[]];
        $depth = 0;

        foreach ($tokens as $t) {
            if (in_array($t, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($t, [')', ']'], true)) {
                $depth--;
            } elseif ($t === ',' && $depth === 0) {
                $args[] = [];
                continue;
            }

            $args[count($args) - 1][] = $t;
        }

        return $args;
    }

    private static function isFalse(array $arg): bool
    {
        $text = strtolower(self::text($arg));

        return $text === 'false' || $text === '0';
    }

    /** Whether a comment with the marker is on the lines $from to $to. */
    private static function marked(array $tokens, int $from, int $to): bool
    {
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
                && $t[2] >= $from && $t[2] <= $to && strpos($t[1], self::MARKER) !== false) {
                return true;
            }
        }

        return false;
    }

    /** The line a token is on: where it starts, or with $end where it ends. */
    private static function lineOf(array $tokens, int $i, bool $end): int
    {
        for ($k = $i; $k >= 0; $k--) {
            if (is_array($tokens[$k])) {
                return $tokens[$k][2] + ($end ? substr_count(rtrim($tokens[$k][1], "\n"), "\n") : 0);
            }
        }

        return 1;
    }

    private static function text(array $tokens): string
    {
        $out = '';

        foreach ($tokens as $t) {
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }
}
