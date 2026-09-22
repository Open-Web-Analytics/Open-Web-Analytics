<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\CubeRebuildCli;

/** A build whose answer to "is anything collecting into v2" the test sets. */
class RebuildWithCollection extends CubeRebuildCli
{
    public static bool $collecting = false;

    protected function anySiteCollectsV2()
    {
        return self::$collecting;
    }
}

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

    private function cli(array $params = []): RebuildWithCollection
    {
        $class = new ReflectionClass(RebuildWithCollection::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $p = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, $params);

        return $cli;
    }

    private function outcome(RebuildWithCollection $cli): string
    {
        return (string) $cli->getCliOutcome()['outcome'];
    }

    private function message(RebuildWithCollection $cli): string
    {
        return (string) $cli->getCliOutcome()['message'];
    }

    /**
     * WHAT MAKES THIS SAFE TO REGISTER AS A JOB.
     *
     * v2 collection is off until a site turns it on, so on most installations a
     * build would rebuild an empty partition -- and that is not free: it creates
     * a staging table, strips its partitioning and exchanges a partition, which
     * is DDL on every run. The command has to decline before any of that.
     */
    public function testItRefusesBeforeTouchingTheDatabaseWhenNothingCollects(): void
    {
        RebuildWithCollection::$collecting = false;

        $cli = $this->cli();
        $cli->action();

        $this->assertSame('refused', $this->outcome($cli));

        // Named, not just counted: there are three other things this command
        // refuses for, and any of them would satisfy the outcome alone.
        $this->assertStringContainsString('v2 collection', $this->message($cli),
            'it must decline for THIS reason, before any database work');
    }

    /**
     * REFUSED, not FAILED: the scheduler counts a refusal as satisfying the
     * occurrence, so the job goes quiet rather than retrying every slot forever.
     */
    public function testTheRefusalSatisfiesTheOccurrenceRatherThanRetrying(): void
    {
        RebuildWithCollection::$collecting = false;

        $cli = $this->cli();
        $cli->action();

        $this->assertNotSame('failed', $this->outcome($cli),
            'a failed outcome would leave the slot unsatisfied and retry it');
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
