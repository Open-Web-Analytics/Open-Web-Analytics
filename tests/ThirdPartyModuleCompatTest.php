<?php

use PHPUnit\Framework\TestCase;

/**
 * A module in the pre-PSR-4 layout is not loaded on v2, and says why.
 *
 * 1.x loaded a module from a LOWERCASE directory (modules/mymodule/) whose
 * module.php declared a global `owa_<name>Module`. v2.0 removed that layout
 * (UPGRADING.md): a module is a PascalCase directory with an autoloadable
 * OWA\Module\<Dir>\Module. An old module left in modules/ is skipped with a
 * notice naming it rather than half-loading, and asking for it by name raises
 * an error that names the layout.
 *
 * The test writes a throwaway old-style module into modules/ under a random
 * name and removes it in tearDown. No database.
 */
final class ThirdPartyModuleCompatTest extends TestCase
{
    private static string $modulesDir;

    private string $legacyName;
    private string $legacyDir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
        self::$modulesDir = OWA_DIR . 'modules/';
    }

    protected function setUp(): void
    {
        $this->legacyName = 'legacycompat' . substr(md5(uniqid('owamod', true)), 0, 6);
        $this->legacyDir  = self::$modulesDir . $this->legacyName;

        @mkdir($this->legacyDir, 0777, true);
        file_put_contents($this->legacyDir . '/module.php',
            "<?php\nclass owa_{$this->legacyName}Module extends \\OWA\\Core\\Module {\n"
            . "    function __construct() { \$this->name = '{$this->legacyName}'; parent::__construct(); }\n}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->legacyDir . '/module.php');
        @rmdir($this->legacyDir);
    }

    public function testALowercaseModuleDirectoryIsNotPresent(): void
    {
        $present = \OWA\Core\CoreAPI::getPresentModules();

        $this->assertNotContains($this->legacyName, $present);
        $this->assertContains('Base', $present, 'a PSR-4 module is');
        $this->assertNotContains('index.php', $present, 'only directories');
    }

    public function testTheModuleDirectoryIsAlwaysPascalCase(): void
    {
        $this->assertSame(ucfirst($this->legacyName), \OWA\Core\Lib::moduleDirName($this->legacyName),
            'the lowercase directory on disk is not chosen');
        $this->assertSame('MaxmindGeoip', \OWA\Core\Lib::moduleDirName('maxmind_geoip'));
        $this->assertSame('Base', \OWA\Core\Lib::moduleDirName('Base'), 'idempotent on its own output');
    }

    public function testAskingForTheOldModuleNamesTheLayout(): void
    {
        try {
            \OWA\Core\CoreAPI::moduleClassFactory($this->legacyName);
            $this->fail('an old-layout module loaded');
        } catch (\Exception $e) {
            $this->assertStringContainsString('pre-PSR-4 layout', $e->getMessage());
        }

        $this->assertFalse(class_exists('owa_' . $this->legacyName . 'Module', false), 'module.php was not required');
    }

    public function testOwnModulesLoad(): void
    {
        $this->assertInstanceOf(\OWA\Module\Base\Module::class, \OWA\Core\CoreAPI::moduleClassFactory('base'));
    }
}
