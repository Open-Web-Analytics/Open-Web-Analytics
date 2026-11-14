<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The 1.x path and version constants are gone (Core\Env answers instead), and
 * OWA_DATA_DIR, OWA_CACHE_DIR and OWA_MAXMIND_DATA_DIR are owa-config.php's
 * alone: no code defines them, or a value in the config file would be ignored
 * with an "already defined" warning, as 1.x's OWA_DATA_DIR was.
 */
final class RemovedEnvConstantsTest extends TestCase
{
    private const REMOVED = array(
        'OWA_PATH', 'OWA_DIR', 'OWA_BASE_DIR', 'OWA_BASE_CLASSES_DIR', 'OWA_BASE_MODULE_DIR',
        'OWA_BASE_CLASS_DIR', 'OWA_PLUGIN_DIR', 'OWA_CONF_DIR', 'OWA_THEMES_DIR', 'OWA_VERSION',
        'OWA_VENDOR_DIR', 'OWA_MODULES_DIR', 'OWA_TEMPLATE_DIR',
    );

    private const CONFIG_ONLY = array( 'OWA_DATA_DIR', 'OWA_CACHE_DIR', 'OWA_MAXMIND_DATA_DIR' );

    /** @return iterable<string> every PHP file of the tree, tests included */
    private static function files(): iterable
    {
        $root = \OWA\Core\Env::root();

        foreach (glob($root . '/*.php') as $file) {
            if (basename($file) !== 'owa-config.php') {
                yield $file;
            }
        }

        foreach (array('Core', 'modules', 'api', 'conf', 'tests') as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                $path = $file->getPathname();
                if (substr($path, -4) === '.php' && strpos($path, '/node_modules/') === false) {
                    yield $path;
                }
            }
        }
    }

    public function testNoCodeReadsOrDefinesThem(): void
    {
        $found = array();
        $count = 0;

        foreach (self::files() as $file) {
            $count++;
            $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)),
                static fn ($t) => !is_array($t) || !in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)));
            $where = substr($file, strlen(\OWA\Core\Env::root()) + 1);

            foreach ($tokens as $i => $t) {
                if (!is_array($t)) {
                    continue;
                }

                if ($t[0] === T_STRING && in_array($t[1], self::REMOVED, true)) {
                    $found[] = "$where:{$t[2]} reads {$t[1]}";
                }

                // define( 'NAME', ... ) or defined( 'NAME' )
                if ($t[0] === T_STRING && in_array(strtolower($t[1]), array('define', 'defined'), true)
                    && ($tokens[$i + 1] ?? null) === '(' && is_array($tokens[$i + 2] ?? null)
                    && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {

                    $name = trim($tokens[$i + 2][1], '\'"');

                    if (in_array($name, self::REMOVED, true)) {
                        $found[] = "$where:{$t[2]} {$t[1]}s $name";
                    }

                    if (strtolower($t[1]) === 'define' && in_array($name, self::CONFIG_ONLY, true)
                        && strpos($where, 'tests/') !== 0) {
                        $found[] = "$where:{$t[2]} defines $name, which is owa-config.php's";
                    }
                }
            }
        }

        $this->assertGreaterThan(500, $count, 'the scan read too few files to mean anything');
        $this->assertSame(array(), $found);
    }

    public function testNoneIsDefinedAfterBoot(): void
    {
        foreach (self::REMOVED as $name) {
            $this->assertFalse(defined($name), "$name is defined");
        }
    }
}
