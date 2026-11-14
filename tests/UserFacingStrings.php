<?php

/**
 * Finds user-facing English that does not go through the catalogue
 * (CoreAPI::t() / tn()), for the guard tests.
 *
 * A template is read as tokens: its HTML is the T_INLINE_HTML between the PHP
 * blocks, with each block masked, so a text node or attribute that is only PHP
 * output has nothing left to read. What is left and holds a word is English
 * written into the page:
 *
 *   - a text node, outside <script>, <style>, <pre>, <code> and comments;
 *   - a user-facing attribute (USER_ATTRIBUTES; `value` on a button or a
 *     submit/button/reset input), or a literal given to confirm(), alert() or
 *     prompt() in an on* attribute;
 *   - a PHP string literal that is output: echoed, printed, given to out() or
 *     outMarkup() as its text, or to sprintf()/printf() -- anywhere but inside
 *     a t() or tn() call. A literal holding markup is read as HTML, so its tags
 *     and their non-user-facing attributes pass.
 *
 * A word is two or more letters left after removing entities, sprintf
 * placeholders, URLs and NOT_LANGUAGE.
 */
final class UserFacingStrings
{
    /** Attributes a reader sees or hears. */
    const USER_ATTRIBUTES = [
        'title', 'alt', 'placeholder', 'label', 'aria-label', 'aria-description', 'aria-placeholder',
        'aria-roledescription', 'aria-valuetext', 'data-owa-confirm-title', 'data-owa-confirm-body',
        'data-owa-confirm-proceed',
    ];

    /** Elements whose contents are not language: code, or another language's source. */
    const SKIPPED_ELEMENTS = ['script', 'style', 'pre', 'code'];

    /**
     * Text that is a name, not language: removed before looking for a word.
     * Each is a whole-word match.
     */
    const NOT_LANGUAGE = [
        'Open Web Analytics' => 'the product name',
        'OWA'                => 'the product name',
    ];

    /** The fix, said once per finding. */
    const FIX = 'wrap it in \OWA\Core\CoreAPI::t() (tn() for a count), e.g. <?php $view->out( \OWA\Core\CoreAPI::t( \'...\' ) ); ?>';

    /**
     * The untranslated English in a template.
     *
     * @return list<array{line:int, what:string, text:string}>
     */
    public static function inTemplate(string $source): array
    {
        $html   = '';
        $blocks = [];   // offset of its mark in $html => the block's PHP tokens
        $block  = null;

        foreach (token_get_all($source) as $t) {
            $id   = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;

            if ($id === T_INLINE_HTML) {
                $html .= $text;
                continue;
            }

            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $block = strlen($html);
                $html .= "\x01";
                $blocks[$block] = [];
            }

            // The block is masked, but keeps its newlines so lines still count.
            $html .= str_repeat("\n", substr_count($text, "\n"));

            if ($block !== null) {
                $blocks[$block][] = $t;
            }

            if ($id === T_CLOSE_TAG) {
                $block = null;
            }
        }

        $contexts = [];
        $out      = self::inHtml($html, 1, $contexts);

        foreach ($blocks as $at => $tokens) {
            $out = array_merge($out, self::outputLiterals($tokens, $contexts[$at] ?? 'text'));
        }

        usort($out, function ($a, $b) { return $a['line'] <=> $b['line']; });

