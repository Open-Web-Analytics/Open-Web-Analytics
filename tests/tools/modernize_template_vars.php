<?php
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * Token-driven rewrite of OWA's own templates off the extract() contract.
 *
 * WHAT IT DOES
 * ------------
 * Templates historically received view data as bare locals, materialized by
 * `extract($this->vars)` in TemplateEngine::fetch(), and reached the template
 * helpers through `$this` (the include happens inside the method, so `$this` is
 * in scope). Both are invisible to static analysis: a missing key is simply an
 * undefined variable, which is a warning in scalar context and a FATAL in
 * foreach ("must be of type array|object, bool given").
 *
 * This rewrites OWA's own templates onto an explicit scope object:
 *     $tabs              ->  $view->tabs
 *     $this->out(...)    ->  $view->out(...)
 *
 * METHOD calls move to $view; PROPERTY reads stay on $this. `$this->config` is the
 * Template's own property, not a view var of the same name, and routing it through
 * ViewScope::__get would let the view var shadow it.
 *
 * extract() STAYS in fetch() as the deprecated compat path, so third-party
 * module templates, site-owner templates/local/ overrides and custom themes --
 * none of which OWA ships or can migrate -- keep working untouched.
 *
 * WHY IT IS BIASED TOWARD DOING NOTHING
 * -------------------------------------
 * Because extract() stays, a variable we FAIL to rewrite still resolves exactly
 * as before: harmless. A variable we WRONGLY rewrite hits ViewScope::__get and
 * throws: a 500. The two error directions are wildly asymmetric, so every rule
 * below resolves ambiguity by leaving the variable alone. A conservative pass
 * that misses some vars is a good outcome; an aggressive one is not.
 *
 * A variable is rewritten ONLY when ALL of these hold:
 *   1. It is never an assignment target anywhere in the file -- not `$x =`, not
 *      `$x['k'] =`, not `$x[] =`, not compound `$x .= ` (accumulators start
 *      undefined by design), not `foreach (... as $x)`, not `global`/`static`,
 *      not a function/closure parameter. ANY of those marks it local for the
 *      whole file, no matter where it appears.
 *   2. It is not a superglobal.
 *   3. Its name appears as a `->set('name', ...)` literal somewhere in the tree.
 *      That is the authoritative set of keys that can ever be in $this->vars,
 *      so a name absent from it cannot be a view var.
 *   4. It is not supplied by an INCLUDER's scope. Raw include/require of one
 *      template by another SHARES scope, so a free variable in a partial may be
 *      a parent's local rather than a view var. The include graph is walked and
 *      any name assigned by any transitive includer is treated as local here.
 *      Variables assigned by included conf/*.php files are handled the same way.
 *
 * Interpolation is handled through the token stream, so "$foo" becomes
 * "{$view->foo}" and "{$foo}" becomes "{$view->foo}" rather than being
 * corrupted by a textual substitution.
 *
 * USAGE
 *   php tests/tools/modernize_template_vars.php            # analyse, write no files
 *   php tests/tools/modernize_template_vars.php --apply    # rewrite in place
 *   php tests/tools/modernize_template_vars.php --file=X    # limit to one file
 */

$root = dirname(__DIR__, 2);
$apply = in_array('--apply', $argv, true);
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--file=')) { $only = substr($a, 7); }
}

const SUPERGLOBALS = [
    '$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE',
    '$_SESSION', '$_REQUEST', '$_ENV', '$this', '$view',
];

/** Every key that can ever land in TemplateEngine::$vars: the ->set('literal') surface. */
function collectSetNames(string $root): array {
    $names = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/Core'));
    $dirs = [$root . '/Core', $root . '/modules'];
    foreach ($dirs as $d) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
            $src = file_get_contents($f->getPathname());
            if (preg_match_all('/->set\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $src, $m)) {
                foreach ($m[1] as $n) { $names[$n] = true; }
            }
        }
    }
    return $names;
}

/**
 * Classify every variable in a file: which are assigned (=> local) and which are
 * only ever read (=> candidate view vars). Assignment wins globally per file.
 */
