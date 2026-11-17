<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Lib::randomId(): the one maker of random ids. Digits only, positive, and no
 * wider than the column it lands in: 63 bits for a signed BIGINT, 32 for an
 * install still on narrow (crc32-width) ids.
 */
final class LibRandomIdTest extends TestCase
{
    public function testAWideIdIsAPositiveBigint(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = \OWA\Core\Lib::randomId();

            $this->assertMatchesRegularExpression('/^[1-9]\d{0,18}$/', $id);
            $this->assertLessThanOrEqual(PHP_INT_MAX, (int) $id);
            $this->assertSame($id, (string) (int) $id, 'no overflow into a float');
        }
    }

    public function testANarrowIdFitsThirtyTwoBits(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = \OWA\Core\Lib::randomId(true);

            $this->assertMatchesRegularExpression('/^[1-9]\d{0,9}$/', $id);
            $this->assertLessThanOrEqual(0xFFFFFFFF, (int) $id);
        }
    }

    public function testIdsDoNotRepeat(): void
    {
        $ids = array();

        for ($i = 0; $i < 1000; $i++) {
            $ids[] = \OWA\Core\Lib::randomId();
        }

        $this->assertCount(1000, array_unique($ids));
    }

    public function testAnEventGuidIsOne(): void
    {
        $event = new \OWA\Module\Base\Classes\Event();

        $this->assertMatchesRegularExpression('/^[1-9]\d{0,18}$/', (string) $event->getGuid());
    }
}
