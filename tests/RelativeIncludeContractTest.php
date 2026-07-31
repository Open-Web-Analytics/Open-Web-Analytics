<?php

use PHPUnit\Framework\TestCase;

/**
 * "No cwd-dependent includes" contract test.
 *
 * WHY THIS EXISTS
 * ---------------
 * A bare relative include -- include_once('owa_env.php') -- is NOT resolved
 * relative to the file doing the including. PHP resolves it against
 * include_path, which on a default install begins with '.', i.e. the CURRENT
 * WORKING DIRECTORY. Only if that fails does PHP fall back to the calling
 * script's own directory.
 *
 * So a bare relative include works in exactly two cases:
 *   1. the target sits beside the including file (the fallback finds it), or
 *   2. the process happens to be running with a cwd that contains the target.
 *
 * Case 2 is a coin flip. Core/Caller.php relied on it: owa_env.php lives one
 * level up, so the fallback looked in Core/ and missed, and the include only
 * worked when something had already chdir'd to the install root. Every other
 * caller -- CLI, cron, WP-CLI, an install nested in a WordPress plugin dir --
 * silently got two warnings per request and no constants. It produced 5,120
 * entries in one Apache error log before anyone noticed, because the failure is
 * a warning rather than a fatal.
 *
 * The same footgun exists elsewhere in the wild: a plugin shipping
 * require_once('vendor/autoload.php') loads a DIFFERENT project's autoloader
 * when run from a directory that has one.
 *
 * This test permits only case 1: a bare relative include is allowed when the
 * target actually exists next to the including file. Anything else must use
 * __DIR__ (or an OWA_*_DIR constant).
 *
 * Maintenance contract: do not add exemptions. Use __DIR__ instead -- it is
 * absolute, unambiguous, and costs nothing.
 */
final class RelativeIncludeContractTest extends TestCase
{
    /** Directories that are not ours to police. */
    private const SKIP = ['/vendor/', '/node_modules/', '/tests/', '/owa-data/', '/public/'];

    public function testNoIncludeDependsOnTheCurrentWorkingDirectory(): void
    {
        $root = dirname(__DIR__);
        $offenders = [];

        foreach ($this->phpFiles($root) as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }

            // Bare relative single-quoted target: include 'foo.php', require_once("bar/baz.php").
            // Anything starting with /, ., $, or containing __DIR__ is already explicit.
            if (! preg_match_all(
                '/\b(?:include|require)(?:_once)?\s*\(\s*[\'"]([^\'"\/][^\'"]*\.(?:php|inc))[\'"]\s*\)/i',
                $src,
                $m
            )) {
                continue;
            }

            foreach ($m[1] as $target) {
                // Allowed only if the target sits beside the including file,
                // which is the one case PHP's fallback resolves reliably.
                if (file_exists(dirname($file) . '/' . $target)) {
                    continue;
                }
                $offenders[] = sprintf(
                    '%s -> %s',
                    ltrim(str_replace($root, '', $file), '/'),
                    $target
                );
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "These includes resolve against the current working directory, not the\n"
            . "including file. They work only by luck and fail silently (a warning,\n"
            . "not a fatal) for any caller with a different cwd -- CLI, cron, WP-CLI,\n"
            . "or an install nested inside another application.\n\n"
            . "Fix by making the path explicit:\n"
            . "    include_once( __DIR__ . '/../owa_env.php' );\n\n"
            . "Offenders:\n  " . implode("\n  ", $offenders) . "\n"
        );
    }

    /** @return iterable<string> */
    private function phpFiles(string $root): iterable
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $path = $f->getPathname();
            foreach (self::SKIP as $skip) {
                if (strpos($path, $skip) !== false) {
                    continue 2;
                }
            }
            yield $path;
        }
    }
}
