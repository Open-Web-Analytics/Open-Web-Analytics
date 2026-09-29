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
}
