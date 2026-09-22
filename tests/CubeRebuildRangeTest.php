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

    /**
     * Two builds of the same cube cannot run at once.
     *
     * The staging and computed tables are named after the target, so a second
     * build would drop the table the first is filling. The row-count check
     * would usually refuse the resulting swap -- the live table is not at risk
     * -- but the failure reads as builds mysteriously failing.
     *
     * And the two intended cadences OVERLAP by construction: a frequent run
     * over the current partition and an hourly one over the trailing window
     * both cover today. The scheduler's lease is keyed on the JOB NAME, which
     * is exactly what lets them run concurrently, so it cannot be what stops
     * them colliding. This lock is keyed on the TARGET TABLE.
     */
    public function testASecondBuildOfTheSameCubeIsRefused(): void
    {
        // The lock is a row: taking it needs a database, and CI's unit job has
        // none. The rest of this file is date arithmetic and does not.
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; the build lock is a row.');
        }

        $table = owa_coreAPI::entityFactory('base.event')->getTableName();

        $held = new \OWA\Module\Base\Classes\JobLease('cube-build:' . $table);

        $this->assertTrue($held->acquire(600), 'the first build takes the lock');

        try {
            $cli = $this->cli(['--dry-run' => 1]);
            $cli->action();

            $this->assertSame('refused', $cli->getCliOutcome()['outcome']);
            $this->assertStringContainsString('already running',
                (string) $cli->getCliOutcome()['message'],
                'and it says why, rather than failing obscurely');
        } finally {
            $held->release();
        }
    }

    /** With nothing holding it, the same run proceeds. */
    public function testABuildProceedsWhenTheCubeIsNotAlreadyBuilding(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; the build lock is a row.');
        }

        $cli = $this->cli(['--dry-run' => 1]);
        $cli->action();

        $this->assertNotSame('refused', $cli->getCliOutcome()['outcome'],
            'the lock is released after a run, so the next one is not blocked');
    }

    private function cli(array $params): \OWA\Module\Base\Controller\CubeRebuildCli
    {
        $class = new ReflectionClass(CubeRebuildCli::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $p = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, $params);

        return $cli;
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