function analyseFile(string $path): array {
    $src = file_get_contents($path);
    $tokens = token_get_all($src);
    $assigned = [];
    $read = [];
    $suppressed = [];

    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE) { continue; }
        $name = $t[1];

        // `@$foo` -- the author explicitly marked this read as possibly-absent.
        // The @ operator suppresses DIAGNOSTICS, not EXCEPTIONS, so rewriting such
        // a read to $view->foo would convert a deliberately-tolerated missing var
        // into a thrown 500. Treat the whole name as off-limits in this file.
        for ($b = $i - 1; $b >= 0; $b--) {
            $p = $tokens[$b];
            if (is_array($p) && $p[0] === T_WHITESPACE) { continue; }
            if ($p === '@') { $suppressed[$name] = true; }
            break;
        }

        // --- does an assignment-ish construct claim this variable? ---
        // Look backward for foreach-as / global / static / function-param.
        $claimed = false;
        for ($b = $i - 1; $b >= 0; $b--) {
            $p = $tokens[$b];
            if (is_array($p) && in_array($p[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            if (is_array($p) && in_array($p[0], [T_AS, T_GLOBAL, T_STATIC, T_DOUBLE_ARROW], true)) {
                // `as $v`, `as $k => $v`, `global $x`, `static $x`
                if ($p[0] === T_DOUBLE_ARROW) {
                    // only a claim when this is the value half of `as $k => $v`
                    $claimed = looksLikeForeachAs($tokens, $b);
                } else {
                    $claimed = true;
                }
            }
            if (is_array($p) && in_array($p[0], [T_FUNCTION, T_FN], true)) { $claimed = true; }
            if ($p === '(' || $p === ',') {
                // could be a function parameter list; check if a `function` precedes
                $claimed = $claimed || precededByFunction($tokens, $b);
            }
            break;
        }
        if ($claimed) { $assigned[$name] = true; continue; }

        // --- forward scan: `$x =`, `$x['k'] =`, `$x[] =`, `$x .= `, `$x++` ---
        $j = $i + 1;
        $depth = 0;
        $isWrite = false;
        while ($j < $n) {
            $q = $tokens[$j];
            if (is_array($q) && in_array($q[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; continue; }
            if ($q === '[') { $depth++; $j++; continue; }
            if ($q === ']') { $depth--; $j++; continue; }
            if ($depth > 0) { $j++; continue; }
            if (is_array($q) && $q[0] === T_OBJECT_OPERATOR) { break; }  // $x->y : read of $x
            if ($q === '=') { $isWrite = true; break; }
            if (is_array($q) && in_array($q[0], [
                T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL,
                T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
                T_COALESCE_EQUAL, T_POW_EQUAL, T_INC, T_DEC,
            ], true)) { $isWrite = true; break; }
            break;
        }
        if ($isWrite) { $assigned[$name] = true; } else { $read[$name] = true; }
    }

    // assignment anywhere in the file wins
    foreach (array_keys($assigned) as $a) { unset($read[$a]); }
    return ['assigned' => $assigned, 'read' => $read, 'suppressed' => $suppressed,
            'tokens' => $tokens, 'src' => $src];
}

function looksLikeForeachAs(array $tokens, int $arrowIdx): bool {
    for ($b = $arrowIdx - 1; $b >= 0 && $b > $arrowIdx - 40; $b--) {
        $p = $tokens[$b];
        if (is_array($p) && $p[0] === T_AS) { return true; }
        if (is_array($p) && $p[0] === T_FOREACH) { return true; }
        if ($p === ';' || $p === '{') { return false; }
    }
    return false;
}

/** True when `$this` at $i is followed by `->name(` -- a method call, not a property read. */
function nextIsMethodCall(array $tokens, int $i): bool {
    $seenArrow = false;
    for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
        $q = $tokens[$j];
        if (is_array($q) && $q[0] === T_WHITESPACE) { continue; }
        if (!$seenArrow) {
            if (is_array($q) && $q[0] === T_OBJECT_OPERATOR) { $seenArrow = true; continue; }
            return false;
        }
        if (is_array($q) && $q[0] === T_STRING) { continue; }   // the member name
        return $q === '(';
    }
    return false;
}

function precededByFunction(array $tokens, int $idx): bool {
    for ($b = $idx - 1; $b >= 0 && $b > $idx - 30; $b--) {
        $p = $tokens[$b];
        if (is_array($p) && in_array($p[0], [T_FUNCTION, T_FN], true)) { return true; }
        if ($p === ';' || $p === '{' || $p === '}') { return false; }
    }
    return false;
}

/** Template-to-template and template-to-conf include edges (raw include shares scope). */
function collectIncludes(string $path, string $root): array {
    $src = file_get_contents($path);
    $out = [];
    if (preg_match_all('/(?:include|require)(?:_once)?\s*\(?\s*([^;]+);/', $src, $m)) {
        foreach ($m[1] as $expr) {
            if (preg_match('/[\'"]([A-Za-z0-9_\/.\-]+\.php)[\'"]/', $expr, $mm)) {
                $out[] = $mm[1];
            }
        }
    }
    return $out;
}

// ---------------------------------------------------------------- main

$setNames = collectSetNames($root);
$templates = glob($root . '/modules/*/templates/*.php') ?: [];
sort($templates);
if ($only) { $templates = array_values(array_filter($templates, fn($t) => str_contains($t, $only))); }

// Pass 1: analyse everything so includer locals are known before rewriting.
$info = [];
foreach ($templates as $t) { $info[$t] = analyseFile($t); }

// Build a map of basename -> assigned names, and who includes whom.
$assignedByBasename = [];
foreach ($info as $path => $d) { $assignedByBasename[basename($path)] = $d['assigned']; }

$ambient = [];   // path -> names that come from an includer's scope (treat as local)
foreach ($templates as $t) {
    foreach (collectIncludes($t, $root) as $inc) {
        $b = basename($inc);
        // the INCLUDED file inherits the INCLUDER's locals
        foreach (array_keys($info[$t]['assigned'] ?? []) as $a) {
            $ambient[$b][$a] = true;
        }
        // and variables the included conf/ file defines become locals in the includer
        $confPath = $root . '/' . ltrim($inc, '/');
        if (!str_contains($inc, 'templates') && is_file($confPath)) {
            $c = analyseFile($confPath);
            foreach (array_keys($c['assigned']) as $a) { $ambient[basename($t)][$a] = true; }
        }
    }
}

$totalRewrites = 0; $totalThis = 0; $skipped = []; $changedFiles = 0;

foreach ($templates as $path) {
    $d = $info[$path];
    $tokens = $d['tokens'];
    $amb = $ambient[basename($path)] ?? [];

    $rewritable = [];
    foreach (array_keys($d['read']) as $name) {
        if (in_array($name, SUPERGLOBALS, true)) { continue; }
        $bare = ltrim($name, '$');
        if (isset($d['suppressed'][$name])) { $skipped[$bare]['error-suppressed-read'] = true; continue; }
        if (isset($amb[$name])) { $skipped[$bare]['includer-scope'] = true; continue; }
        if (!isset($setNames[$bare])) { $skipped[$bare]['not-in-set-allowlist'] = true; continue; }
        $rewritable[$name] = true;
    }

    // ---- emit ----
    $out = '';
    $fileRewrites = 0; $fileThis = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) { $out .= $t; continue; }

        if ($t[0] !== T_VARIABLE) { $out .= $t[1]; continue; }

        // $this->helper(...) -> $view->helper(...), but ONLY for METHOD CALLS.
        //
        // A property read must be left alone: `$this->config` is the TEMPLATE OBJECT's
        // config, which is a different thing from a view var that happens to also be
        // called 'config'. Rewriting it to $view->config sends the read through
        // ViewScope::__get, which serves view vars -- so the view var shadows the
        // template property and the read silently returns the wrong value. That is
        // exactly how install_config_entry.php's db_supported_types loop emptied out
        // and left <select name="owa_db_type"> with no options. Only 2 property reads
        // exist in the whole corpus, so leaving them as $this-> costs nothing.
        if ($t[1] === '$this') {
            if (nextIsMethodCall($tokens, $i)) { $out .= '$view'; $fileThis++; }
            else { $out .= '$this'; }
            continue;
        }

        if (!isset($rewritable[$t[1]])) { $out .= $t[1]; continue; }

        $bare = ltrim($t[1], '$');
        // Inside a double-quoted string / heredoc, a bare "$foo" must become
        // "{$view->foo}" -- simple interpolation cannot express ->.
        if (inInterpolation($tokens, $i)) {
            $out .= '{$view->' . $bare . '}';
        } else {
            $out .= '$view->' . $bare;
        }
        $fileRewrites++;
    }

    // A template is included from inside TemplateEngine::fetch(), so $view is
    // injected by the includer and looks undefined to any tool analysing the file
    // on its own -- the same false positive $this used to produce (825 of them).
    // One declared anchor per template fixes that, and it is the single place a
    // per-page typed view model would later be named instead of ViewScope.
    $needsHeader = (str_contains($out, '$view') || $fileRewrites || $fileThis)
        && !str_contains($out, '@var \OWA\Core\ViewScope');
    if ($needsHeader) {
        $out = "<?php /** @var \\OWA\\Core\\ViewScope \$view */ ?>\n" . $out;
        $headersAdded = true;
    }

    if ($fileRewrites || $fileThis || $needsHeader) {
        $changedFiles++;
        $totalRewrites += $fileRewrites; $totalThis += $fileThis;
        $GLOBALS['headerCount'] = ($GLOBALS['headerCount'] ?? 0) + ($needsHeader ? 1 : 0);
        printf("  %-56s vars:%-4d \$this:%-4d hdr:%s\n", str_replace($root . '/', '', $path), $fileRewrites, $fileThis, $needsHeader ? 'y' : 'n');
        if ($apply) { file_put_contents($path, $out); }
    }
}

