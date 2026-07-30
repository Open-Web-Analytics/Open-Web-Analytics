<?php
/**
 * Phase 6.5 — modernize OWA's own legacy `owa_*` class references to their new
 * PSR-4 namespaced names, using the compat map as the authoritative old->new
 * lookup.
 *
 * WHAT IT REWRITES (token-accurate, never touches comments/strings/property
 * names): a class-name TOKEN whose text is a legacy `owa_*` name (optionally
 * leading-backslash) that appears in a CLASS-REFERENCE position — extends,
 * new, instanceof, implements, a `::` static access, or a typehint before a
 * `$var` — is replaced with the fully-qualified new name, e.g.
 *   \owa_coreAPI::foo()      -> \OWA\Core\CoreAPI::foo()
 *   class X extends \owa_view -> class X extends \OWA\Core\View
 *   new owa_dbColumn          -> new \OWA\Module\Base\Classes\DbColumn
 *
 * WHY FQCN (not `use` + short name): a pure token substitution that cannot
 * collide with the file's own namespace/class and needs no import-block
 * analysis. Runtime-identical. The bridge stays for third parties + runtime
 * factory synthesis, so any ref this misses still resolves — no big-bang.
 *
 * CASE: legacy code references a couple of names in the "wrong" case
 * (owa_coreApi, owa_usersdeleteController). PHP class names are
 * case-insensitive so they worked; namespaced names are case-SENSITIVE, so we
 * canonicalize via a case-insensitive index of the map BEFORE looking up the
 * target.
 *
 * Usage:
 *   php tests/tools/modernize_legacy_refs.php --dry-run <path> [<path>...]
 *   php tests/tools/modernize_legacy_refs.php --write   <path> [<path>...]
 * Paths may be files or directories (recursed for *.php).
 */

require __DIR__ . '/../../owa_compat_aliases.php';

$args = array_slice($argv, 1);
$mode = null;
$paths = [];
foreach ($args as $a) {
    if ($a === '--dry-run') { $mode = 'dry'; }
    elseif ($a === '--write') { $mode = 'write'; }
    else { $paths[] = $a; }
}
if ($mode === null || !$paths) {
    fwrite(STDERR, "usage: modernize_legacy_refs.php --dry-run|--write <path>...\n");
    exit(2);
}

$map = owa_compat_class_map();
$ciMap = [];
foreach ($map as $old => $new) {
    $ciMap[strtolower($old)] = $new;
}

/** Collect *.php files from a path (file or dir). */
function collect(string $path): array {
    if (is_file($path)) return [$path];
    $out = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $fi) {
        $p = $fi->getPathname();
        if (substr($p, -4) === '.php'
            && strpos($p, '/vendor/') === false
            && strpos($p, '/node_modules/') === false) {
            $out[] = $p;
        }
    }
    sort($out);
    return $out;
}

$NAME_TOKENS = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE];

$totalRefs = 0;
$totalFiles = 0;

foreach ($paths as $path) {
    foreach (collect($path) as $file) {
        $src = file_get_contents($file);
        $toks = token_get_all($src);
        $n = count($toks);
        $out = '';
        $fileRefs = 0;

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (is_string($t)) { $out .= $t; continue; }
            if (!in_array($t[0], $NAME_TOKENS, true)) { $out .= $t[1]; continue; }

            $raw = $t[1];
            $hasLead = ($raw[0] === '\\');
            $name = ltrim($raw, '\\');
            // Qualified into a namespace already (contains \) -> new-style, skip.
            if (strpos($name, '\\') !== false) { $out .= $raw; continue; }
            if (strncmp($name, 'owa_', 4) !== 0) { $out .= $raw; continue; }

            // Resolve target via exact then case-insensitive map.
            $new = $map[$name] ?? ($ciMap[strtolower($name)] ?? null);
            if ($new === null) { $out .= $raw; continue; }

            // Confirm class-reference position via neighbouring significant tokens.
            $j = $i - 1;
            while ($j >= 0 && is_array($toks[$j])
                && in_array($toks[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j--;
            $prev = $j >= 0 ? $toks[$j] : null;
            $k2 = $i + 1;
            while ($k2 < $n && is_array($toks[$k2])
                && in_array($toks[$k2][0], [T_WHITESPACE, T_COMMENT], true)) $k2++;
            $next = $k2 < $n ? $toks[$k2] : null;

            $isRef = false;
            if (is_array($prev) && in_array($prev[0],
                [T_EXTENDS, T_NEW, T_INSTANCEOF, T_IMPLEMENTS], true)) $isRef = true;
            if (is_array($next) && $next[0] === T_DOUBLE_COLON) $isRef = true;
            if (!$isRef && is_array($next) && $next[0] === T_VARIABLE) $isRef = true; // typehint

            if (!$isRef) { $out .= $raw; continue; }

            // Rewrite to FQCN. Always leading-backslash (global -> absolute ns).
            $out .= '\\' . $new;
            $fileRefs++;
        }

        if ($fileRefs > 0) {
            $totalRefs += $fileRefs;
            $totalFiles++;
            if ($mode === 'write') {
                file_put_contents($file, $out);
                echo "rewrote $fileRefs  $file\n";
            } else {
                echo "would rewrite $fileRefs  $file\n";
            }
        }
    }
}

echo "\n" . ($mode === 'write' ? 'REWROTE' : 'DRY-RUN') . ": $totalRefs refs across $totalFiles files\n";
