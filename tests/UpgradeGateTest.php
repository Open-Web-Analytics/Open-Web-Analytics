<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * 2.0 upgrades from 1.14.0 (schema 33) and nothing older.
 *
 * The version is set in memory only, and restored: the refusal happens before
 * any update is read, so nothing reaches the database.
 */
final class UpgradeGateTest extends TestCase
{
    private $recorded;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('reads the installation\'s settings');
        }

        $this->recorded = \OWA\Core\CoreAPI::getSetting('base', 'schema_version');
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::setSetting('base', 'schema_version', $this->recorded);
        }
    }

    private function base(): \OWA\Module\Base\Module
    {
        return \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');
    }

    public function testTheOldestUpgradableSchemaIs114s(): void
    {
        $this->assertSame(33, \OWA\Module\Base\Module::OLDEST_UPGRADABLE_SCHEMA);
        $this->assertSame([], glob(OWA_DIR . 'modules/Base/Update/Update0{0,1,2}[0-9].php', GLOB_BRACE),
            'v2 carries no update older than 1.14\'s schema');
        $this->assertSame([], glob(OWA_DIR . 'modules/Base/Update/Update03[0-3].php'));
    }

    public function testAnInstallationOlderThan114IsRefused(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'schema_version', 25);

        $this->assertFalse($this->base()->update());
        $this->assertSame(25, (int) \OWA\Core\CoreAPI::getSetting('base', 'schema_version'), 'nothing applied');
    }

    /** Tables with no recorded version were made by something older still. */
    public function testNoRecordedVersionIsRefused(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'schema_version', null);

        $this->assertFalse($this->base()->update());
    }

    /** At 1.14's schema the chain runs; here, already current, it has nothing to apply. */
    public function testACurrentInstallationPasses(): void
    {
        $this->assertTrue($this->base()->update());
    }
}
