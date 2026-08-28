<?php

use PHPUnit\Framework\TestCase;

/**
 * "Load every class" smoke test — the keystone of the namespace-migration
 * safety net (Phase 6, stage 0).
 *
 * WHY THIS EXISTS
 * ---------------
 * OWA loads its ~340 classes by hand-rolled factories + require_once against
 * an explicit FILE PATH (owa_coreAPI::moduleFactory / owa_lib::factory /
 * moduleRequireOnce). When a class file is missing, mis-named, or its class
 * symbol doesn't match what the factory synthesizes, the failure is SILENT at
 * load time — moduleRequireOnce just debug-logs and returns false — and only
 * surfaces as a runtime "class not found" on whatever report page or CLI
 * command happens to touch that class. The existing unit + e2e suites walk
 * only a fraction of those paths, so a dropped/renamed class can sail through.
 *
 * This test converts that silent failure into a deterministic, pre-flight one:
 * it discovers EVERY OWA-owned class file, requires it, and asserts that every
 * class/interface/trait the file declares actually resolves. During the
 * namespace migration this is the net that catches:
 *   - a multi-class file split that dropped or mis-placed a class,
 *   - a filename that no longer matches its class short-name (PSR-4 breakage),
 *   - a class_alias bridge that wasn't declared for a renamed class.
 *
 * It is intentionally behavior-free: it proves the classes LOAD, not that they
 * behave. Behavior is covered by the ingestion/REST/e2e suites.
 *
 * NOTE: requires a booted framework (the class files reference OWA_* path
 * constants + extend base classes that must be loadable), so it uses the
 * shared full-boot helper. It does NOT touch the database, so it runs even in
 * a no-DB environment.
 */
final class ClassLoadSmokeTest extends TestCase
{
    /** Repo root (this file lives in <root>/tests). */
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__);
        // Full framework boot (defines OWA_* constants, loads base classes).
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * Every discovered class file requires cleanly and every symbol it
     * declares resolves. A single data set (not a dataProvider) so the whole
     * tree is loaded into one process the way OWA actually loads it —
     * require_once is idempotent and later files legitimately depend on
     * earlier ones being present.
     */
    public function testEveryClassFileLoadsAndDeclaresItsClasses(): void
    {
        $files = $this->discoverClassFiles();

        $this->assertGreaterThan(
            300,
            count($files),
            'Expected to discover the full OWA class tree (~336 files); a much '
            . 'smaller number means discovery is mis-scoped.'
        );

        // Pass 1: require every file. A hard failure here is a fatal in the
        // file itself (parse error, bad require path, redeclaration).
        $requireErrors = [];
        foreach ($files as $path => $declared) {
            try {
                require_once $path;
            } catch (\Throwable $e) {
                $requireErrors[] = $this->rel($path) . ': ' . $e->getMessage();
            }
        }
        $this->assertSame(
            [],
            $requireErrors,
            "Class file(s) failed to require:\n" . implode("\n", $requireErrors)
        );

        // Pass 2: every declared symbol must now resolve. This is the check
        // that catches a rename/split that left a class behind.
        $missing = [];
        foreach ($files as $path => $declared) {
            foreach ($declared as $name) {
                if (
                    !class_exists($name, false)
                    && !interface_exists($name, false)
                    && !trait_exists($name, false)
                ) {
                    $missing[] = "$name (declared in " . $this->rel($path) . ')';
                }
            }
        }
        $this->assertSame(
            [],
            $missing,
            "Declared class/interface/trait(s) did not resolve after loading:\n"
            . implode("\n", $missing)
        );
    }

    /**
     * Discover OWA-owned class files: any *.php that declares a class,
     * interface, or trait, excluding vendored/third-party trees, tests, build
     * output, and the front-controller/bootstrap entry points (which are not
     * class files and have side effects on include).
     *
     * @return array<string, string[]> path => declared symbol names
     */
    private function discoverClassFiles(): array
    {
        $skipFragments = [
            '/vendor/',
            '/node_modules/',
            '/tests/',
            '/includes/MaxMind',        // vendored MaxMind reader, already namespaced
            '/public/',                 // build output
            'owa-config',               // install-generated config
        ];
        $skipBasenames = [
            'owa_env.php', 'owa.php', 'index.php', 'install.php',
            'cli.php', 'queue.php', 'log.php', 'blank.php',
        ];

        $files = [];
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $fileInfo) {
            $path = $fileInfo->getPathname();

            if (substr($path, -4) !== '.php') {
                continue;
            }
            if (in_array(basename($path), $skipBasenames, true)) {
                continue;
            }
            foreach ($skipFragments as $frag) {
                if (strpos($path, $frag) !== false) {
                    continue 2;
                }
            }

            $src = file_get_contents($path);
            if (
                !preg_match_all(
                    '/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)/m',
                    $src,
                    $m
                )
            ) {
                continue; // no class/interface/trait declaration
            }

            // A migrated (PSR-4) file declares a namespace; its symbols resolve
            // only by their fully-qualified name. Prepend the file's namespace
            // so the resolution check matches how the class is actually loaded.
            // Legacy global-namespace files have no namespace -> names as-is.
            $ns = '';
            if (preg_match('/^\s*namespace\s+([^;]+);/m', $src, $nm)) {
                $ns = trim($nm[1]) . '\\';
            }

            $files[$path] = array_map(static fn (string $n): string => $ns . $n, $m[1]);
        }

        ksort($files);
        return $files;
    }

    private function rel(string $path): string
    {
        return str_replace(self::$root . '/', '', $path);
    }
}
