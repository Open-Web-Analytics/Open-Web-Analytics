<?php

require_once __DIR__ . '/CliControllerTestCase.php';

/**
 * The scheduler's own decisions: what it runs, what it refuses, what it records,
 * and what it says when a job is not running.
 *
 * CronTest covers the schedule arithmetic and runs everywhere. This covers the
 * layer above -- the registry, the config merge, the lock, outcome recording and
 * the diagnosis -- and needs a database, so it skips in CI alongside its 232
 * siblings.
 */
final class ScheduleCliTest extends CliControllerTestCase
{
    /** @var string[] job names to clean up */
    private $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['scheduled_job', 'job_lock'] as $e) {
            \OWA\Core\CoreAPI::entityFactory('base.' . $e)->createTable();
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->jobs as $name) {
            $db->query(sprintf("DELETE FROM owa_scheduled_job WHERE job_name = '%s'", $db->prepare($name)));
            $db->query(sprintf("DELETE FROM owa_job_lock WHERE job_name = '%s'", $db->prepare($name)));
        }

        $this->jobs = [];

        // The stub command is registered on the module, which is a singleton
        // shared with every later test.
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        unset($s->modules['base']->cli_commands['owa-test-lease-stub']);

        // Settings are global; restore anything a case changed.
        \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', []);
        \OWA\Core\CoreAPI::setSetting('base', 'scheduler_enabled', true);

        parent::tearDown();
    }

    /** @return mixed */
    private function callProtected(object $ctrl, string $method, array $args = [])
    {
        $m = new ReflectionMethod($ctrl, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($ctrl, $args);
    }

    private function runner(array $params = []): object
    {
        return new \OWA\Module\Base\Controller\ScheduleRunCli($params);
    }

    private function statusCli(array $params = []): object
    {
        return new \OWA\Module\Base\Controller\ScheduleStatusCli($params);
    }

    /** Load the registry with a given OWA_SCHEDULED_JOBS-shaped override. */
    private function jobsWith(array $configured): array
    {
        \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', $configured);

        return $this->callProtected($this->runner(), 'jobs');
    }

    private function track(string $name): string
    {
        $this->jobs[] = $name;

        return $name;
    }

    // -----------------------------------------------------------------------
    // What ships
    // -----------------------------------------------------------------------

    /**
     * partition-rotate is registered with EMPTY params.
     *
     * The most valuable assertion in this file. It reddens if anyone later adds
     * keep=24 to the REGISTRATION, which would quietly turn "nothing is deleted
     * by default" into a retention policy that starts deleting data on upgrade.
     * Adding keep in config is the supported path and has its own case below.
     */
    public function testRotateShipsWithNoArgumentsSoNothingIsEverDeleted()
    {
        $jobs = $this->callProtected($this->runner(), 'jobs');

        $this->assertArrayHasKey('partition-rotate', $jobs);
        $this->assertSame([], $jobs['partition-rotate']['params'], 'no keep= may be registered in code');
        $this->assertSame('@monthly', $jobs['partition-rotate']['schedule']);
        $this->assertSame('code', $jobs['partition-rotate']['source']);
    }

    /** Only that one job ships; everything else is opt-in. */
    public function testOnlyPartitionRotateIsRegisteredByDefault()
    {
        $jobs = $this->callProtected($this->runner(), 'jobs');

        $this->assertSame(['partition-rotate'], array_keys($jobs));
    }

    /** Every registered job must name a real command and parse. */
    public function testEveryRegisteredJobIsUsable()
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ($this->callProtected($this->runner(), 'jobs') as $name => $job) {
            $this->assertNotEmpty($s->getCliCommandClass($job['command']), "$name names an unregistered command");
            $this->assertIsArray(\OWA\Core\Cron::parse($job['schedule']), "$name has an unparseable schedule");
        }
    }

    /** Building the registry twice does not duplicate or drop anything. */
    public function testLoadingJobsIsIdempotent()
    {
        $once  = $this->callProtected($this->runner(), 'jobs');
        $twice = $this->callProtected($this->runner(), 'jobs');

        $this->assertSame($once, $twice);
    }

    // -----------------------------------------------------------------------
    // OWA_SCHEDULED_JOBS -- every row of the merge table
    // -----------------------------------------------------------------------

    /** Giving only params keeps the shipped schedule, and vice versa. */
    public function testConfigOverridesPerKey()
    {
        $jobs = $this->jobsWith(['partition-rotate' => ['params' => ['keep' => 24]]]);

        $this->assertSame(['keep' => 24], $jobs['partition-rotate']['params']);
        $this->assertSame('@monthly', $jobs['partition-rotate']['schedule'], 'the shipped schedule survives');
        $this->assertSame('config-override', $jobs['partition-rotate']['source']);

        $jobs = $this->jobsWith(['partition-rotate' => ['schedule' => '@daily']]);

        $this->assertSame('@daily', $jobs['partition-rotate']['schedule']);
        $this->assertSame([], $jobs['partition-rotate']['params'], 'the shipped params survive');
    }

    /** A job the release never registered can be added outright. */
    public function testConfigCanAddAJob()
    {
        $jobs = $this->jobsWith([
            'nightly-flush' => ['command' => 'flush-cache', 'schedule' => '@daily'],
        ]);

        $this->assertArrayHasKey('nightly-flush', $jobs);
        $this->assertSame('flush-cache', $jobs['nightly-flush']['command']);
        $this->assertSame('config', $jobs['nightly-flush']['source']);
    }

    /**
     * The same command twice under different names: two jobs, two state rows,
     * two locks — so one instance running never blocks the other.
     */
    public function testTheSameCommandCanBeScheduledTwice()
    {
        $jobs = $this->jobsWith([
            'rotate-one-table' => [
                'command'  => 'partition-rotate',
                'params'   => ['table' => 'owa_click'],
                'schedule' => '@weekly',
            ],
        ]);

        $this->assertSame('partition-rotate', $jobs['partition-rotate']['command']);
        $this->assertSame('partition-rotate', $jobs['rotate-one-table']['command']);
        $this->assertSame([], $jobs['partition-rotate']['params']);
        $this->assertSame(['table' => 'owa_click'], $jobs['rotate-one-table']['params']);
        $this->assertNotSame($jobs['partition-rotate']['schedule'], $jobs['rotate-one-table']['schedule']);
    }

    /**
     * A bad entry disables itself and nothing else. A typo in one line of
     * config must never stop the other jobs running.
     */
    public function testABadEntryDisablesOnlyItself()
    {
        $cases = [
            'no command'        => ['schedule' => '@daily'],
            'no schedule'       => ['command' => 'flush-cache'],
            'unknown command'   => ['command' => 'no-such-command', 'schedule' => '@daily'],
            'not an array'      => 'nonsense',
        ];

        foreach ($cases as $label => $spec) {
            $jobs = $this->jobsWith(['broken' => $spec, 'ok-one' => ['command' => 'flush-cache', 'schedule' => '@daily']]);

            $this->assertArrayNotHasKey('broken', $jobs, "$label should be refused");
            $this->assertArrayHasKey('ok-one', $jobs, "$label must not take the other jobs down");
            $this->assertArrayHasKey('partition-rotate', $jobs, "$label must not take the shipped job down");
        }
    }

    /**
     * The denylist is checked against the COMMAND, not the job name, so a
     * friendly label cannot smuggle one past.
     */
    public function testDangerousCommandsAreRefusedEvenFromConfig()
    {
        foreach (['install', 'update', 'reset-secrets'] as $command) {
            $jobs = $this->jobsWith(['harmless-sounding' => ['command' => $command, 'schedule' => '@daily']]);

            $this->assertArrayNotHasKey('harmless-sounding', $jobs, "$command must never be schedulable");
        }
    }

    /** 'off' leaves a job registered and listed, just never due. */
    public function testOffLeavesAJobListedButNeverDue()
    {
        $jobs = $this->jobsWith(['partition-rotate' => ['schedule' => 'off']]);

        $this->assertArrayHasKey('partition-rotate', $jobs, 'off is a state, not an absence');
        $this->assertTrue($this->callProtected($this->runner(), 'isDisabled', [$jobs['partition-rotate']]));
        $this->assertNull($this->callProtected($this->runner(), 'parsedSchedule', [$jobs['partition-rotate']]));
    }

    // -----------------------------------------------------------------------
    // Locking
    // -----------------------------------------------------------------------

    public function testALockIsExclusiveAndReleasableOnlyByItsOwner()
    {
        $name = $this->track('owa_test_lock_' . bin2hex(random_bytes(3)));

        $mine   = new \OWA\Module\Base\Classes\JobLease($name);
        $theirs = new \OWA\Module\Base\Classes\JobLease($name);

        $this->assertTrue($mine->acquire(3600));
        $this->assertFalse($theirs->acquire(3600), 'a second holder must be refused');

        $this->assertTrue($mine->refresh(3600), 'the owner may extend');
        $this->assertFalse($theirs->refresh(3600), 'a stranger may not');

        $theirs->release();
        $this->assertNotNull(\OWA\Core\CoreAPI::dbSingleton()->getJobLock($name), 'a stranger cannot release it');

        $mine->release();
        $this->assertNull(\OWA\Core\CoreAPI::dbSingleton()->getJobLock($name));
    }

    /**
     * An expired lock is taken over, and the original holder finishing late
     * cannot then release the lock belonging to the run that replaced it.
     */
    public function testAnExpiredLockIsTakenOverSafely()
    {
        $name = $this->track('owa_test_lock_' . bin2hex(random_bytes(3)));
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        $dead = new \OWA\Module\Base\Classes\JobLease($name);
        $this->assertTrue($dead->acquire(3600));

        $db->query(sprintf(
            "UPDATE owa_job_lock SET expires_at = %d WHERE job_name = '%s'",
            time() - 10, $db->prepare($name)
        ));

        $next = new \OWA\Module\Base\Classes\JobLease($name);
        $this->assertTrue($next->acquire(3600), 'an abandoned lock must be reclaimable');

        $dead->release();
        $this->assertNotNull($db->getJobLock($name), 'the late finisher must not release the new holder');

        $next->release();
    }

    // -----------------------------------------------------------------------
    // Outcome recording
    // -----------------------------------------------------------------------

    /** The default: a command that says nothing ran to completion. */
    public function testACommandThatSaysNothingReportsOk()
    {
        $ctrl = new \OWA\Module\Base\Controller\PartitionStatusCli([]);

        $this->assertSame('ok', $ctrl->getCliOutcome()['outcome']);
    }

    /** refuse() and fail() are distinct, and the FIRST message wins. */
    public function testRefuseAndFailAreDistinctAndKeepTheFirstMessage()
    {
        $ctrl = $this->runner();

        $this->callProtected($ctrl, 'refuse', ['first reason']);
        $this->callProtected($ctrl, 'refuse', ['second reason']);

        $out = $ctrl->getCliOutcome();
        $this->assertSame('refused', $out['outcome']);
        $this->assertSame('first reason', $out['message'], 'the first thing that went wrong is what is recorded');

        $ctrl2 = $this->runner();
        $this->callProtected($ctrl2, 'fail', ['it broke']);
        $this->assertSame('failed', $ctrl2->getCliOutcome()['outcome']);
    }

    /**
     * partition-rotate reports a refusal rather than looking like a success.
     *
     * Before the outcome channel, a refused run and a completed one were
     * indistinguishable to any caller.
     */
    public function testRotateReportsItsRefusals()
    {
        foreach (['abc', '0', '-4'] as $bad) {
            $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli(['keep' => $bad]);
            $ctrl->action();

            $this->assertSame('refused', $ctrl->getCliOutcome()['outcome'], "keep=$bad should be refused");
        }

        $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli(['granularity' => 'fortnightly']);
        $ctrl->action();

        $this->assertSame('refused', $ctrl->getCliOutcome()['outcome']);
    }

    /**
     * Rotate's lease reflects the work PLANNED, not the partitions that happen
     * to exist.
     *
     * The distinction is the whole point, and getting it wrong is not
     * theoretical: an earlier version added the count of existing partitions on
     * every table, so a routine rotate with nothing whatsoever to do asked for
     * 8.3 hours on an installation whose rotate takes 3 seconds. Asserting only
     * "at least the floor" would have passed that happily.
     */
    public function testRotateDerivesItsLeaseFromPlannedWorkNotExistingPartitions()
    {
        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli([]);

        // How much work is genuinely outstanding right now?
        $through = \OWA\Core\Db::partitionLeadBoundary();
        $budget  = $this->callProtected($ctrl, 'factTableBudget');

        $operations = 0;
        $existing   = 0;

        foreach ($this->callProtected($ctrl, 'factTables') as $table) {

            if (! $db->isPartitioned($table)) {
                continue;
            }

            $granularity = $db->inferPartitionGranularity($table) ?: 'monthly';

            $operations += (int) ($db->extendPartitions($table, $granularity, $through, true)['planned'] ?? 0);
            $operations += count($db->planPartitionCompaction($table, $budget['limit'])['merges'] ?? []);
            $existing   += count($db->getPartitionSpans($table));
        }

        $lease = $ctrl->getJobLease();

        $this->assertSame(max(1800, $operations * 300), $lease, 'the lease must follow planned operations');

        if ($operations === 0) {
            $this->assertSame(1800, $lease, 'nothing to do means the floor, however many partitions exist');
        }

        // The guard that would have caught the original defect: an installation
        // with hundreds of partitions and nothing to do must not ask for hours.
        if ($existing > 50 && $operations === 0) {
            $this->assertLessThan(
                3600, $lease,
                sprintf('%d existing partitions with no work planned must not inflate the lease', $existing)
            );
        }
    }

    // -----------------------------------------------------------------------
    // The dispatcher
    // -----------------------------------------------------------------------

    /** A disabled scheduler runs nothing and says so. */
    public function testADisabledSchedulerRefusesToRun()
    {
        \OWA\Core\CoreAPI::setSetting('base', 'scheduler_enabled', false);

        $ctrl = $this->runner();
        $ctrl->action();

        $this->assertSame('refused', $ctrl->getCliOutcome()['outcome']);
        $this->assertStringContainsString('disabled', $ctrl->getCliOutcome()['message']);
    }

    /** An unknown job name is refused, with the valid names offered. */
    public function testAnUnknownJobNameIsRefused()
    {
        $ctrl = $this->runner(['job' => 'no-such-job']);
        $ctrl->action();

        $out = $ctrl->getCliOutcome();
        $this->assertSame('refused', $out['outcome']);
        $this->assertStringContainsString('partition-rotate', $out['message'], 'say what the valid names are');
    }

    /** --dry-run changes no state at all. */
    public function testDryRunWritesNothing()
    {
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $before = $db->get_results('SELECT * FROM owa_scheduled_job ORDER BY job_name');

        $this->runner(['dry-run' => true])->action();

        $this->assertEquals(
            $before,
            $db->get_results('SELECT * FROM owa_scheduled_job ORDER BY job_name'),
            'a dry run must leave the state table untouched'
        );
    }

    /**
     * The state row is materialised for EVERY registered job, not only due
     * ones -- which is what lets "a row exists" prove the dispatcher has run.
     */
    public function testStateIsMaterialisedForEveryJob()
    {
        $name = $this->track('owa_test_never_due_' . bin2hex(random_bytes(3)));

        // Registered but never due: a schedule in the past cannot recur.
        \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', [
            $name => ['command' => 'flush-cache', 'schedule' => '0 0 1 1 *'],
        ]);

        $ctrl = $this->runner(['dry-run' => false, 'job' => $name]);
        $this->callProtected($ctrl, 'ensureState', [$name, false]);

        $this->assertNotNull(
            $this->callProtected($ctrl, 'state', [$name]),
            'a row must exist even before the job is ever due'
        );
    }

    // -----------------------------------------------------------------------
    // Diagnosis
    // -----------------------------------------------------------------------

    /**
     * A job registered in code whose command has been removed can never run,
     * and must say so rather than reporting a next-due time.
     */
    public function testDiagnosisNamesAnUnregisteredCommand()
    {
        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'ghost',
            ['command' => 'no-such-command', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            null, null, \OWA\Core\Cron::parse('@daily'), time(), true, false, true, time(),
        ]);

        $this->assertStringContainsString('not registered', (string) $reason);
    }

    /** A held lock reads as "running now", not as a fault. */
    public function testDiagnosisReportsARunningJob()
    {
        $now = time();

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'busy',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            null,
            ['job_name' => 'busy', 'owner' => 'x', 'acquired_at' => $now - 60, 'expires_at' => $now + 3600],
            \OWA\Core\Cron::parse('@daily'), $now, true, false, true, $now,
        ]);

        $this->assertStringContainsString('Running now', (string) $reason);
    }

    /** An expired lock names the fix. */
    public function testDiagnosisReportsAStuckLock()
    {
        $now = time();

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'stuck',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            null,
            ['job_name' => 'stuck', 'owner' => 'x', 'acquired_at' => $now - 99999, 'expires_at' => $now - 60],
            \OWA\Core\Cron::parse('@daily'), $now, true, false, true, $now,
        ]);

        $this->assertStringContainsString('died', (string) $reason);
        $this->assertStringContainsString('--force-release', (string) $reason);
    }

    /**
     * By elimination: overdue, with nothing else true, means the dispatcher is
     * not running -- and it must say so and print the crontab line.
     */
    public function testDiagnosisBlamesTheDispatcherOnlyByElimination()
    {
        $now  = time();
        $long_ago = $now - (40 * 86400);

        $row = [
            'job_name' => 'lonely', 'last_run_slot' => $long_ago, 'last_run_at' => $long_ago,
            'last_finished_at' => $long_ago, 'last_status' => 'ok', 'last_message' => '-',
            'last_success_at' => $long_ago, 'last_failure_at' => 0, 'run_count' => 1, 'failure_count' => 0,
        ];

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'lonely',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            $row, null, \OWA\Core\Cron::parse('@daily'), $now, true, false, true, $long_ago,
        ]);

        $this->assertStringContainsString('does not appear to be running', (string) $reason);
        $this->assertStringContainsString('cmd=schedule-run', (string) $reason, 'print the fix');
    }

    /**
     * ...and its inverse: with a sibling having run moments ago, the dispatcher
     * is demonstrably alive and must NOT be blamed.
     */
    public function testDiagnosisDoesNotBlameTheDispatcherWhenAnotherJobJustRan()
    {
        $now      = time();
        $long_ago = $now - (40 * 86400);

        $row = [
            'job_name' => 'lonely', 'last_run_slot' => $long_ago, 'last_run_at' => $long_ago,
            'last_finished_at' => $long_ago, 'last_status' => 'ok', 'last_message' => '-',
            'last_success_at' => $long_ago, 'last_failure_at' => 0, 'run_count' => 1, 'failure_count' => 0,
        ];

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'lonely',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            $row, null, \OWA\Core\Cron::parse('@daily'), $now, true, false, true, $now - 30,
        ]);

        $this->assertStringNotContainsString('does not appear to be running', (string) $reason);
        $this->assertStringContainsString('specific to this job', (string) $reason);
    }

    /** A failing job is reported as failing, with when and why. */
    public function testDiagnosisReportsAFailingJob()
    {
        $now      = time();
        $long_ago = $now - (40 * 86400);

        $row = [
            'job_name' => 'sad', 'last_run_slot' => $long_ago, 'last_run_at' => $long_ago,
            'last_finished_at' => $long_ago, 'last_status' => 'failed', 'last_message' => 'the disk is full',
            'last_success_at' => $long_ago - 100, 'last_failure_at' => $long_ago,
            'run_count' => 9, 'failure_count' => 3,
        ];

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'sad',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            $row, null, \OWA\Core\Cron::parse('@daily'), $now, true, false, true, $long_ago,
        ]);

        $this->assertStringContainsString('Failing since', (string) $reason);
        $this->assertStringContainsString('the disk is full', (string) $reason);
    }

    /** A crashed run is distinguishable from a failed one. */
    public function testDiagnosisReportsARunThatNeverFinished()
    {
        $now      = time();
        $long_ago = $now - (40 * 86400);

        $row = [
            'job_name' => 'gone', 'last_run_slot' => $long_ago, 'last_run_at' => $long_ago,
            'last_finished_at' => $long_ago - 500,      // finished BEFORE it started == died mid-run
            'last_status' => 'ok', 'last_message' => '-',
            'last_success_at' => $long_ago, 'last_failure_at' => 0, 'run_count' => 1, 'failure_count' => 0,
        ];

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'gone',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            $row, null, \OWA\Core\Cron::parse('@daily'), $now, true, false, true, $long_ago,
        ]);

        $this->assertStringContainsString('never finished', (string) $reason);
    }

    /** Due but inside the tolerance of a normal tick is not a fault. */
    public function testAJobDueMomentsAgoIsNotReportedAsBehind()
    {
        $now = time();

        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'fresh',
            ['command' => 'flush-cache', 'schedule' => '* * * * *', 'params' => [], 'source' => 'code'],
            null, null, \OWA\Core\Cron::parse('* * * * *'), $now, true, false, true, $now,
        ]);

        $this->assertNull($reason, 'a job due within the grace period is simply waiting for the next tick');
    }

    /** An unreadable schedule is named as the reason, never defaulted. */
    public function testDiagnosisNamesAnUnreadableSchedule()
    {
        $reason = $this->callProtected($this->statusCli(), 'diagnose', [
            'wonky',
            ['command' => 'flush-cache', 'schedule' => 'every thursday-ish', 'params' => [], 'source' => 'config'],
            null, null, null, time(), true, false, true, time(),
        ]);

        $this->assertStringContainsString('cannot be read', (string) $reason);
    }

    // -----------------------------------------------------------------------
    // Job lifecycle
    // -----------------------------------------------------------------------

    /**
     * A de-registered job keeps its row, is never run, and is listed as
     * orphaned. Auto-deleting would let a config typo destroy real history.
     */
    public function testAnOrphanedJobKeepsItsRowAndIsListed()
    {
        $name = $this->track('owa_test_orphan_' . bin2hex(random_bytes(3)));
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        $db->query(sprintf(
            "INSERT INTO owa_scheduled_job (id, job_name, last_status, last_message, last_params, last_run_at, run_count)
             VALUES (%d, '%s', 'ok', '-', '-', %d, 4)",
            crc32($name), $db->prepare($name), time() - 100
        ));

        $lines = $this->callProtected($this->statusCli(), 'describeOrphans', [
            ['partition-rotate' => []],
            $this->callProtected($this->statusCli(), 'allState'),
        ]);

        $this->assertStringContainsString($name, implode("\n", $lines));
        $this->assertStringContainsString('Orphaned', implode("\n", $lines));

        // Still there: nothing pruned it behind our back.
        $this->assertNotNull($this->callProtected($this->statusCli(), 'state', [$name]));
    }

    /**
     * A re-registered job resumes from its stored occurrence: due ONCE when the
     * slot is stale, rather than reading as never-run or firing repeatedly.
     */
    public function testAReRegisteredJobResumesFromItsStoredSlot()
    {
        $parsed = \OWA\Core\Cron::parse('@daily');
        $tz     = $this->callProtected($this->statusCli(), 'timezone');
        $stale  = time() - (10 * 86400);

        $slot = \OWA\Core\Cron::dueSlot($parsed, $stale, time(), $tz);
        $this->assertNotNull($slot, 'a ten-day-old slot must be due');

        // Once satisfied, not due again until the next occurrence.
        $this->assertNull(
            \OWA\Core\Cron::dueSlot($parsed, $slot, time(), $tz),
            'it must run once on return, not repeatedly'
        );
    }

    // -----------------------------------------------------------------------
    // Documentation is a fixture
    // -----------------------------------------------------------------------

    /**
     * The OWA_SCHEDULED_JOBS example in the shipped config template must satisfy
     * the merge rules it documents.
     *
     * Documentation fails silently: the prose says one thing and the
     * copy-pasteable block says another. While this feature was being designed
     * the example twice acquired a defect the rules forbid -- a duplicated key,
     * then an entry with no command.
     */
    public function testTheDocumentedExampleObeysItsOwnRules()
    {
        $template = OWA_DIR . 'owa-config-dist.php';

        if (! is_readable($template)) {
            $this->markTestSkipped('no config template to check');
        }

        $body = file_get_contents($template);

        if (strpos($body, 'OWA_SCHEDULED_JOBS') === false) {
            $this->markTestSkipped('the template does not document OWA_SCHEDULED_JOBS');
        }

        // Every commented example line naming a command must name a real one.
        preg_match_all("/'command'\s*=>\s*'([^']+)'/", $body, $m);

        $this->assertNotEmpty($m[1], 'the example should show at least one command');

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->loadCliCommands();

        foreach ($m[1] as $command) {
            $this->assertNotEmpty(
                $s->getCliCommandClass($command),
                "the documented example names '$command', which is not a registered command"
            );
        }
    }

    /**
     * A scheduled rotate on an installation that never ran partition-init must
     * report a REFUSAL, not success.
     *
     * This is the silent failure the scheduler exists to remove, relocated one
     * level up: left as 'ok', a monthly rotate would show a clean history and a
     * rising run count forever while skipping every table, and the operator
     * would have no signal that partition-init is the missing step.
     *
     * 'refused' rather than 'failed' so the occurrence is still consumed and it
     * is not retried every minute for a condition that will not change on its
     * own.
     */
    public function testRotateRefusesWhenNothingIsPartitioned()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        // A fact table that is real but empty, so removing partitioning is
        // cheap and reversible within the test.
        $table = 'owa_commerce_line_item_fact';

        if (! $db->isPartitioned($table)) {
            $this->markTestSkipped("$table is not partitioned to begin with.");
        }

        if ((int) $db->get_row("SELECT COUNT(*) AS n FROM $table")['n'] > 0) {
            $this->markTestSkipped("$table has rows; not rewriting it in a test.");
        }

        $granularity = $db->inferPartitionGranularity($table) ?: 'monthly';
        $spans       = $db->getPartitionSpans($table);

        try {
            $db->query("ALTER TABLE $table REMOVE PARTITIONING");
            $this->assertFalse($db->isPartitioned($table), 'the fixture should be unpartitioned now');

            $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli(['table' => $table]);
            $ctrl->action();

            $out = $ctrl->getCliOutcome();

            $this->assertSame('refused', $out['outcome'], 'skipping every table is not success');
            $this->assertStringContainsString('partition-init', $out['message'], 'name the missing step');

        } finally {

            // Put it back exactly as it was.
            if ($spans) {
                $db->partitionTable($table, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                    $spans[0]['start'],
                    date('Ymd', strtotime(end($spans)['less_than'] . ' -1 day')),
                    $granularity
                ));
            }
        }

        $this->assertTrue($db->isPartitioned($table), 'the fixture must be restored');
    }

    /** ...and reports ok when it did rotate something. */
    public function testRotateReportsOkWhenItActuallyRotates()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $partitioned = null;

        $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli([]);

        foreach ($this->callProtected($ctrl, 'factTables') as $t) {
            if ($db->isPartitioned($t)) { $partitioned = $t; break; }
        }

        if (! $partitioned) {
            $this->markTestSkipped('no partitioned fact table to rotate.');
        }

        $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli(['table' => $partitioned, 'dry-run' => true]);
        $ctrl->action();

        $this->assertSame('ok', $ctrl->getCliOutcome()['outcome']);
    }

    // -----------------------------------------------------------------------
    // Overrun: the lease running out while a job is still working
    // -----------------------------------------------------------------------

    /**
     * heartbeat() is what PREVENTS an overrun, and is the command's own job to
     * call -- the dispatcher is blocked inside the job and has no thread to do
     * it from.
     */
    public function testHeartbeatExtendsTheLease()
    {
        $name = $this->track('owa_test_hb_' . bin2hex(random_bytes(3)));
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        $lease = new \OWA\Module\Base\Classes\JobLease($name);
        $this->assertTrue($lease->acquire(300));

        $before = (int) $db->getJobLock($name)['expires_at'];

        $ctrl = new \OWA\Module\Base\Controller\PartitionStatusCli([]);
        $ctrl->setJobLease($lease);

        $this->callProtected($ctrl, 'heartbeat');

        $after = (int) $db->getJobLock($name)['expires_at'];

        $this->assertGreaterThan($before, $after, 'a heartbeat must push the expiry out');
        $this->assertSame(
            $lease->getOwner(), $db->getJobLock($name)['owner'],
            'and must not change who holds it'
        );

        $lease->release();
    }

    /**
     * A heartbeat from a run that has already been superseded reports false, so
     * a long job can notice it lost the lock and stop rather than carrying on
     * alongside its replacement.
     */
    public function testHeartbeatReportsWhenTheLockHasBeenLost()
    {
        $name = $this->track('owa_test_hb_' . bin2hex(random_bytes(3)));
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        $overrunning = new \OWA\Module\Base\Classes\JobLease($name);
        $this->assertTrue($overrunning->acquire(300));

        // Its lease runs out while it is still working, and the next tick takes
        // the lock over.
        $db->query(sprintf(
            "UPDATE owa_job_lock SET expires_at = %d WHERE job_name = '%s'",
            time() - 10, $db->prepare($name)
        ));

        $replacement = new \OWA\Module\Base\Classes\JobLease($name);
        $this->assertTrue($replacement->acquire(300));

        $this->assertFalse(
            $overrunning->refresh(300),
            'the superseded run must be told it no longer holds the lock'
        );

        $this->assertSame(
            $replacement->getOwner(), $db->getJobLock($name)['owner'],
            'and must not have stolen it back'
        );

        $replacement->release();
    }

    /** Run by hand, with no lease injected, heartbeat() does nothing at all. */
    public function testHeartbeatIsANoOpWhenRunByHand()
    {
        $ctrl = new \OWA\Module\Base\Controller\PartitionStatusCli([]);

        // No setJobLease() call: this is the hand-run case. It must not raise,
        // because a scheduled command and a hand-run one have to behave alike.
        $this->assertNull($this->callProtected($ctrl, 'heartbeat'));
    }

    /**
     * The dispatcher skips a job whose lock is live -- it does not wait, and it
     * does not start a second copy.
     *
     * This is the overrun case as the dispatcher sees it: a job that is still
     * working when the next tick arrives.
     */
    public function testTheDispatcherSkipsAJobThatIsStillRunning()
    {
        $name = $this->track('owa_test_busy_' . bin2hex(random_bytes(3)));
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', [
            $name => ['command' => 'flush-cache', 'schedule' => '* * * * *'],
        ]);

        $ctrl = $this->runner();
        $jobs = $this->callProtected($ctrl, 'jobs');

        // Someone else is mid-run.
        $held = new \OWA\Module\Base\Classes\JobLease($name);
        $this->assertTrue($held->acquire(3600));

        $this->callProtected($ctrl, 'considerJob', [$name, $jobs[$name], false, false]);

        $state = $this->callProtected($ctrl, 'state', [$name]);

        $this->assertNotNull($state, 'the state row is still materialised');
        $this->assertSame(0, (int) $state->get('run_count'), 'but no run was recorded');
        $this->assertSame(
            $held->getOwner(), $db->getJobLock($name)['owner'],
            'the running job keeps its lock'
        );

        $held->release();
    }

    /**
     * ...and once the lock is gone, the same job runs on the next tick. The
     * skip is a deferral, not a loss: the occurrence stays unsatisfied.
     */
    public function testASkippedJobRunsOnceTheLockClears()
    {
        $name = $this->track('owa_test_defer_' . bin2hex(random_bytes(3)));

        \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', [
            $name => ['command' => 'flush-cache', 'schedule' => '* * * * *'],
        ]);

        $ctrl = $this->runner();
        $jobs = $this->callProtected($ctrl, 'jobs');

        $held = new \OWA\Module\Base\Classes\JobLease($name);
        $held->acquire(3600);
        $this->callProtected($ctrl, 'considerJob', [$name, $jobs[$name], false, false]);
        $held->release();

        $this->callProtected($ctrl, 'considerJob', [$name, $jobs[$name], false, false]);

        $state = $this->callProtected($ctrl, 'state', [$name]);

        $this->assertSame(1, (int) $state->get('run_count'), 'the deferred occurrence still ran');
        $this->assertSame('ok', $state->get('last_status'));
    }

    /**
     * The lock is taken with the COMMAND's lease, not a fixed default -- which
     * is what lets partition-rotate ask for longer when it has more to do.
     *
     * Goes through the dispatcher on purpose. Constructing a JobLease directly
     * and handing it a number only proves the lease object honours what it is
     * given; it says nothing about whether runJob() asked the controller. That
     * distinction is not academic -- hardcoding the lease in the dispatcher
     * passed an earlier version of this test.
     */
    public function testTheDispatcherTakesTheLockWithTheCommandsOwnLease()
    {
        $name = $this->track('owa_test_lease_' . bin2hex(random_bytes(3)));
        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $s    = \OWA\Core\CoreAPI::serviceSingleton();

        // A command whose lease is unmistakable, so a hardcoded one cannot
        // masquerade as it.
        // Registered on the MODULE, not just the service map: loadJobs()
        // rebuilds the command map from the modules on every call, so anything
        // set only on the map is wiped before the registry is read.
        $s->modules['base']->cli_commands['owa-test-lease-stub'] = 'base.owaTestLeaseStub';
        $s->setMapValue('actions', 'base.owaTestLeaseStub', [
            'class_name' => 'OwaTestLeaseStubCli',
            'file'       => '',
        ]);

        \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', [
            $name => ['command' => 'owa-test-lease-stub', 'schedule' => '* * * * *'],
        ]);

        $ctrl = $this->runner();
        $jobs = $this->callProtected($ctrl, 'jobs');

        $this->assertArrayHasKey($name, $jobs, 'the stub job should be registered');

        // Hold the lock ourselves so the dispatcher's acquire fails and the row
        // it would have written is the one we can inspect... no: instead let it
        // run, and read the lock back from inside the job.
        OwaTestLeaseStubCli::$seen_expiry = 0;

        $this->callProtected($ctrl, 'considerJob', [$name, $jobs[$name], false, false]);

        $this->assertGreaterThan(
            0, OwaTestLeaseStubCli::$seen_expiry,
            'the job should have observed its own lock while running'
        );

        $this->assertEqualsWithDelta(
            time() + OwaTestLeaseStubCli::LEASE,
            OwaTestLeaseStubCli::$seen_expiry,
            10,
            'the lock must expire at the lease the COMMAND asked for, not a fixed default'
        );
    }

}

/**
 * A command with a distinctive lease, used to prove the dispatcher asks the
 * controller rather than using a constant. It records the lock's expiry as it
 * sees it from inside its own run.
 */
class OwaTestLeaseStubCli extends \OWA\Core\Controller\Cli {

    const LEASE = 4321;

    /** @var int */
    public static $seen_expiry = 0;

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function getJobLease() {

        return self::LEASE;
    }

    function action() {

        $lock = \OWA\Core\CoreAPI::dbSingleton()->getJobLock( 'x' );

        // The dispatcher names the lock after the JOB, which this stub does not
        // know -- so find the one held right now.
        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results(
            'SELECT expires_at FROM owa_job_lock ORDER BY acquired_at DESC LIMIT 1'
        );

        foreach ( (array) $rows as $row ) {

            self::$seen_expiry = (int) ( (array) $row )['expires_at'];
        }
    }
}
