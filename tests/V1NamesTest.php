<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Migration\V1Names;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The 1.14 -> v2 name map: complete, and pointing only at names v2 has.
 */
final class V1NamesTest extends TestCase
{
    private function names(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/v1_names_1_14.json'), true);
    }

    public function testEvery114NameIsMapped(): void
    {
        $names = $this->names();

        $this->assertGreaterThan(30, count($names['metrics']));
        $this->assertGreaterThan(90, count($names['dimensions']));

        foreach ($names['metrics'] as $name) {
            $this->assertTrue(V1Names::metric($name)['known'], "metric $name has no entry");
        }

        foreach ($names['dimensions'] as $name) {
            $this->assertTrue(V1Names::dimension($name)['known'], "dimension $name has no entry");
        }

        $this->assertSame([], array_diff(array_keys(V1Names::METRICS), $names['metrics']), 'no invented metric names');
        $this->assertSame([], array_diff(array_keys(V1Names::DIMENSIONS), $names['dimensions']), 'no invented dimension names');
    }

    public function testEveryTargetIsAV2Name(): void
    {
        $metrics = \OWA\Core\CoreAPI::getAllMetrics();
        $dimensions = \OWA\Core\CoreAPI::getAllDimensions();

        foreach (array_filter(V1Names::METRICS) as $from => $to) {
            $this->assertArrayHasKey($to, $metrics, "$from maps to $to, which v2 does not register");
        }

        foreach (array_filter(V1Names::DIMENSIONS) as $from => $to) {
            $this->assertArrayHasKey($to, $dimensions, "$from maps to $to, which v2 does not register");
        }
    }

    public function testNumberedSlotsAreDroppedAndOtherNamesAreNotClaimed(): void
    {
        $this->assertSame(['known' => true, 'to' => null], V1Names::metric('goal7Completions'));
        $this->assertSame(['known' => true, 'to' => null], V1Names::dimension('customVarValue3'));
        $this->assertSame(['known' => false, 'to' => 'sessions'], V1Names::metric('sessions'));
    }

    public function testMergedDimensionValuesTranslate(): void
    {
        $this->assertSame('New', V1Names::VALUES['isNewVisitor']['1']);
        $this->assertSame('Returning', V1Names::VALUES['isRepeatVisitor']['1']);
    }
}
