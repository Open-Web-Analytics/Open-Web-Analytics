<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** A database that records what partition-reorganize asked of it. */
class ReorganizeRecordingDb
{
    public array $spans = [];
    public array $calls = [];
    public string $granularity = 'monthly';

    public function supportsPartitioning()
    {
        return true;
    }

    public function isPartitioned($table)
    {
        return true;
    }

    public function getPartitionSpans($table)
    {
        return $this->spans;
    }

    public function inferPartitionGranularity($table)
    {
        return $this->granularity;
    }

    public function repartitionTable($table, $granularity, $dry_run = false, $from = null, $to = null, $skip = null)
    {
        $this->calls[] = compact('table', 'granularity', 'dry_run', 'from', 'to', 'skip');

        return ['changed' => [], 'skipped' => 0, 'failed' => [], 'planned' => 1];
    }
}

/** A reorganize that believes every table is the cube, with a database it can see. */
class ReorganizeAsCube extends \OWA\Module\Base\Controller\PartitionReorganizeCli
{
    /** @var ReorganizeRecordingDb|null */
    public static $db = null;

    public static bool $cube = true;

    protected function db()
    {
        return self::$db;
    }

    protected function dailyLeadMonths($table)
    {
        return self::$cube ? \OWA\Core\Db::CUBE_DAILY_MONTHS : 0;
    }

    protected function factTables($only = null)
    {
        return ['owa_event'];
    }

    protected function factTableBudget()
    {
        return ['limit' => 400, 'reason' => 'test'];
    }

    protected function assertPartitioningSupported()
    {
        return true;
    }
}

/**
 * What cmd=partition-reorganize does to the reporting cube.
 *
 * The cube's lead is part daily and cmd=partition-rotate owns that part. Those
 * are ordinary one-day partitions, so left to itself this command merges them
 * away -- rewriting live rows, which the next rotate rewrites again putting
 * them back.
 */
final class CubeReorganizeTest extends TestCase
{
    private function cli(array $params): ReorganizeAsCube
    {
        $class = new ReflectionClass(ReorganizeAsCube::class);
        $cli   = $class->newInstanceWithoutConstructor();

        $p = $class->getProperty('params');
        $p->setAccessible(true);
        $p->setValue($cli, $params);

        return $cli;
    }

    private function span(string $start, string $less_than): array
    {
        return ['name' => 'p' . $start, 'start' => $start, 'less_than' => $less_than];
    }

    private function dailySpans(string $month): array
    {
        $spans = [];
        $day   = $month . '01';
        $end   = date('Ymd', strtotime($month . '01 +1 month'));

        while ($day < $end) {
            $next    = date('Ymd', strtotime($day . ' +1 day'));
            $spans[] = $this->span($day, $next);
            $day     = $next;
        }

        return $spans;
    }

    protected function setUp(): void
    {
        ReorganizeAsCube::$cube = true;
        ReorganizeAsCube::$db   = new ReorganizeRecordingDb();

        ReorganizeAsCube::$db->spans = array_merge(
            $this->dailySpans('202610'),
            $this->dailySpans('202611'),
            [$this->span('20261201', '20270101')],
            [$this->span('20270101', '20270201')]
        );
    }

    public function testTheDailyFrontIsPassedAsASkipRange(): void
    {
        $this->cli(['granularity' => 'quarter-month', 'dry-run' => 1])->action();

        $calls = ReorganizeAsCube::$db->calls;

        $this->assertNotEmpty($calls);
        $this->assertSame(
            ['start' => '20261001', 'less_than' => '20261201'],
            $calls[0]['skip'],
            'the whole daily run, read from the live partition list'
        );
    }

    public function testAnOrdinaryFactTableGetsNoSkipRange(): void
    {
        ReorganizeAsCube::$cube = false;

        $this->cli(['granularity' => 'quarter-month', 'dry-run' => 1])->action();

        $this->assertNull(ReorganizeAsCube::$db->calls[0]['skip'],
            'only the cube has a daily part of its lead to protect');
    }

    /**
     * An explicit range is an operator saying they mean it.
     *
     * Without this there would be no way to take the cube off the daily front
     * at all -- and the protection is a default, not a prohibition.
     */
    public function testAnExplicitRangeOverridesTheProtection(): void
    {
        $this->cli([
            'granularity' => 'monthly',
            'from'        => '20261001',
            'to'          => '20261201',
            'dry-run'     => 1,
        ])->action();

        $call = ReorganizeAsCube::$db->calls[0];

        $this->assertNull($call['skip']);
        $this->assertSame('20261001', $call['from']);
        $this->assertSame('20261201', $call['to']);
    }

    /**
     * daily is refused for the cube.
     *
     * Granularity is never stored -- inferPartitionGranularity() reads the LAST
     * span -- so a wholly daily cube makes the next rotate extend twelve months
     * of lead at daily, some 365 partitions, with no warning.
     */
    public function testDailyIsRefusedForTheCube(): void
    {
        $this->cli(['granularity' => 'daily', 'dry-run' => 1])->action();

        $this->assertSame([], ReorganizeAsCube::$db->calls,
            'nothing is planned, let alone run');
    }

    public function testDailyIsStillAllowedForAnOrdinaryFactTable(): void
    {
        ReorganizeAsCube::$cube = false;

        $this->cli(['granularity' => 'daily', 'dry-run' => 1])->action();

        $this->assertCount(1, ReorganizeAsCube::$db->calls,
            'the refusal is about the cube, not about daily');
    }
}
