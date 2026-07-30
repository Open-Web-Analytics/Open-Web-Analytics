<?php

use PHPUnit\Framework\TestCase;

/**
 * Backwards-compat guard for PRE-PSR-4 third-party modules (Phase 6).
 *
 * Stage B renamed OWA's own module directories to PascalCase and made
 * owa_coreAPI::moduleClassFactory() derive a PSR-4 class name
 * (OWA\Module\<Seg>\Module). That would have silently broken any third-party
 * module still shipped in the old convention:
 *   - a LOWERCASE directory (modules/mymodule/), and
 *   - a global-namespace registry class `owa_<name>Module` declared in
 *     modules/<dir>/module.php (NOT an autoloadable OWA\Module\...\Module).
 *
 * Two additive seams keep such a module loading through the deprecation window
 * (commit cd6cd9c8), and THIS test locks them so a future change can't quietly
 * re-break external modules:
 *   1. owa_lib::moduleDirName() is filesystem-aware — it resolves to a legacy
 *      lowercase dir when no PascalCase dir exists, so the presence scan +
 *      every path-building factory still find the module.
 *   2. moduleClassFactory() falls back to require(module.php) + instantiate
 *      owa_<name>Module when the PSR-4 class is absent.
 *
 * The test writes a throwaway legacy-style module into modules/ under a random
 * name, exercises the real factory, and removes the fixture in tearDown. It
 * also asserts OWA's own modules still take the PascalCase PSR-4 path
 * unchanged. Needs a booted framework (path constants + owa_module base) but
 * touches NO database, so it runs in a no-DB environment.
 */
final class ThirdPartyModuleCompatTest extends TestCase
{
    private static string $modulesDir;

    /** @var string lowercase runtime name of the throwaway legacy module */
    private string $legacyName;
    /** @var string absolute path of the scratch module dir */
    private string $legacyDir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
        self::$modulesDir = OWA_DIR . 'modules/';
    }

    protected function setUp(): void
    {
        // A lowercase, underscore-free name so moduleDirName()'s PascalCase
        // transform differs from the legacy dir (proves the fallback fired):
        // 'legacyxxxx' -> PascalCase 'Legacyxxxx', which does NOT exist on disk.
        $this->legacyName = 'legacycompat' . substr(md5(uniqid('owamod', true)), 0, 6);
        $this->legacyDir  = self::$modulesDir . $this->legacyName;

        @mkdir($this->legacyDir, 0777, true);
        file_put_contents(
            $this->legacyDir . '/module.php',
            "<?php\n"
            . "// Throwaway PRE-PSR-4 third-party module fixture: global-namespace\n"
            . "// registry class in module.php, lowercase directory.\n"
            . "class owa_{$this->legacyName}Module extends owa_module {\n"
            . "    function __construct() {\n"
            . "        \$this->name = '{$this->legacyName}';\n"
            . "        \$this->display_name = 'Legacy Compat Fixture';\n"
            . "        parent::__construct();\n"
            . "    }\n"
            . "}\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->legacyDir . '/module.php');
        @rmdir($this->legacyDir);
    }

    /**
     * moduleDirName() resolves a legacy lowercase module dir verbatim (because
     * its PascalCase form does not exist on disk), while OWA's own modules —
     * whose PascalCase dirs DO exist — still resolve to PascalCase.
     */
    public function testModuleDirNameResolvesLegacyDirButKeepsPascalForOwnModules(): void
    {
        // Sanity: the scratch dir exists lowercase, its PascalCase form does not.
        $pascal = ucfirst($this->legacyName);
        $this->assertDirectoryExists($this->legacyDir);
        $this->assertDirectoryDoesNotExist(self::$modulesDir . $pascal);

        $this->assertSame($this->legacyName, owa_lib::moduleDirName($this->legacyName),
            'A legacy lowercase module dir must resolve to itself when no PascalCase dir exists.');

        // OWA's own modules are PascalCased on disk and must still translate.
        $this->assertSame('Base', owa_lib::moduleDirName('base'));
        $this->assertSame('MaxmindGeoip', owa_lib::moduleDirName('maxmind_geoip'));
        // Idempotent on its own output (the linchpin for the dual-name factory).
        $this->assertSame('Base', owa_lib::moduleDirName('Base'));
    }

    /**
     * The registry-class factory falls back to require(module.php) +
     * instantiate owa_<name>Module for a legacy module, and the loaded module
     * keeps its lowercase runtime name.
     */
    public function testModuleClassFactoryLoadsLegacyModule(): void
    {
        $m = owa_coreAPI::moduleClassFactory($this->legacyName);

        $this->assertInstanceOf('owa_' . $this->legacyName . 'Module', $m,
            'A pre-PSR-4 module must load via the legacy owa_<name>Module fallback.');
        $this->assertInstanceOf('owa_module', $m,
            'The legacy module must still be an owa_module.');
        $this->assertSame($this->legacyName, $m->name,
            'The module runtime name stays lowercase (DB key / dotted-id / ?do= contract).');
    }

    /**
     * OWA's own base module still loads via the PSR-4 path — the fast branch
     * must be unaffected by the legacy fallback.
     */
    public function testOwnModuleStillLoadsViaPsr4(): void
    {
        $base = owa_coreAPI::moduleClassFactory('base');
        $this->assertInstanceOf('OWA\\Module\\Base\\Module', $base,
            'OWA\'s own modules must resolve through the PSR-4 class, not the legacy fallback.');
    }
}
