<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * No source file uses a variable variable.
 *
 * This exists because of a real, long-lived defect. VisualizationFunnel read a
 * result set as a doubled dollar sign -- PHP took the object's value as the
 * NAME of a variable to look up, and fatalled converting stdClass to string.
 * The report returned a 500 for any goal that had a funnel, and because no
 * install had ever configured one, nothing ever ran the line. It survived a
 * full PSR-4 relocation of every module.
 *
 * A doubled dollar is nearly always that typo rather than an intended
 * indirection: the feature is real, but this codebase does not use it once. So
 * the useful rule is the absolute one -- if a genuine need ever arrives, the
 * failing assertion is the review conversation.
 *
 * Tokenizing rather than grepping is what makes the rule enforceable. The
 * comment above this class contains the words for the bug but not the token,
 * and the comment in the fixed file DOES spell it literally; a textual scan
 * would either trip on prose or force the explanation out of the code.
 */
final class NoVariableVariablesTest extends TestCase
{
    /**
     * Every PHP file OWA ships, excluding dependencies and build output.
     *
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $roots = ['Core', 'modules', 'tests'];
        $files = [];

        foreach ($roots as $root) {
            $dir = new RecursiveDirectoryIterator(OWA_DIR . $root, FilesystemIterator::SKIP_DOTS);

            foreach (new RecursiveIteratorIterator($dir) as $file) {
                $path = $file->getPathname();

                if (substr($path, -4) !== '.php') {
                    continue;
                }

                // Not ours to police, and node_modules alone is large enough to
                // make this test slow enough that someone would delete it.
                if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    public function testNoSourceFileUsesAVariableVariable(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $tokens = @token_get_all((string) file_get_contents($path));

            foreach ($tokens as $i => $token) {
                // `$$name` tokenizes as the literal string '$' followed by a
                // T_VARIABLE. `${expr}` and `$$` inside a double-quoted string
                // do not produce this pair, which is exactly the precision a
                // regex over the raw bytes cannot offer.
                if ($token !== '$') {
                    continue;
                }

                $next = $tokens[$i + 1] ?? null;

                if (is_array($next) && $next[0] === T_VARIABLE) {
                    $offenders[] = sprintf(
                        '%s:%d  $%s',
                        str_replace(OWA_DIR, '', $path),
                        $next[2],
                        $next[1]
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Variable variables found. This is almost always a typo -- a doubled dollar sign -- "
            . "and it fails at RUNTIME on the one line nobody exercises:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The guard is only worth having if it can still fail.
     */
    public function testTheScanDetectsAVariableVariable(): void
    {
        $tokens    = token_get_all('<?php $visitors = $$rs->aggregates->visitors;');
        $found     = false;

        foreach ($tokens as $i => $token) {
            if ($token === '$' && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_VARIABLE) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'the scan must recognise the exact shape of the defect it exists to prevent');
    }

    /**
     * And only worth having if it does not fire on prose or on strings, since
     * the file it was written for explains the bug in a comment.
     */
    public function testTheScanIgnoresCommentsAndStrings(): void
    {
        $benign = <<<'PHP'
        <?php
        // `$$rs` -- a doubled dollar -- was a variable variable.
        /* $$also_here */
        $literal = 'a $$b in a single-quoted string';
        $money   = "costs $$5";
        PHP;

        $offenders = [];

        foreach (token_get_all($benign) as $i => $token) {
            $tokens = token_get_all($benign);

            if ($token === '$' && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_VARIABLE) {
                $offenders[] = $tokens[$i + 1][1];
            }
        }

        $this->assertSame([], $offenders, 'comments and string literals are not code');
    }
}
