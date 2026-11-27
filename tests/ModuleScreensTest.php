<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Activate and Deactivate on the modules screen (base.moduleActivate,
 * base.moduleDeactivate): a module that is missing, unknown, not active or
 * not installed is refused on the modules screen with the reason, and
 * nothing fatal happens.
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
        if (owa_test_db_available() && array_key_exists('is_active', $this->saved)) {
            \OWA\Core\CoreAPI::persistSetting('maxmind_geoip', 'is_active', $this->saved['is_active']);
            \OWA\Core\CoreAPI::configSingleton()->save();
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
        $step = new \OWA\Module\Base\Controller\ModuleActivate(['module' => 'maxmind_geoip']);
        $step->action();

        $this->assertSame(2501, (int) $step->data['status_code']);
        $this->assertSame('base.optionsModules', $step->data['do']);
        $this->assertContains('maxmind_geoip', \OWA\Core\CoreAPI::getActiveModules());
    }

    public function testDeactivatingAnInactiveModuleIsRefused(): void
    {
        \OWA\Core\CoreAPI::setSetting('maxmind_geoip', 'is_active', false);

        $data = (array) (new \OWA\Module\Base\Controller\ModuleDeactivate(['module' => 'maxmind_geoip']))->action();

        $this->assertSame('Module maxmind_geoip is not active.', self::message($data));
        $this->assertSame('base.optionsModules', $data['subview'] ?? null);
    }

    public function testAnActiveModuleIsDeactivatedThroughCoreApi(): void
    {
        \OWA\Core\CoreAPI::setSetting('maxmind_geoip', 'is_active', true);

        $step = new \OWA\Module\Base\Controller\ModuleDeactivate(['module' => 'MaxmindGeoip']);
        $step->action();

        $this->assertSame(2502, (int) $step->data['status_code'], 'by its directory name as well');
        $this->assertNotContains('maxmind_geoip', \OWA\Core\CoreAPI::getActiveModules());
    }

    public function testTheMessagesAreDeclared(): void
    {
        $c = new \OWA\Module\Base\Controller\OptionsModules([]);

        $this->assertSame('Module %1$s was not activated. %2$s', $c->getMsg(3030)['message']);
        $this->assertSame('There is no module named %s.', $c->getMsg(3031)['message']);
        $this->assertSame('Module %s is not active.', $c->getMsg(3032)['message']);
        $this->assertSame('No module was named.', $c->getMsg(3033)['message']);
    }
}
