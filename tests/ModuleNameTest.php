<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** Declares no NAME of its own: it inherits Sqs's. */
final class ModuleNameTestInheritsSqs extends \OWA\Module\Sqs\Module
{
}

/**
 * A module class declares its runtime name as its own constant, NAME. One
 * without is refused where modules are loaded: CoreAPI::getPresentModules()
 * skips it with a notice and CoreAPI::moduleClassFactory() throws, each naming
 * the class.
 *
 * The test writes a throwaway module without NAME into modules/ under a
 * random name and removes it in tearDown. No database.
 */
final class ModuleNameTest extends TestCase
{
    private string $dir = '';

    private string $class = '';

    protected function setUp(): void
    {
        $seg         = 'Zznoname' . substr(md5(uniqid('owamod', true)), 0, 8);
        $this->dir   = \OWA\Core\Env::modulesDir() . $seg;
        $this->class = 'OWA\\Module\\' . $seg . '\\Module';

        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/Module.php', "<?php\nnamespace OWA\\Module\\{$seg};\n\n"
            . "class Module extends \\OWA\\Core\\Module {\n\n"
            . "    public function __construct() {\n\n        \$this->name = 'zznoname';\n\n        parent::__construct();\n    }\n}\n");
        require_once $this->dir . '/Module.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/Module.php');
        @rmdir($this->dir);
    }

    public function testEachShippedModuleDeclaresItsName(): void
    {
        foreach (['Base' => 'base', 'Domstream' => 'domstream', 'MaxmindGeoip' => 'maxmind_geoip', 'Sqs' => 'sqs'] as $dir => $name) {
            $class = 'OWA\\Module\\' . $dir . '\\Module';

            $this->assertTrue(\OWA\Core\Module::declaresName($class), $class);
            $this->assertSame($name, $class::NAME);
        }

        $this->assertSame('base', \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->name, 'the constructor sets it');
    }

    public function testANameMustBeTheClassesOwn(): void
    {
        $this->assertFalse(\OWA\Core\Module::declaresName($this->class), 'none');
        $this->assertFalse(\OWA\Core\Module::declaresName(ModuleNameTestInheritsSqs::class), 'inherited');
        $this->assertFalse(\OWA\Core\Module::declaresName('OWA\\Module\\Nothing\\Module'), 'no class');
    }

    public function testAModuleWithoutNameIsNotPresent(): void
    {
        $present = \OWA\Core\CoreAPI::getPresentModules();

        $this->assertNotContains(basename($this->dir), $present);
        $this->assertContains('Base', $present);
        $this->assertSame(['base', 'domstream', 'maxmind_geoip', 'sqs'], array_keys(\OWA\Core\VersionInfo::modules()));
    }

    public function testAskingForItNamesTheClass(): void
    {
        try {
            \OWA\Core\CoreAPI::moduleClassFactory(basename($this->dir));
            $this->fail('a module without NAME was built');
        } catch (\Exception $e) {
            $this->assertStringContainsString($this->class, $e->getMessage());
            $this->assertStringContainsString('declares no NAME of its own', $e->getMessage());
        }
    }

    public function testTheMessageNamesTheClass(): void
    {
        $this->assertSame(
            'OWA\\Module\\Acme\\Module is not loaded as a module: it declares no NAME of its own. '
                . 'A module class declares its runtime name, as in const NAME = \'acme\'.',
            \OWA\Core\Module::undeclaredNameProblem('\\OWA\\Module\\Acme\\Module'));
    }
}
