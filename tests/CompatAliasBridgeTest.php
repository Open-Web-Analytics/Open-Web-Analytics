<?php

use PHPUnit\Framework\TestCase;

/**
 * Backward-compat alias bridge test — proves the Phase-6 stage-1 linchpin
 * (owa_compat_aliases.php) actually resolves a migrated class by its legacy
 * owa_* name.
 *
 * The production bridge's map (owa_compat_class_map()) is deliberately EMPTY at
 * stage 1, so it cannot yet be exercised against a real rename. This test
 * instead proves the MECHANISM the bridge relies on end-to-end, against a
 * throwaway fixture that mimics a migrated class:
 *   - a PSR-4-style namespaced class loadable on demand,
 *   - a lazy autoloader identical in shape to the production one,
 *   - resolution of the LEGACY name through class_alias.
 *
 * When the production bridge is wired (owa_env.php requires it on boot) the
 * real map simply supplies real old->new pairs to this exact code path. Two
 * further guarantees are asserted here that the bridge's contract depends on:
 *   (1) instanceof works in BOTH directions across the alias, and
 *   (2) the bridge is a strict no-op for any non-owa_ / unmapped name.
 */
final class CompatAliasBridgeTest extends TestCase
{
    /** @var callable */
    private static $loader;
    private static string $fixtureDir;

    public static function setUpBeforeClass(): void
    {
        // A namespaced fixture class standing in for a migrated OWA class,
        // written to a temp PSR-4 root and loaded by our fixture autoloader.
        self::$fixtureDir = sys_get_temp_dir() . '/owa_compat_fixture_' . getmypid();
        @mkdir(self::$fixtureDir . '/OWA/Fixture', 0777, true);
        file_put_contents(
            self::$fixtureDir . '/OWA/Fixture/Widget.php',
            "<?php\nnamespace OWA\\Fixture;\nclass Widget { public \$module; public function __construct(\$args = []) {} }\n"
        );

        // (1) PSR-4 loader for the new namespaced fixture name.
        spl_autoload_register(function (string $class): void {
            $prefix = 'OWA\\Fixture\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $rel  = substr($class, strlen($prefix));
            $path = self::$fixtureDir . '/OWA/Fixture/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });

        // (2) The lazy alias autoloader — IDENTICAL in shape to the one in
        // owa_compat_aliases.php, but reading a fixture map. This is the code
        // path under test.
        self::$loader = function (string $class): void {
            if (strncmp($class, 'owa_', 4) !== 0) {
                return;
            }
            $map = ['owa_fixtureWidget' => 'OWA\\Fixture\\Widget'];
            if (!isset($map[$class])) {
                return;
            }
            $new = $map[$class];
            if (class_exists($new) && !class_exists($class, false)) {
                class_alias($new, $class);
            }
        };
        spl_autoload_register(self::$loader);
    }

    public static function tearDownAfterClass(): void
    {
        spl_autoload_unregister(self::$loader);
        @unlink(self::$fixtureDir . '/OWA/Fixture/Widget.php');
        @rmdir(self::$fixtureDir . '/OWA/Fixture');
        @rmdir(self::$fixtureDir . '/OWA');
        @rmdir(self::$fixtureDir);
    }

    public function testLegacyNameResolvesToMigratedClass(): void
    {
        // A factory synthesizes the legacy name 'owa_' . $file and checks it
        // with autoload enabled — exactly the production seam.
        $legacy = 'owa_' . 'fixtureWidget';

        $this->assertTrue(
            class_exists($legacy),
            'Legacy name did not resolve through the lazy alias autoloader.'
        );

        $obj = new $legacy([]);
        $this->assertInstanceOf('OWA\\Fixture\\Widget', $obj, 'New-name instanceof failed.');
        $this->assertInstanceOf($legacy, $obj, 'Legacy-name instanceof failed.');
    }

    public function testBridgeIsNoOpForUnmappedAndNonOwaNames(): void
    {
        // An unmapped owa_* name is left to the other loaders (returns false
        // here because no such class/file exists), and a non-owa_ name is never
        // touched. Neither should throw or alias anything.
        $this->assertFalse(class_exists('owa_thisIsNotMappedAnywhere', true));
        $this->assertFalse(class_exists('SomeVendor\\Thing', true));
    }

    /**
     * The real production bridge is loaded on boot and its map is a well-formed
     * (possibly empty) array — a structural guard so a malformed map is caught
     * even while the map has no entries yet.
     */
    public function testProductionBridgeMapIsWellFormed(): void
    {
        require_once dirname(__DIR__) . '/owa_compat_aliases.php';

        $this->assertTrue(
            function_exists('owa_compat_class_map'),
            'owa_compat_aliases.php did not define owa_compat_class_map().'
        );

        $map = owa_compat_class_map();
        $this->assertIsArray($map);
        foreach ($map as $old => $new) {
            $this->assertIsString($old);
            $this->assertIsString($new);
            $this->assertStringStartsWith('owa_', $old, "Map key '$old' is not a legacy owa_ name.");
            $this->assertStringContainsString('\\', $new, "Map value '$new' is not a namespaced name.");
        }
    }
}
