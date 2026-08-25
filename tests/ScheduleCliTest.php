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
     * The rotate job is registered with EMPTY params.
     *
     * The most valuable assertion in this file. It reddens if anyone later adds
     * keep=24 to the REGISTRATION, which would quietly turn "nothing is deleted
     * by default" into a retention policy that starts deleting data on upgrade.
     * Adding keep in config is the supported path and has its own case below.
     */
    public function testRotateShipsWithNoArgumentsSoNothingIsEverDeleted()
    {
        $jobs = $this->callProtected($this->runner(), 'jobs');

        $this->assertArrayHasKey('rotate-partitions', $jobs);
        $this->assertSame([], $jobs['rotate-partitions']['params'], 'no keep= may be registered in code');
        $this->assertSame('@monthly', $jobs['rotate-partitions']['schedule']);
        $this->assertSame('code', $jobs['rotate-partitions']['source']);
    }

    /** Only that one job ships; everything else is opt-in. */
    public function testTheDefaultJobsAreRegistered()
    {
        $jobs = $this->callProtected($this->runner(), 'jobs');

        // Named exactly, not counted: a job appearing here means every install
        // starts running something on a timer, which is a decision rather than
        // a detail. fetch-notifications joined rotate-partitions when the OWA
        // News panel stopped calling api.github.com during page renders.
        $this->assertSame(['rotate-partitions', 'fetch-notifications'], array_keys($jobs));
    }

    /**
     * No default job may fire at midnight.
     *
     * fetch-notifications calls a THIRD PARTY. `@daily` is `0 0 * * *`, so
     * every install would call it at the same instant, and most servers keep
     * UTC so timezones would not spread it either. Each install derives its own
     * minute and hour from something stable about itself.
     */
    public function testNoJobThatCallsOutIsScheduledAtMidnight()
    {
        $jobs = $this->callProtected($this->runner(), 'jobs');

        $this->assertNotSame('@daily', $jobs['fetch-notifications']['schedule']);
        $this->assertNotSame('0 0 * * *', $jobs['fetch-notifications']['schedule']);
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
        $jobs = $this->jobsWith(['rotate-partitions' => ['params' => ['keep' => 24]]]);

        $this->assertSame(['keep' => 24], $jobs['rotate-partitions']['params']);
        $this->assertSame('@monthly', $jobs['rotate-partitions']['schedule'], 'the shipped schedule survives');
        $this->assertSame('config-override', $jobs['rotate-partitions']['source']);

        $jobs = $this->jobsWith(['rotate-partitions' => ['schedule' => '@daily']]);

        $this->assertSame('@daily', $jobs['rotate-partitions']['schedule']);
        $this->assertSame([], $jobs['rotate-partitions']['params'], 'the shipped params survive');
    }

    /**
     * ...and both keys together, which is the common real request: keep two
     * years of data AND run the job at 4am on the 1st rather than midnight.
     *
     * There is one configuration form, not a short one and a long one. Every key
     * is independently optional on a job that is already registered, and
     * 'command' is what a NEW job additionally has to supply because it has
     * nothing to inherit.
     */
    public function testScheduleAndParamsCanBeOverriddenTogether()
    {
        $jobs = $this->jobsWith(['rotate-partitions' => [
            'schedule' => '0 4 1 * *',
            'params'   => ['keep' => 24],
        ]]);

        $this->assertSame('0 4 1 * *', $jobs['rotate-partitions']['schedule']);
        $this->assertSame(['keep' => 24], $jobs['rotate-partitions']['params']);
        $this->assertSame('partition-rotate', $jobs['rotate-partitions']['command'],
            'the registered command survives: config never has to restate it');
        $this->assertSame('config-override', $jobs['rotate-partitions']['source']);
        $this->assertNotNull(\OWA\Core\Cron::parse('0 4 1 * *'), 'the documented expression must parse');
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
    /**
     * The configuration key is the JOB NAME, never the command name.
     *
     * The shipped job now demonstrates this itself -- rotate-partitions runs
     * partition-rotate -- but this keeps its own fixture so the rule is pinned
     * independently of how the shipped job happens to be registered: the name
     * reaches a job, and the command does not.
     */
    public function testTheConfigKeyIsTheJobNameNotTheCommand()
    {
        $jobs = $this->jobsWith([
            'nightly-cache-flush' => ['command' => 'flush-cache', 'schedule' => '@daily'],

            // Keyed by the COMMAND. There is no job of this name, so this is
            // read as a NEW job -- which needs a schedule, has none, and is
            // refused. What it must not do is reach nightly-cache-flush.
            'flush-cache'         => ['params' => ['reached' => 'the wrong job']],
        ]);

        $this->assertArrayHasKey('nightly-cache-flush', $jobs);
        $this->assertSame([], $jobs['nightly-cache-flush']['params'],
            'an entry keyed by the command name must not modify the job that runs it');

        $this->assertArrayNotHasKey('flush-cache', $jobs,
            'a key that is neither a registered job nor a complete new entry is refused');

        // ...and the name does reach it.
        $jobs = $this->jobsWith([
            'nightly-cache-flush' => [
                'command'  => 'flush-cache',
                'schedule' => '@daily',
                'params'   => ['reached' => 'the right job'],
            ],
        ]);

        $this->assertSame(['reached' => 'the right job'], $jobs['nightly-cache-flush']['params']);
    }

    public function testTheSameCommandCanBeScheduledTwice()
    {
        $jobs = $this->jobsWith([
            'rotate-one-table' => [
                'command'  => 'partition-rotate',
                'params'   => ['table' => 'owa_click'],
                'schedule' => '@weekly',
            ],
        ]);

        $this->assertSame('partition-rotate', $jobs['rotate-partitions']['command']);
        $this->assertSame('partition-rotate', $jobs['rotate-one-table']['command']);
        $this->assertSame([], $jobs['rotate-partitions']['params']);
        $this->assertSame(['table' => 'owa_click'], $jobs['rotate-one-table']['params']);
        $this->assertNotSame($jobs['rotate-partitions']['schedule'], $jobs['rotate-one-table']['schedule']);
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
            $this->assertArrayHasKey('rotate-partitions', $jobs, "$label must not take the shipped job down");
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
        $jobs = $this->jobsWith(['rotate-partitions' => ['schedule' => 'off']]);

        $this->assertArrayHasKey('rotate-partitions', $jobs, 'off is a state, not an absence');
        $this->assertTrue($this->callProtected($this->runner(), 'isDisabled', [$jobs['rotate-partitions']]));
        $this->assertNull($this->callProtected($this->runner(), 'parsedSchedule', [$jobs['rotate-partitions']]));
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
     * Rotate's lease reflects the DATA a run will rewrite, not the partitions
     * that happen to exist and not the number of periods it will create.
     *
     * Both mistakes were made and both were expensive. Counting existing
     * partitions gave 8.3 hours for a rotate with nothing to do. Counting
     * created partitions gave 2.6 hours for an extension measured at 1.52
     * seconds -- because extending the lead is ONE statement however many
     * periods it produces, and its cost follows what is in the catch-all.
     *
     * A test asserting only "at least the floor" passed both.
     */
    public function testRotateLeaseFollowsDataRewrittenNotPartitionCount()
    {
        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $ctrl = new \OWA\Module\Base\Controller\PartitionRotateCli([]);

        $through = \OWA\Core\Db::partitionLeadBoundary();
        $budget  = $this->callProtected($ctrl, 'factTableBudget');

        $statements = 0;
        $rows       = 0;
        $existing   = 0;
        $creating   = 0;

        foreach ($this->callProtected($ctrl, 'factTables') as $table) {

            if (! $db->isPartitioned($table)) {
                continue;
            }

            $granularity = $db->inferPartitionGranularity($table) ?: 'monthly';
            $sizes       = [];
            $catch_all   = 0;

            foreach ($db->listPartitions($table) as $p) {
                $sizes[$p['name']] = (int) $p['rows'];

                if (strtoupper($p['less_than']) === OWA_DTD_PARTITION_MAXVALUE) {
                    $catch_all = (int) $p['rows'];
                }
            }

            $extend = $db->extendPartitions($table, $granularity, $through, true);

            if (! empty($extend['planned'])) {
                $statements++;
                $rows     += $catch_all;
                $creating += (int) $extend['planned'];
            }

            foreach ((array) ($db->planPartitionCompaction($table, $budget['limit'])['merges'] ?? []) as $merge) {
                $statements++;

                foreach ((array) ($merge['names'] ?? []) as $n) {
                    $rows += $sizes[$n] ?? 0;
                }
            }

            $existing += count($db->getPartitionSpans($table));
        }

        $expected = max(1800, ($statements * 120) + (int) ceil($rows / 1000000) * 300);

        $this->assertSame($expected, $ctrl->getJobLease(), 'the lease must follow statements and rows');

        // The two guards that would each have caught a past defect.
        if ($existing > 50 && $statements === 0) {
            $this->assertSame(1800, $ctrl->getJobLease(),
                sprintf('%d existing partitions with nothing planned must give the floor', $existing));
        }

        if ($creating > 10 && $rows < 100000) {
            $this->assertLessThan(3600, $ctrl->getJobLease(),
                sprintf('creating %d periods over %d rows is one cheap statement', $creating, $rows));
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
        $this->assertStringContainsString('rotate-partitions', $out['message'], 'say what the valid names are');
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
            ['rotate-partitions' => []],
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

            // The message is what an operator reads every month on an install
            // that never ran partition-init, so it has to read as a sentence.
            // The first version rendered "no fact table is not partitioned" --
            // the plural branch supplied "no fact table is" into a template
            // that already said "not partitioned".
            $this->assertStringContainsString('that table is not partitioned', $out['message'],
                'the single-table branch names the table it skipped');
            $this->assertDoesNotMatchRegularExpression(
                '/\bis not\b[^.]*\bnot\b/', $out['message'],
                'no double negative in the sentence'
            );

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

        // This case mutates a REAL fact table, which other tests also read, so
        // it asserts its own restore rather than leaving a lossy one to surface
        // later as an unrelated failure somewhere else in the suite. A
        // misattributed failure costs far more to diagnose than the assertion
        // costs to write.
        $this->assertTrue($db->isPartitioned($table), 'the fixture must be repartitioned');
        $this->assertSame(
            count($spans), count($db->getPartitionSpans($table)),
            'the fixture must be restored to the SAME layout, not merely to some layout'
        );
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

    /**
     * The lease arithmetic at sizes this installation does not have.
     *
     * Tested through leaseFor() because the interesting terms are both zero on
     * a maintained install -- so a test that only recomputes the estimate from
     * live state agrees with every wrong version of the formula. Two mutants
     * survived exactly that way before this was split out.
     */
    public function testTheLeaseArithmetic()
    {
        $lease = fn(int $st, int $rows) => \OWA\Module\Base\Controller\PartitionRotateCli::leaseFor($st, $rows);

        // Nothing to do, and a few hundred rows, are both the floor.
        $this->assertSame(1800, $lease(0, 0));
        $this->assertSame(1800, $lease(1, 569), 'one cheap statement is still the floor');
        $this->assertSame(1800, $lease(0, 999999), 'sub-million never reaches the floor');

        // Rows drive it, and drive it smoothly.
        $this->assertSame(3120, $lease(1, 10000000), '10M rows: 120 + 3000');
        $this->assertSame(15120, $lease(1, 50000000), '50M rows: 120 + 15000');
        $this->assertGreaterThan($lease(1, 10000000), $lease(1, 20000000), 'more data means a longer lease');

        // Statements matter, but far less than data -- which is the whole point.
        $this->assertSame(1800, $lease(5, 0), 'five empty merges are still the floor');
        $this->assertLessThan($lease(1, 50000000), $lease(20, 0), 'twenty statements cost less than 50M rows');

        // Never negative, never below the floor.
        $this->assertSame(1800, $lease(0, -1));
        $this->assertSame(1800, $lease(-3, 0));
    }

    /**
     * A real lead gap: one statement creating many periods over a tiny table
     * must NOT be priced per period.
     *
     * This is the case that produced a 2.6-hour lease for 1.52 seconds of work.
     */
    public function testCreatingManyPeriodsOverLittleDataIsCheap()
    {
        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = 'owa_domstream';

        if (! $db->isPartitioned($table)) {
            $this->markTestSkipped("$table is not partitioned.");
        }

        $spans = $db->getPartitionSpans($table);

        if (count($spans) < 2) {
            $this->markTestSkipped("$table has too few partitions to open a gap in.");
        }

        $granularity = $db->inferPartitionGranularity($table) ?: 'monthly';

        // Keep ONE span, so the gap is the whole lead however much history this
        // installation has. Keeping six worked on a table with years behind it
        // and left only seven periods to rebuild on a freshly installed one,
        // where the six covered half of everything there was.
        $keep = array_slice($spans, 0, 1);

        try {
            // Strip the lead, leaving a gap a rotate would have to rebuild.
            $db->query("ALTER TABLE $table REMOVE PARTITIONING");
            $db->partitionTable($table, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                $keep[0]['start'],
                date('Ymd', strtotime(end($keep)['less_than'] . ' -1 day')),
                $granularity
            ));

            $planned = (int) $db->extendPartitions(
                $table, $granularity, \OWA\Core\Db::partitionLeadBoundary(), true
            )['planned'];

            $this->assertGreaterThanOrEqual(10, $planned, 'the fixture should leave real work to do');

            $lease = (new \OWA\Module\Base\Controller\PartitionRotateCli(['table' => $table]))->getJobLease();

            $this->assertSame(
                1800, $lease,
                sprintf('%d periods over a few hundred rows is one cheap statement, not %ds of work', $planned, $lease)
            );

        } finally {
            $db->query("ALTER TABLE $table REMOVE PARTITIONING");
            $db->partitionTable($table, 'yyyymmdd', \OWA\Core\Db::makePartitionRanges(
                $spans[0]['start'],
                date('Ymd', strtotime(end($spans)['less_than'] . ' -1 day')),
                $granularity
            ));
        }

        $this->assertCount(count($spans), $db->getPartitionSpans($table), 'the fixture must be restored');
    }

    // -----------------------------------------------------------------------
    // One cron line, from one place
    // -----------------------------------------------------------------------

    /**
     * The crontab line is emitted on four surfaces -- the status command, the
     * admin banner, the install page and the docblocks -- and an installation
     * shown two different lines for the same install has to guess which is
     * right. SchedulerHealth::cronLine() is the one source; this reddens if
     * anyone hand-rolls another.
     *
     * Two rules, because they fail differently. A doc example cannot call the
     * helper, so it is held to the FORM; runtime code can, so it is held to the
     * helper itself.
     */
    public function testEveryCronLineComesFromTheOneHelper()
    {
        $root  = dirname(__DIR__);
        $files = ['owa-config-dist.php'];

        foreach (['modules', 'Core'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }

        $runtime = [];

        foreach ($files as $rel) {
            foreach (explode("\n", (string) file_get_contents($root . '/' . $rel)) as $n => $line) {

                // Only lines that actually present a crontab entry. Prose that
                // merely mentions the expression is not one.
                if (strpos($line, '* * * * *') === false || strpos($line, 'cmd=schedule-run') === false) {
                    continue;
                }

                $where = $rel . ':' . ($n + 1);

                $this->assertStringContainsString('&& php cli.php cmd=schedule-run', $line,
                    $where . ' presents a crontab line in a different form from '
                    . 'SchedulerHealth::cronLine(). Every surface must show the same line.');

                // A docblock cannot call the helper; runtime code has no excuse.
                $trimmed = ltrim($line);
                if ($trimmed[0] !== '*' && strpos($trimmed, '//') !== 0 && strpos($trimmed, '#') !== 0) {
                    $runtime[] = $where;
                }
            }
        }

        $this->assertCount(1, $runtime,
            'Exactly one place may BUILD the cron line at runtime; found: ' . implode(', ', $runtime));
        $this->assertStringContainsString('SchedulerHealth.php', $runtime[0],
            'The one place that builds it must be SchedulerHealth::cronLine().');
    }

    /**
     * ...and the diagnosis that tells an operator the dispatcher is dead prints
     * that exact line, not an approximation of it. This is the copy-paste the
     * whole feature exists to deliver.
     */
    public function testTheDispatcherDiagnosisPrintsTheCanonicalCronLine()
    {
        $now      = time();
        $long_ago = $now - (40 * 86400);

        // A stale row, not a missing one. With no row at all, dueSlot() returns
        // TODAY's occurrence, so a daily job is at most 24 hours late -- inside
        // its own one-interval grace period, therefore not behind, and diagnose()
        // correctly says nothing. Reaching the never-run branch needs a job that
        // is genuinely overdue.
        $row = [
            'job_name' => 'lonely', 'last_run_slot' => $long_ago, 'last_run_at' => $long_ago,
            'last_finished_at' => $long_ago, 'last_status' => 'ok', 'last_message' => '-',
            'last_success_at' => $long_ago, 'last_failure_at' => 0, 'run_count' => 1,
            'failure_count' => 0,
        ];

        $reason = (string) $this->callProtected($this->statusCli(), 'diagnose', [
            'lonely',
            ['command' => 'flush-cache', 'schedule' => '@daily', 'params' => [], 'source' => 'code'],
            $row, null, \OWA\Core\Cron::parse('@daily'), $now, true, false, false, 0,
        ]);

        $this->assertStringContainsString(
            \OWA\Module\Base\Classes\SchedulerHealth::cronLine(), $reason,
            'The never-run diagnosis must print the same line the banner does.'
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
