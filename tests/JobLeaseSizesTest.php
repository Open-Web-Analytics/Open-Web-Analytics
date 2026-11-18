<?php

require_once(__DIR__ . '/CliControllerTestCase.php');

/**
 * The frequent jobs' leases are near twice their budget, not the six-hour
 * default, so a run that died is taken over within minutes. cube-rebuild
 * renews its scheduler lock and its queue claim wherever it renews its cube
 * lock.
 */
final class JobLeaseSizesTest extends CliControllerTestCase
{
    public function testTheDrainsLeaseIsTwiceItsBudget(): void
    {
        $D = \OWA\Module\Base\Controller\DrainTrackerIngestCli::class;

        $this->assertSame(2 * $D::BUDGET, (new $D([]))->getJobLease());
        $this->assertSame(20, (new $D(['seconds' => '10']))->getJobLease());
    }

    public function testTheCubeRebuildsLeaseCoversTwoOfItsTicks(): void
    {
        $C = \OWA\Module\Base\Controller\CubeRebuildCli::class;

        $this->assertSame(600, (new $C([]))->getJobLease());
        $this->assertSame(600, \OWA\Module\Base\Classes\JobQueue::policyFor('cube-rebuild')['lease_seconds']);
        $this->assertLessThan(\OWA\Core\Controller\Cli::DEFAULT_JOB_LEASE, $C::JOB_LEASE);
    }

    /** After each partition: the cube lock, then the job's own lease. */
    public function testTheBuildHeartbeatsWhereItRefreshesTheCubeLock(): void
    {
        $m    = new ReflectionMethod(\OWA\Module\Base\Controller\CubeRebuildCli::class, 'buildUnderLock');
        $body = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        $this->assertMatchesRegularExpression('/\$lock->refresh\( self::BUILD_LEASE \);\s*\$this->heartbeat\(\);/', $body);
    }

    /** heartbeat() extends a queued job's claim by the command's lease. */
    public function testAHeartbeatExtendsAQueuedClaimToTheCommandsLease(): void
    {
        $lease = new class('1', 300) extends \OWA\Module\Base\Classes\JobQueueLease {
            public $asked = 0;
            public function refresh($seconds) { $this->asked = (int) $seconds; return true; }
        };

        $cli = new \OWA\Module\Base\Controller\CubeRebuildCli([]);
        $cli->setJobLease($lease);

        (new ReflectionMethod($cli, 'heartbeat'))->invoke($cli);

        $this->assertSame(600, $lease->asked);
    }
}
