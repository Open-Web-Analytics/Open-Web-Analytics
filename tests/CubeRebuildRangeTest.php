<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\CubeRebuildCli;

/**
 * Which dates cmd=cube-rebuild resolves to.
 *
 * Built without the constructor: Core\Controller\Cli exits unless the request
 * mode is cli, and these assert the date arithmetic rather than the dispatch.
 */
final class CubeRebuildRangeTest extends TestCase
{
    private function range(array $params): ?array
    {
        $class = new ReflectionClass(CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $property = $class->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($cli, $params);

        $method = $class->getMethod('resolveRange');
        $method->setAccessible(true);

        return $method->invoke($cli);
    }

    public function testTheDefaultCoversYesterdayAsWellAsToday(): void
    {
        // A session whose last event is in the previous partition, and which
        // closed after that partition's final build, has is_exit = 0 -- right
        // at the time, wrong afterwards. Nothing else would revisit it.
        $range = $this->range([]);

        $this->assertSame((int) date('Ymd', strtotime('-1 day')), $range['from']);
        $this->assertSame((int) date('Ymd'), $range['to']);
    }

    public function testFromWithNoToRunsThroughToNow(): void
    {
        // The shape a rebuild is actually for: re-applying a correction, or
        // picking up what arrived late, both of which reach forward.
        $range = $this->range(['from' => '20260915']);

        $this->assertSame(20260915, $range['from']);
        $this->assertSame((int) date('Ymd'), $range['to']);
    }

    public function testDaysIsTheSameWindowSaidRelatively(): void
    {
        $range = $this->range(['days' => '6']);

        $this->assertSame((int) date('Ymd', strtotime('-5 days')), $range['from']);
        $this->assertSame((int) date('Ymd'), $range['to']);
    }

    public function testAClosedWindowIsHonouredExactly(): void
    {
        $range = $this->range(['from' => '20260901', 'to' => '20260930']);

        $this->assertSame(array('from' => 20260901, 'to' => 20260930), $range);
    }

    public function testAnUnreadableRangeIsRefusedRatherThanGuessed(): void
    {
        $this->assertNull($this->range(['from' => 'yesterday']));
        $this->assertNull($this->range(['from' => '20261301']), 'month 13 is not a date');
        $this->assertNull($this->range(['days' => '0']));
        $this->assertNull($this->range(['days' => 'lots']));
        $this->assertNull($this->range(['from' => '20260930', 'to' => '20260901']),
            'a backwards window is a typo, not an instruction');
    }
}