        return $out;
    }

    /**
     * The untranslated English in HTML: text nodes and user-facing attributes.
     * "\x01" marks PHP output, which is not read; $contexts is filled with
     * where each mark sits, by offset: 'text', 'skip' (script, style, code,
     * comment), 'tag' (between attributes) or 'attr <element> <type> <name>'.
     *
     * @return list<array{line:int, what:string, text:string}>
     */
    public static function inHtml(string $html, int $first_line, array &$contexts = []): array
    {
        $out  = [];
        $len  = strlen($html);
        $i    = 0;
        $line = function (int $at) use ($html, $first_line): int {
            return $first_line + substr_count($html, "\n", 0, $at);
        };
        $mark = function (int $from, int $to, $context) use ($html, &$contexts): void {
            for ($at = strpos($html, "\x01", $from); $at !== false && $at < $to; $at = strpos($html, "\x01", $at + 1)) {
                $contexts[$at] = is_callable($context) ? $context($at) : $context;
            }
        };

        while ($i < $len) {
            $lt = strpos($html, '<', $i);
            $lt = $lt === false ? $len : $lt;

            if ($lt > $i) {
                $text = substr($html, $i, $lt - $i);
                $mark($i, $lt, 'text');

                if (self::hasWords($text)) {
                    $out[] = ['line' => $line($i + strspn($text, " \t\r\n")), 'what' => 'text', 'text' => self::squash($text)];
                }
            }

            if ($lt >= $len) {
                break;
            }

            if (substr($html, $lt, 4) === '<!--') {
                $end = strpos($html, '-->', $lt + 4);
                $i   = $end === false ? $len : $end + 3;
                $mark($lt, $i, 'skip');
                continue;
            }

            if (substr($html, $lt, 2) === '<!' || substr($html, $lt, 2) === '<?') {
                // A declaration: <!DOCTYPE html>, <![CDATA[ ... ]]>.
                $end = strpos($html, '>', $lt);
                $i   = $end === false ? $len : $end + 1;
                $mark($lt, $i, 'skip');
                continue;
            }

            if (!preg_match('#\G<(/?)([a-zA-Z][a-zA-Z0-9-]*)#', $html, $m, 0, $lt)) {
                // A '<' that opens no tag is text.
                $next = strpos($html, '<', $lt + 1);
                $next = $next === false ? $len : $next;
                $text = substr($html, $lt, $next - $lt);
                $mark($lt, $next, 'text');

                if (self::hasWords($text)) {
                    $out[] = ['line' => $line($lt), 'what' => 'text', 'text' => self::squash($text)];
                }

                $i = $next;
                continue;
            }

            $name   = strtolower($m[2]);
            $from   = $lt + strlen($m[0]);
            $end    = self::tagEnd($html, $from);
            $inside = substr($html, $from, $end - $from);
            $type   = self::attributeValue($inside, 'type');
            $attrs  = self::attributes($inside, $from);

            $mark($lt, $end, function (int $at) use ($attrs, $name, $type): string {
                foreach ($attrs as $a) {
                    if ($at >= $a['at'] && $at < $a['at'] + $a['length']) {
                        return 'attr ' . $name . ' ' . ($type === '' ? '-' : $type) . ' ' . $a['name'];
                    }
                }

                return 'tag ' . $name . ' ' . ($type === '' ? '-' : $type);
            });

            if ($m[1] === '') {
                foreach ($attrs as $a) {
                    $why = self::attributeProblem($name, $a, $type);

                    if ($why !== null) {
                        $out[] = ['line' => $line($a['at']), 'what' => $why, 'text' => self::squash($a['value'])];
                    }
                }
            }

            $i = min($len, $end + 1);

            if ($m[1] === '' && in_array($name, self::SKIPPED_ELEMENTS, true)) {
                $close = stripos($html, '</' . $name, $i);
                $close = $close === false ? $len : $close;
                $mark($i, $close, 'skip');
                $i = $close;
            }
        }

        return $out;
    }

    /** The index of the '>' that ends a tag, quotes respected. */
    private static function tagEnd(string $html, int $from): int
    {
        $len   = strlen($html);
        $quote = null;

        for ($j = $from; $j < $len; $j++) {
            $c = $html[$j];

            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '>') {
                return $j;
            }
        }

        return $len;
    }

    /** @return list<array{name:string, value:string, at:int, length:int}> */
    private static function attributes(string $inside, int $offset): array
    {
        preg_match_all('#([^\s"\'>/=\x01]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\']+))#', $inside, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $out = [];

        foreach ($all as $a) {
            $value = $a[3][0] !== '' ? $a[3][0] : (($a[4][0] ?? '') !== '' ? $a[4][0] : ($a[5][0] ?? ''));
            $out[] = ['name' => strtolower($a[1][0]), 'value' => $value, 'at' => $offset + $a[2][1], 'length' => strlen($a[2][0])];
        }

        return $out;
    }

    private static function attributeValue(string $tag, string $name): string
    {
        foreach (self::attributes($tag, 0) as $a) {
            if ($a['name'] === $name) {
                return strtolower($a['value']);
            }
        }

        return '';
    }

    /** Why an attribute holds untranslated English, or null. */
    private static function attributeProblem(string $element, array $a, string $type): ?string
    {
        $name = $a['name'];

        if (in_array($name, self::USER_ATTRIBUTES, true)
            || ($name === 'value' && ($element === 'button' || ($element === 'input' && in_array($type, ['submit', 'button', 'reset'], true))))) {
            return self::hasWords($a['value']) ? $name . ' attribute' : null;
        }

        if (strpos($name, 'on') === 0
            && preg_match_all('#\b(confirm|alert|prompt)\s*\(\s*([\'"])(.*?)\2#s', html_entity_decode($a['value'], ENT_QUOTES), $calls)) {
            foreach ($calls[3] as $said) {
                if (self::hasWords($said)) {
                    return $name . ' attribute';
                }
            }
        }

        return null;
    }

    /**
     * Whether text holds a word of language: two or more letters left after
     * removing PHP output, entities, sprintf placeholders, URLs and names.
     */
    public static function hasWords(string $text): bool
    {
        $text = str_replace("\x01", ' ', $text);
        $text = preg_replace('/&(#\d+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);?/', ' ', $text);
        $text = preg_replace('/%(\d+\$)?[-+ 0#]*\d*(\.\d+)?[bcdeEfFgGosuxX%]/', ' ', (string) $text);
        $text = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', ' ', (string) $text);
        // Identifiers, file and host names: a word joined to another by . or _.
        $text = preg_replace('/[\w-]*\w[._]\w[\w.-]*/u', ' ', (string) $text);

        foreach (array_keys(self::NOT_LANGUAGE) as $name) {
            $text = preg_replace('/(?<![\p{L}])' . preg_quote($name, '/') . '(?![\p{L}])/u', ' ', (string) $text);
        }

        return (bool) preg_match('/\p{L}{2,}/u', (string) $text);
    }

    private static function squash(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', str_replace("\x01", '<?php ?>', $text)));

        return strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
    }

    /** Calls whose string arguments are output. */
    const OUTPUT_CALLS = ['out', 'outmarkup', 'sprintf', 'printf', 'vsprintf', 'vprintf'];

    /** Calls whose string arguments are catalogued. */
    const CATALOGUE_CALLS = ['t', 'tn'];

    /** Operators whose string operand is compared, not output. */
    const COMPARISONS = [T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_SPACESHIP];

    /**
     * PHP string literals that one PHP block outputs without t() or tn().
     *
     * An echo, print or <?= statement, and an out() / outMarkup() / sprintf()
     * / printf() call, outputs the literals directly in it -- not those given
     * to another call (makeLink(), date(), array()...), whose arguments are not
     * text, nor those compared. Inside t() or tn() a literal is catalogued.
     *
     * What counts as English depends on where the block sits ($context, from
     * inHtml()): in a text node a literal is read as HTML; in an attribute,
     * only a user-facing one's value counts; between attributes, the literal
     * is read as attributes; in a script or style, nothing counts.
     *
     * @param  array  $tokens   token_get_all() output, PHP tokens only
     * @param  string $context  where the block's output lands
     * @return list<array{line:int, what:string, text:string}>
     */
    public static function outputLiterals(array $tokens, string $context = 'text'): array
    {
        if ($context === 'skip') {
            return [];
        }

        $tokens = array_values(array_filter($tokens, function ($t) {
            return !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
        }));

        $out   = [];
        // One frame per open bracket: the call it belongs to (lowercase name,
        // '' for none) and which of its arguments is being read.
        $stack = [];
        // The depth at which an echo statement is open, or null.
        $echo  = null;
        $n     = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t    = $tokens[$i];
            $id   = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;

            if ($id === T_ECHO || $id === T_PRINT || $id === T_OPEN_TAG_WITH_ECHO) {
                $echo = count($stack);
                continue;
            }

            if ($echo !== null && count($stack) === $echo && ($text === ';' || $id === T_CLOSE_TAG)) {
                $echo = null;
            }

            if ($text === '(' || $text === '[' || $text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $prev = $tokens[$i - 1] ?? null;
                $call = $text === '(' && is_array($prev) && in_array($prev[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_ISSET, T_EMPTY], true)
                    ? strtolower(substr((string) strrchr('\\' . $prev[1], '\\'), 1)) : ($text === '(' ? '' : '[');
                $stack[] = [$call, 0];
                continue;
            }

            if ($text === ',' && $stack) {
                $stack[count($stack) - 1][1]++;
                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                array_pop($stack);

                if ($echo !== null && count($stack) < $echo) {
                    $echo = null;
                }

                continue;
            }

            if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            $before = $tokens[$i - 1] ?? null;
            $after  = $tokens[$i + 1] ?? null;

            if ((is_array($before) && in_array($before[0], self::COMPARISONS, true))
                || (is_array($after) && in_array($after[0], self::COMPARISONS, true))) {
                continue;
            }

            // The innermost call that is not a plain grouping parenthesis.
            $call = '';
            $arg  = 0;
            for ($d = count($stack) - 1; $d >= 0; $d--) {
                if ($stack[$d][0] !== '') {
                    list($call, $arg) = $stack[$d];
                    break;
                }
            }

            if (in_array($call, self::CATALOGUE_CALLS, true)) {
                continue;
            }

            // A format's arguments land where the format says, so only the
            // format is read; outMarkup()'s are markup, read as HTML.
            $output = (in_array($call, self::OUTPUT_CALLS, true) && ($arg === 0 || $call === 'outmarkup'))
                || ($call === '' && $echo !== null && count($stack) >= $echo);

            if (!$output) {
                continue;
            }

            $value = $id === T_CONSTANT_ENCAPSED_STRING ? self::unquote($text) : $text;

            foreach (self::literalProblems($value, (int) $t[2], $context) as $found) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /** The English in a literal output where $context says. */
    private static function literalProblems(string $value, int $line, string $context): array
    {
        $parts = explode(' ', $context);

        if ($parts[0] === 'attr') {
            $why = self::attributeProblem($parts[1], ['name' => $parts[3], 'value' => $value], $parts[2] === '-' ? '' : $parts[2]);

            return $why === null ? [] : [['line' => $line, 'what' => 'output literal (' . $why . ')', 'text' => self::squash($value)]];
        }

        if ($parts[0] === 'tag') {
            $out = [];

            foreach (self::attributes($value, 0) as $a) {
                $why = self::attributeProblem($parts[1], $a, $parts[2] === '-' ? '' : $parts[2]);

                if ($why !== null) {
                    $out[] = ['line' => $line, 'what' => 'output literal (' . $why . ')', 'text' => self::squash($a['value'])];
                }
            }

            return $out;
        }

        $out = [];

        foreach (self::inHtml($value, $line) as $found) {
            $found['what'] = 'output literal (' . $found['what'] . ')';
            $out[]         = $found;
        }

        return $out;
    }

    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'" ? strtr($body, ["\\\\" => "\\", "\\'" => "'"]) : stripcslashes($body);
    }

    /** Array keys whose value is a message or label a person reads. */
    const SINK_KEYS = [
        'errorMsg', 'error_msg', 'error', 'message', 'headline', 'msg', 'pattern_problem', 'status_msg',
        'title', 'page_title', 'legend', 'heading', 'anchortext', 'overview_label', 'proceed', 'hint', 'label',
    ];

    /** Calls whose first argument is a message a person reads. */
    const SINK_CALLS = ['refuse', 'seterrormessage', 'adderror', 'settitle', 'addrequesterror'];

    /** set( '<key>', ... ): view values a person reads. */
    const SINK_SET_KEYS = ['error_msg', 'status_msg', 'headline', 'page_title', 'title'];

    /** Calls whose argument (by 0-based position) is a nav label. */
    const SINK_ARGUMENTS = ['addnavigationsubgroup' => 2, 'addnavigationlinkinsubgroup' => 2];

    /**
     * String literals given to a message or label sink without t() or tn():
     * the value of a SINK_KEYS key, the first argument of a SINK_CALLS call
     * or of set() for a SINK_SET_KEYS key, a nav label (SINK_ARGUMENTS) and,
     * in a settings.php ($settings), the description of a setting that has a
     * label. Within the value, a literal is bare when it is not inside a call
     * (a ternary's branches, `?:`) or is the format given to sprintf();
     * a literal compared with ==, or inside a constant declaration (a label
     * table the extractor reads as data), is not a message.
     *
     * @return list<array{line:int, what:string, text:string}>
     */
    public static function inMessageSinks(string $source, bool $settings = false): array
    {
        $tokens = array_values(array_filter(token_get_all($source), function ($t) {
            return !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
        }));
        $n      = count($tokens);
        $text   = function ($i) use ($tokens) {
            return isset($tokens[$i]) ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]) : null;
        };
        $const  = false;
        $out    = [];
        $starts = [];   // token index where a sink's value starts => what it is

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && $t[0] === T_CONST) {
                $const = true;
            } elseif ($const && $text($i) === ';') {
                $const = false;
            }

            if ($const) {
                continue;
            }

            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_ARROW) {
                $key = substr($t[1], 1, -1);

                if (in_array($key, self::SINK_KEYS, true)
                    || ($settings && $key === 'description' && self::siblingKey($tokens, $i, 'label'))) {
                    $starts[$i + 2] = "'$key' value";
                }

                continue;
            }

            if (is_array($t) && $t[0] === T_STRING && $text($i + 1) === '(') {
                $name = strtolower($t[1]);

                if (in_array($name, self::SINK_CALLS, true)) {
                    $starts[$i + 2] = $t[1] . '() message';
                } elseif ($name === 'set' && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array(substr($tokens[$i + 2][1], 1, -1), self::SINK_SET_KEYS, true) && $text($i + 3) === ',') {
                    $starts[$i + 4] = 'set( ' . $tokens[$i + 2][1] . ' ) value';
                } elseif (isset(self::SINK_ARGUMENTS[$name])) {
                    $at = self::argumentStart($tokens, $i + 1, self::SINK_ARGUMENTS[$name]);

                    if ($at !== null) {
                        $starts[$at] = $t[1] . '() label';
                    }
                }
            }
        }

        foreach ($starts as $start => $what) {
            foreach (self::bareLiteralsFrom($tokens, $start) as $literal) {
                $value = self::unquote($literal[1]);

                if (self::hasWords(strip_tags($value))) {
                    $out[] = ['line' => (int) $literal[2], 'what' => $what, 'text' => self::squash($value)];
                }
            }
        }

        usort($out, function ($a, $b) { return $a['line'] <=> $b['line']; });

        return $out;
    }

    /** Whether the array holding the key at $i also has $key at its own level. */
    private static function siblingKey(array $tokens, int $i, string $key): bool
    {
        $depth = 0;

        for ($j = $i - 1; $j >= 0; $j--) {
            $x = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($x === ')' || $x === ']') {
                $depth++;
            } elseif ($x === '(' || $x === '[') {
                if ($depth-- === 0) {
                    break;
                }
            } elseif ($depth === 0 && $x === "'$key'" && ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW) {
                return true;
            }
        }

        $depth = 0;

        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $x = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($x === '(' || $x === '[') {
                $depth++;
            } elseif ($x === ')' || $x === ']') {
                if ($depth-- === 0) {
                    break;
                }
            } elseif ($depth === 0 && $x === "'$key'" && ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW) {
                return true;
            }
        }

        return false;
    }

    /** The index where argument $arg of the call whose '(' is at $open starts. */
    private static function argumentStart(array $tokens, int $open, int $arg): ?int
    {
        $depth = 0;
        $seen  = 0;

        for ($j = $open + 1, $n = count($tokens); $j < $n; $j++) {
            if ($seen === $arg) {
                return $j;
            }

            $x = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($x === '(' || $x === '[') {
                $depth++;
            } elseif ($x === ')' || $x === ']') {
                if ($depth-- === 0) {
                    return null;
                }
            } elseif ($x === ',' && $depth === 0) {
                $seen++;
            }
        }

        return null;
    }

    /**
     * The bare literals of the expression starting at $start, up to the , ) ]
     * or ; that ends it: those outside any call, and sprintf()'s format.
     *
     * @return list<array>
     */
    private static function bareLiteralsFrom(array $tokens, int $start): array
    {
        $out   = [];
        $stack = [];   // per open bracket: [call name, argument index]

        for ($j = $start, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            $x = is_array($t) ? $t[1] : $t;

            if ($x === '(' || $x === '[') {
                $prev = $tokens[$j - 1] ?? null;
                $p    = is_array($prev) ? $prev[1] : $prev;

                if ($x === '(' && is_array($prev) && in_array($prev[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY], true)) {
                    $call = strtolower(substr((string) strrchr('\\' . $prev[1], '\\'), 1));
                } elseif ($x === '[' && (in_array($p, [']', ')', '}'], true) || (is_array($prev) && in_array($prev[0], [T_VARIABLE, T_STRING], true)))) {
                    $call = '[]';   // an index, not a value
                } else {
                    $call = '';
                }

                $stack[] = [$call, 0];
                continue;
            }

            if ($x === ')' || $x === ']') {
                if (!$stack) {
                    break;
                }

                array_pop($stack);
                continue;
            }

            if (!$stack && ($x === ',' || $x === ';')) {
                break;
            }

            if ($x === ',') {
                $stack[count($stack) - 1][1]++;
                continue;
            }

            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $before = $tokens[$j - 1] ?? null;
            $after  = $tokens[$j + 1] ?? null;

            if ((is_array($before) && in_array($before[0], self::COMPARISONS, true))
                || (is_array($after) && in_array($after[0], self::COMPARISONS, true))
                || (is_array($after) && $after[0] === T_DOUBLE_ARROW)) {
                continue;
            }

            $call = '';
            $arg  = 0;
            for ($d = count($stack) - 1; $d >= 0; $d--) {
                if ($stack[$d][0] !== '') {
                    list($call, $arg) = $stack[$d];
                    break;
                }
            }

            if ($call === '' || ($call === 'sprintf' && $arg === 0)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** A finding as the line a test prints. */
    public static function describe(string $file, array $found): string
    {
        return sprintf("%s:%d: untranslated %s \"%s\": %s", $file, $found['line'], $found['what'], $found['text'], self::FIX);
    }
}
