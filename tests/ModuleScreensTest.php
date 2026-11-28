<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Activate and Deactivate on the modules screen (base.moduleActivate,
 * base.moduleDeactivate): a module that is missing, unknown, not active or
 * not installed is refused on the modules screen with the reason, and
 * nothing fatal happens. Nothing is installed or stored: the database is
 * the next test file's too, and loads its active modules from it.
 */
final class ModuleScreensTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the modules screen loads the site list');
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);

        $this->saved = ['is_active' => \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'is_active')];
    }

    protected function tearDown(): void
    {
        if (array_key_exists('is_active', $this->saved)) {
            \OWA\Core\CoreAPI::setSetting('maxmind_geoip', 'is_active', $this->saved['is_active']);
        }
    }

    private static function message(array $data): string
    {
        return (string) ($data['error_msg']['message'] ?? '');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unknownModules(): iterable
    {
        yield 'no module'      => [[], 'No module was named.'];
        yield 'an empty one'   => [['module' => ''], 'No module was named.'];
        yield 'an unknown one' => [['module' => 'owatest_no_such_module'], 'There is no module named owatest_no_such_module.'];
        yield 'a path'         => [['module' => '../Base'], 'There is no module named ../Base.'];
    }

    /** @dataProvider unknownModules */
    public function testActivatingAnUnknownModuleIsRefusedOnTheScreen(array $params, string $message): void
    {
        $data = (array) (new \OWA\Module\Base\Controller\ModuleActivate($params))->action();

        $this->assertSame($message, self::message($data));
        $this->assertSame('base.optionsModules', $data['subview'] ?? null, 'the modules screen, drawn');
        $this->assertArrayNotHasKey('status_code', $data, 'not "Module activated."');
    }

    /** @dataProvider unknownModules */
    public function testDeactivatingAnUnknownModuleIsRefusedOnTheScreen(array $params, string $message): void
    {
        $data = (array) (new \OWA\Module\Base\Controller\ModuleDeactivate($params))->action();

        $this->assertSame($message, self::message($data));
        $this->assertSame('base.optionsModules', $data['subview'] ?? null);
    }

    public function testAModuleThatDidNotInstallIsNotActivatedAndSaysWhy(): void
    {
        $step = new class(['module' => 'maxmind_geoip']) extends \OWA\Module\Base\Controller\ModuleActivate {
            protected function install($module, &$problem)
            {
                $problem = 'Its table owa_test could not be created. The error log says why.';

                return false;
            }
        };

        $data = (array) $step->action();

        $this->assertSame('Module maxmind_geoip was not activated. Its table owa_test could not be created. The error log says why.',
            self::message($data));
        $this->assertSame('base.optionsModules', $data['subview'] ?? null);
        $this->assertArrayNotHasKey('status_code', $data);
    }

    public function testWithoutAReasonTheErrorLogIsNamed(): void
    {
        $step = new class(['module' => 'maxmind_geoip']) extends \OWA\Module\Base\Controller\ModuleActivate {
            protected function install($module, &$problem)
            {
                $problem = '';

                return false;
            }
        };

        $this->assertSame('Module maxmind_geoip was not activated. The error log says why.', self::message((array) $step->action()));
    }

    public function testAModuleThatInstallsIsActivated(): void
    {
        $step = new class(['module' => 'maxmind_geoip']) extends \OWA\Module\Base\Controller\ModuleActivate {
            public array $installed = [];

            protected function install($module, &$problem)
            {
                $this->installed[] = $module;
                $problem = '';

                return true;
            }
        };

        $step->action();

        $this->assertSame(['maxmind_geoip'], $step->installed);
        $this->assertSame(2501, (int) $step->data['status_code']);
        $this->assertSame('base.optionsModules', $step->data['do']);
    }

    public function testDeactivatingAnInactiveModuleIsRefused(): void
    {
        \OWA\Core\CoreAPI::setSetting('maxmind_geoip', 'is_active', false);

        $data = (array) (new \OWA\Module\Base\Controller\ModuleDeactivate(['module' => 'maxmind_geoip']))->action();

        $this->assertSame('Module maxmind_geoip is not active.', self::message($data));
        $this->assertSame('base.optionsModules', $data['subview'] ?? null);
    }

    public function testAnActiveModuleIsDeactivated(): void
    {
        \OWA\Core\CoreAPI::setSetting('maxmind_geoip', 'is_active', true);

        $step = new class(['module' => 'MaxmindGeoip']) extends \OWA\Module\Base\Controller\ModuleDeactivate {
            public array $deactivated = [];

            protected function deactivate($module)
            {
                $this->deactivated[] = $module;

                return true;
            }
        };

        $step->action();

        $this->assertSame(['maxmind_geoip'], $step->deactivated, 'by its runtime name, from its directory name');
        $this->assertSame(2502, (int) $step->data['status_code']);
    }

    /** Base is refused on the screen, and nothing is deactivated. */
    public function testDeactivatingBaseIsRefused(): void
    {
        $step = new class(['module' => 'base']) extends \OWA\Module\Base\Controller\ModuleDeactivate {
            public array $deactivated = [];

            protected function deactivate($module)
            {
                $this->deactivated[] = $module;

                return true;
            }
        };

        $data = (array) $step->action();

        $this->assertSame('Module base cannot be deactivated: OWA needs it to run.', self::message($data));
        $this->assertSame('base.optionsModules', $data['subview'] ?? null);
        $this->assertArrayNotHasKey('status_code', $data, 'not "Module deactivated."');
        $this->assertSame([], $step->deactivated);
    }

    /** Module::deactivate() answers false for base, as cmd=deactivate module=base reports it. */
    public function testBaseDoesNotReportItselfDeactivated(): void
    {
        $this->assertFalse(\OWA\Core\CoreAPI::moduleClassFactory('base')->deactivate());
        $this->assertNotSame(false, \OWA\Core\CoreAPI::getSetting('base', 'is_active'));
    }

    /** The screen deactivates as cmd=deactivate does. */
    public function testDeactivatingIsCoreApis(): void
    {
        $this->assertStringContainsString('\\OWA\\Core\\CoreAPI::deactivateModule( $module )',
            (string) file_get_contents(__DIR__ . '/../modules/Base/Controller/ModuleDeactivate.php'));
    }

    public function testTheMessagesAreDeclared(): void
    {
        $c = new \OWA\Module\Base\Controller\OptionsModules([]);

        $this->assertSame('Module %1$s was not activated. %2$s', $c->getMsg(3030)['message']);
        $this->assertSame('There is no module named %s.', $c->getMsg(3031)['message']);
        $this->assertSame('Module %s is not active.', $c->getMsg(3032)['message']);
        $this->assertSame('No module was named.', $c->getMsg(3033)['message']);
        $this->assertSame('Module base cannot be deactivated: OWA needs it to run.', $c->getMsg(3034)['message']);
    }
}
