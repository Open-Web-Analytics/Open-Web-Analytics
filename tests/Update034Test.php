<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update034 stops an installation deriving 32-bit ids before anything after it
 * derives one, and remembers that it did.
 *
 * up() only: down() drops the v2 tables this installation is using.
 */
final class Update034Test extends TestCase
{
    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes settings');
        }
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::persistSetting('base', 'use_32bit_hash', false);
            \OWA\Core\CoreAPI::persistSetting('base', 'use_32bit_hash_before_v2', false);
        }
    }

    public function testAnInstallationStill32BitIsWidenedAndRemembered(): void
    {
        \OWA\Core\CoreAPI::persistSetting('base', 'use_32bit_hash', true);
        $this->assertTrue(\OWA\Core\Lib::useNarrowGuid());

        $this->assertTrue((new \OWA\Module\Base\Update\Update034())->up());

        $this->assertFalse((bool) \OWA\Core\CoreAPI::getSetting('base', 'use_32bit_hash'));
        $this->assertFalse(\OWA\Core\Lib::useNarrowGuid(), 'every later update derives 64-bit ids');
        $this->assertTrue((bool) \OWA\Core\CoreAPI::getSetting('base', 'use_32bit_hash_before_v2'),
            'so down() can put it back');
    }

    public function testAnInstallationAlready64BitIsNotMarked(): void
    {
        $this->assertTrue((new \OWA\Module\Base\Update\Update034())->up());

        $this->assertFalse((bool) \OWA\Core\CoreAPI::getSetting('base', 'use_32bit_hash_before_v2'));
    }

    /**
     * At schema 34 from a 1.x build whose Update034 re-keyed nothing, v2 runs
     * only the updates after 34: Base\Module::update() runs the id step first.
     */
    public function testAnInstallationAt34StillDeriving32BitIdsIsWidenedBeforeTheUpdatesAfterIt(): void
    {
        \OWA\Core\CoreAPI::persistSetting('base', 'use_32bit_hash', true);

        $this->assertTrue(\OWA\Module\Base\Module::widenIdsAt(34));

        $this->assertFalse(\OWA\Core\Lib::useNarrowGuid(), 'the updates after 34 derive 64-bit ids');
        $this->assertTrue((bool) \OWA\Core\CoreAPI::getSetting('base', 'use_32bit_hash_before_v2'),
            'so Update034::down() can put it back');
    }

    /** Below 34, Update034 runs the step itself; above, it has run. */
    public function testOnlySchema34RunsTheStepBeforeTheUpdates(): void
    {
        \OWA\Core\CoreAPI::persistSetting('base', 'use_32bit_hash', true);

        $this->assertTrue(\OWA\Module\Base\Module::widenIdsAt(33));
        $this->assertTrue(\OWA\Module\Base\Module::widenIdsAt(35));

        $this->assertTrue(\OWA\Core\Lib::useNarrowGuid());
        $this->assertFalse((bool) \OWA\Core\CoreAPI::getSetting('base', 'use_32bit_hash_before_v2'));
    }

    /** Base\Module::update() asks for the step before the updates run. */
    public function testTheModuleUpdateRunsTheStepBeforeTheUpdates(): void
    {
        $src = (string) file_get_contents(\OWA\Core\Env::root() . '/modules/Base/Module.php');

        $step = strpos($src, 'self::widenIdsAt( $recorded )');
        $this->assertNotFalse($step);
        $this->assertLessThan(strpos($src, 'parent::update()'), $step);
    }
}
