<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A module whose stored is_active is true but whose code is gone from
 * modules/: the Hello example module on an install that had it active, after
 * the release that removed it and before Update091 deletes its rows.
 *
 * The loader skips it without an error. getActiveModules() still names it,
 * from the stored row, which is what cmd=instance-info prints.
 *
 * No database: the rows are put in the settings array, as boot leaves them.
 */
final class OrphanModuleSettingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $settings;

    protected function setUp(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();
        $this->settings = (array) $c->config->get('settings');

        $orphan = $this->settings;
        $orphan['hello'] = ['is_active' => true, 'schema_version' => 1];
        $c->config->set('settings', $orphan);
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::configSingleton()->config->set('settings', $this->settings);
    }

    public function testTheCodeIsGone(): void
    {
        $this->assertDirectoryDoesNotExist(\OWA\Core\Env::modulesDir() . 'Hello');
        $this->assertNotContains('Hello', \OWA\Core\CoreAPI::getPresentModules());
    }

    public function testTheLoaderSkipsIt(): void
    {
        $service = new \OWA\Module\Base\Classes\Service();
        $service->_loadModules();

        $this->assertArrayHasKey('base', $service->modules);
        $this->assertArrayNotHasKey('hello', $service->modules);
        $this->assertNotContains('hello', $service->getModulesNeedingUpdates());
    }

    public function testTheStoredRowStillNamesItActive(): void
    {
        $this->assertContains('hello', \OWA\Core\CoreAPI::getActiveModules(),
            'what cmd=instance-info lists; Update091 deletes the rows');
    }

    public function testDeactivatingItNeedsItsClass(): void
    {
        $this->expectExceptionMessage('Module hello has no OWA\\Module\\Hello\\Module class');

        \OWA\Core\CoreAPI::deactivateModule('hello');
    }
}