/**
 * True when token $i sits inside a double-quoted string or heredoc body, where
 * `$foo->bar` is NOT parsed as a property access and needs {} braces.
 * Already-braced ({$foo}) positions are detected via the preceding T_CURLY_OPEN.
 */
function inInterpolation(array $tokens, int $i): bool {
    $inString = false;
    for ($b = 0; $b < $i; $b++) {
        $p = $tokens[$b];
        if ($p === '"') { $inString = !$inString; continue; }
        if (is_array($p) && $p[0] === T_START_HEREDOC) { $inString = true; }
        if (is_array($p) && $p[0] === T_END_HEREDOC) { $inString = false; }
    }
    if (!$inString) { return false; }
    // if immediately preceded by {$ (T_CURLY_OPEN) the braces already exist
    for ($b = $i - 1; $b >= 0; $b--) {
        $p = $tokens[$b];
        if (is_array($p) && in_array($p[0], [T_WHITESPACE], true)) { continue; }
        if (is_array($p) && $p[0] === T_CURLY_OPEN) { return false; }
        break;
    }
    return true;
}

echo "\n";
printf("templates scanned : %d\n", count($templates));
printf("files changed     : %d\n", $changedFiles);
printf("var rewrites      : %d\n", $totalRewrites);
printf("\$this -> \$view    : %d\n", $totalThis);
printf("mode              : %s\n", $apply ? 'APPLIED' : 'dry run (no files written)');
if ($skipped) {
    echo "\nleft alone (conservative), by reason:\n";
    $byReason = [];
    foreach ($skipped as $name => $reasons) {
        foreach (array_keys($reasons) as $r) { $byReason[$r][] = $name; }
    }
    foreach ($byReason as $r => $names) {
        sort($names);
        printf("  %-22s %d: %s\n", $r, count($names), implode(', ', array_slice($names, 0, 12)) . (count($names) > 12 ? ' ...' : ''));
    }
}
