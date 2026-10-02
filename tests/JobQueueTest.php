<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\JobQueue;
use OWA\Module\Base\Classes\JobQueueLease;

/**
 * The one-off job queue (PLAN 2.30.5): leases, attempts, back-off, dedupe,
 * the run budget, and the management calls.
 *
 * Against a scratch copy of owa_job_queue (JobQueue::$table), so a test never
 * claims a real job and the development install's scheduler never claims a
 * test's. The queue's clock is stopped (JobQueue::$frozen_at) and moved on,
 * never waited for.
 *
 * The commands are real registered ones because enqueue() refuses any other;
 * the drain's runner here is a closure, so none of them runs.
 */
final class JobQueueTest extends TestCase
{
    private const TABLE = 'owa_job_queue_phpunit';

    /** A registered command that is harmless if it ever did run. */
    private const CMD = 'partition-status';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the queue is a table');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query('CREATE TABLE ' . self::TABLE . ' LIKE owa_job_queue');

        JobQueue::$table     = self::TABLE;
        JobQueue::$frozen_at = time();
    }

    protected function tearDown(): void
    {
        JobQueue::$table     = null;
        JobQueue::$frozen_at = null;

        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    private function row(string $id): array
    {
        return (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = ?', array($id));
    }

    private function set(string $id, array $cols): void
    {
        $sets = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($cols)));

        \OWA\Core\CoreAPI::dbSingleton()->query(
            'UPDATE ' . self::TABLE . " SET $sets WHERE id = ?", array_merge(array_values($cols), array($id)));
    }

    private function later(int $seconds): void
    {
        JobQueue::$frozen_at += $seconds;
    }

    /** Drain with a runner that answers $outcome and records what it was given. */
    private function drainWith(array $outcome, array &$ran = null, int $budget = 60): array
    {
        $ran = array();

        return JobQueue::drain(JobQueue::now() + $budget, function ($command, $params, $job) use ($outcome, &$ran) {
            $ran[] = array($command, $params, (string) $job['id']);

            return $outcome;
        });
    }

    public function testAJobIsQueuedPendingWithItsArgumentsAndTheDefaultPolicy(): void
    {
        $id  = JobQueue::enqueue(self::CMD, array('site' => 'abc'));
        $row = $this->row($id);

        $this->assertSame('pending', $row['status']);
        $this->assertSame(self::CMD, $row['command']);
        $this->assertSame(array('site' => 'abc'), json_decode($row['params'], true));
        $this->assertSame(3, (int) $row['max_attempts']);
        $this->assertSame(300, (int) $row['lease_seconds']);
        $this->assertSame('60,300,900', $row['backoff']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['dedupe_key']);
    }

    public function testAnUnknownCommandIsNotQueued(): void
    {
        $this->assertFalse(JobQueue::enqueue('no-such-command-anywhere'));
        $this->assertSame(0, array_sum(array_filter(JobQueue::stats(), 'is_int')));
    }

    /** Ten saves in a minute leave one job, with the last arguments, due at the soonest. */
    public function testAPendingKeyIsUpdatedNotDuplicated(): void
    {
        $first  = JobQueue::enqueue(self::CMD, array('n' => 1), 'k', 600);
        $second = JobQueue::enqueue(self::CMD, array('n' => 2), 'k', 0);
        $third  = JobQueue::enqueue(self::CMD, array('n' => 3), 'k', 900);

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);

        $row = $this->row($first);
        $this->assertSame(array('n' => 3), json_decode($row['params'], true));
        $this->assertLessThanOrEqual(JobQueue::now(), (int) $row['run_after'], 'a later enqueue never pushes it back');
        $this->assertSame(1, JobQueue::stats()['due']);
    }

    /** Claiming releases the key: a change made while a job runs queues one that sees it. */
    public function testAClaimedJobReleasesItsKey(): void
    {
        $first = JobQueue::enqueue(self::CMD, array(), 'k');

        $this->assertTrue(JobQueue::isQueued(self::CMD, 'k'));

        $claimed = JobQueue::claim();
        $this->assertSame($first, (string) $claimed['id']);
        $this->assertNull($this->row($first)['dedupe_key']);

        $second = JobQueue::enqueue(self::CMD, array(), 'k');

        $this->assertNotSame($first, $second);
        $this->assertSame('pending', $this->row($second)['status']);
    }

    public function testADelayedJobWaitsUntilItIsDue(): void
    {
        $id = JobQueue::enqueue(self::CMD, array(), null, 120);

        $this->assertNull(JobQueue::claim());
        $this->assertSame(1, JobQueue::stats()['delayed']);

        $this->later(121);

        $this->assertSame($id, (string) JobQueue::claim()['id']);
    }

    public function testAClaimIsALeaseAndCountsTheAttempt(): void
    {
        $id  = JobQueue::enqueue(self::CMD);
        $job = JobQueue::claim();
        $row = $this->row($id);

        $this->assertSame('running', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame(1, $job['attempts']);
        $this->assertSame(JobQueue::now() + 300, (int) $row['lease_until']);

        $this->assertNull(JobQueue::claim(), 'a job under lease is not claimed twice');
    }

    /** A runner that died is recovered by the next claim once the lease passes; there is no reaper. */
    public function testAJobWhoseLeaseExpiredIsClaimedAgain(): void
    {
        $id = JobQueue::enqueue(self::CMD);
        JobQueue::claim();

        $this->later(299);
        $this->assertNull(JobQueue::claim(), 'not before the lease ends');

        $this->later(2);
        $again = JobQueue::claim();

        $this->assertSame($id, (string) $again['id']);
        $this->assertSame(2, (int) $this->row($id)['attempts']);
    }

    /** A job that kills its runner every time stops being retried, without being run again. */
    public function testAJobThatNeverRecordsAnOutcomeFailsAfterItsAttempts(): void
    {
        $id = JobQueue::enqueue(self::CMD);
        $this->set($id, array('last_error' => 'the previous try said this'));

        for ($i = 0; $i < 3; $i++) {
            $this->assertNotNull(JobQueue::claim());
            $this->later(301);
        }

        $counts = $this->drainWith(array('outcome' => 'ok'), $ran);

        $this->assertSame(array(), $ran, 'the fourth claim does not run it');
        $this->assertSame(1, $counts['exhausted']);

        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('Did not finish in 3 attempts', $row['last_error']);
        $this->assertStringContainsString('the previous try said this', $row['last_error']);
    }

    public function testOkAndRefusedAreBothDone(): void
    {
        $ok      = JobQueue::enqueue(self::CMD, array('a' => 1));
        $refused = JobQueue::enqueue(self::CMD, array('a' => 2));

        $ran    = array();
        $counts = JobQueue::drain(JobQueue::now() + 600, function ($c, $params) use (&$ran) {
            $ran[] = $params['a'];

            return array('outcome' => $params['a'] === 1 ? 'ok' : 'refused');
        });

        $this->assertSame(2, $counts['done']);
        $this->assertEqualsCanonicalizing(array(1, 2), $ran);
        $this->assertSame('done', $this->row($ok)['status']);
        $this->assertSame('done', $this->row($refused)['status']);
        $this->assertNotNull($this->row($ok)['finished_at']);
    }

    /** A retry keeps its row and waits each back-off step; the last attempt fails it for good. */
    public function testAFailedRunIsRetriedInPlaceAfterEachBackOffStep(): void
    {
        $id = JobQueue::enqueue(self::CMD);

        $counts = $this->drainWith(array('outcome' => 'failed', 'message' => 'first'));
        $row    = $this->row($id);

        $this->assertSame(1, $counts['retried']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('first', $row['last_error']);
        $this->assertSame(JobQueue::now() + 60, (int) $row['run_after']);
        $this->assertNull($row['lease_until']);

        $this->later(60);
        $this->drainWith(array('outcome' => 'failed', 'message' => 'second'));
        $this->assertSame(JobQueue::now() + 300, (int) $this->row($id)['run_after']);

        $this->later(300);
        $counts = $this->drainWith(array('outcome' => 'failed', 'message' => 'third'));
        $row    = $this->row($id);

        $this->assertSame(1, $counts['failed']);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('third', $row['last_error']);
        $this->assertSame(3, (int) $row['attempts']);
    }

    /** More attempts than back-off steps reuse the last step. */
    public function testTheLastBackOffStepIsReused(): void
    {
        $id = JobQueue::enqueue(self::CMD);
        $this->set($id, array('max_attempts' => 5, 'backoff' => '10,20'));

        foreach (array(10, 20, 20) as $expected) {
            $this->drainWith(array('outcome' => 'failed'));
            $this->assertSame(JobQueue::now() + $expected, (int) $this->row($id)['run_after']);
            $this->later($expected);
        }
    }

    public function testARunnerThatThrowsIsAFailedRun(): void
    {
        $id = JobQueue::enqueue(self::CMD);

        $counts = JobQueue::drain(JobQueue::now() + 60, function () {
            throw new \RuntimeException('it broke');
        });

        $this->assertSame(1, $counts['retried']);
        $this->assertSame('RuntimeException: it broke', $this->row($id)['last_error']);
    }

    public function testArgumentsThatAreNotJsonFailWithoutRunning(): void
    {
        $id = JobQueue::enqueue(self::CMD);
        $this->set($id, array('params' => '{not json'));

        $counts = $this->drainWith(array('outcome' => 'ok'), $ran);

        $this->assertSame(array(), $ran);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame('failed', $this->row($id)['status']);
    }

    /** STRICT mode refuses an over-long value, so the error is cut to fit. */
    public function testALongErrorIsCutToTheColumn(): void
    {
        $id = JobQueue::enqueue(self::CMD);

        $this->drainWith(array('outcome' => 'failed', 'message' => str_repeat('é', 5000)));

        $this->assertSame(JobQueue::ERROR_LENGTH, mb_strlen($this->row($id)['last_error']));
    }

    /**
     * A job is claimed only when the rest of the run covers its lease -- except
     * the first, so a job longer than the whole budget still runs.
     */
    public function testOnlyAJobWhoseLeaseFitsTheRestOfTheBudgetIsClaimedAfterTheFirst(): void
    {
        $long  = JobQueue::enqueue(self::CMD, array('n' => 'long'));
        $short = JobQueue::enqueue(self::CMD, array('n' => 'short'));
        $big   = JobQueue::enqueue(self::CMD, array('n' => 'big'));

        $this->set($long, array('lease_seconds' => 1000, 'run_after' => JobQueue::now() - 3));
        $this->set($short, array('lease_seconds' => 30, 'run_after' => JobQueue::now() - 2));
        $this->set($big, array('lease_seconds' => 100, 'run_after' => JobQueue::now() - 1));

        $this->drainWith(array('outcome' => 'ok'), $ran, 60);

        $this->assertSame(array('long', 'short'), array_map(fn ($r) => $r[1]['n'], $ran));
        $this->assertSame('pending', $this->row($big)['status'], 'left for a run with room for it');
    }

    public function testDrainingStopsAtTheDeadline(): void
    {
        JobQueue::enqueue(self::CMD);

        $counts = JobQueue::drain(JobQueue::now(), function () {
            return array('outcome' => 'ok');
        });

        $this->assertSame(0, array_sum($counts));
        $this->assertSame(1, JobQueue::stats()['due']);
    }

    /** Two overlapping ticks take different jobs: the row one holds is skipped, not waited on. */
    public function testAJobLockedByAnotherConnectionIsSkipped(): void
    {
        $a = JobQueue::enqueue(self::CMD, array('n' => 'a'));
        $b = JobQueue::enqueue(self::CMD, array('n' => 'b'));
        $this->set($a, array('run_after' => JobQueue::now() - 10));

        $other = mysqli_init();
        if (!@mysqli_real_connect($other, OWA_DB_HOST, OWA_DB_USER, OWA_DB_PASSWORD, OWA_DB_NAME, (int) OWA_DB_PORT)) {
            $this->markTestSkipped('needs a second connection');
        }

        try {
            $other->begin_transaction();
            $other->query(sprintf('SELECT id FROM %s WHERE id = %s FOR UPDATE', self::TABLE, $a));

            $started = microtime(true);
            $claimed = JobQueue::claim();

            $this->assertSame($b, (string) $claimed['id']);
            $this->assertLessThan(5, microtime(true) - $started, 'skipped, not waited for');

        } finally {
            $other->rollback();
            $other->close();
        }

        $this->assertSame($a, (string) JobQueue::claim()['id'], 'and taken once released');
    }

    public function testDeadlocksAreRetriedAndLostConnectionsStop(): void
    {
        $this->assertTrue(JobQueue::isRetryable('Deadlock found when trying to get lock; try restarting transaction (1213)'));
        $this->assertTrue(JobQueue::isRetryable('SQLSTATE[40001]: Serialization failure'));
        $this->assertTrue(JobQueue::isRetryable('Lock wait timeout exceeded; try restarting transaction'));
        $this->assertFalse(JobQueue::isRetryable("Table 'x' doesn't exist (1146)"));

        $this->assertTrue(JobQueue::isLostConnection('MySQL server has gone away (2006)'));
        $this->assertTrue(JobQueue::isLostConnection('Lost connection to MySQL server during query'));
        $this->assertFalse(JobQueue::isLostConnection('Deadlock found (1213)'));
    }

    public function testAFailedJobCanBeRetriedOneOrAll(): void
    {
        $one = JobQueue::enqueue(self::CMD, array('n' => 1));
        $two = JobQueue::enqueue(self::CMD, array('n' => 2));
        $this->set($one, array('status' => 'failed', 'attempts' => 3, 'last_error' => 'x', 'run_after' => 0));
        $this->set($two, array('status' => 'failed', 'attempts' => 3, 'last_error' => 'y', 'run_after' => 0));

        $this->assertSame(1, JobQueue::retry($one));

        $row = $this->row($one);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
        $this->assertSame(JobQueue::now(), (int) $row['run_after']);

        $this->assertSame(0, JobQueue::retry($one), 'only a failed job is retried');
        $this->assertSame(1, JobQueue::retry('all'));
        $this->assertSame('pending', $this->row($two)['status']);
    }

    public function testAJobCanBeForgotten(): void
    {
        $id = JobQueue::enqueue(self::CMD);

        $this->assertTrue(JobQueue::forget($id));
        $this->assertFalse(JobQueue::forget($id));
        $this->assertSame(array(), $this->row($id));
    }

    public function testPruningKeepsDoneAWeekAndFailedAMonth(): void
    {
        $now = JobQueue::now();
        $ids = array();

        foreach (array(
            'old-done'    => array('done', $now - JobQueue::KEEP_DONE - 1),
            'new-done'    => array('done', $now - JobQueue::KEEP_DONE + 60),
            'old-failed'  => array('failed', $now - JobQueue::KEEP_FAILED - 1),
            'new-failed'  => array('failed', $now - JobQueue::KEEP_DONE - 1),
            'old-pending' => array('pending', null),
        ) as $name => [$status, $finished]) {
            $ids[$name] = JobQueue::enqueue(self::CMD, array('n' => $name));
            $this->set($ids[$name], array('status' => $status, 'finished_at' => $finished, 'created_at' => 0));
        }

        $this->assertSame(2, JobQueue::prune());

        $this->assertSame(array(), $this->row($ids['old-done']));
        $this->assertSame(array(), $this->row($ids['old-failed']));
        foreach (array('new-done', 'new-failed', 'old-pending') as $kept) {
            $this->assertNotSame(array(), $this->row($ids[$kept]), $kept);
        }
    }

    public function testStatsCountEachStateAndTheOldestDueAge(): void
    {
        $due     = JobQueue::enqueue(self::CMD, array('n' => 1));
        $delayed = JobQueue::enqueue(self::CMD, array('n' => 2), null, 600);
        $failed  = JobQueue::enqueue(self::CMD, array('n' => 3));
        $this->set($due, array('run_after' => JobQueue::now() - 90));
        $this->set($failed, array('status' => 'failed'));

        $stats = JobQueue::stats();

        $this->assertSame(1, $stats['due']);
        $this->assertSame(1, $stats['delayed']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['running']);
        $this->assertSame(90, $stats['oldest_due_age']);

        $this->assertSame(array((string) $failed), array_map(fn ($r) => (string) $r['id'], JobQueue::listJobs('failed')));
        $this->assertCount(3, JobQueue::listJobs());
    }

    public function testAnEmptyQueueHasNoDueAge(): void
    {
        $this->assertNull(JobQueue::stats()['oldest_due_age']);
        $this->assertFalse(JobQueue::isQueued(self::CMD));
    }

    /** A running job is queued for its command; its key it has released. */
    public function testIsQueuedSeesARunningJobByCommand(): void
    {
        JobQueue::enqueue(self::CMD, array(), 'k');
        JobQueue::claim();

        $this->assertTrue(JobQueue::isQueued(self::CMD));
        $this->assertFalse(JobQueue::isQueued('jobs-prune'));
    }

    /** A job's heartbeat extends its own claim, and only while it is running. */
    public function testTheLeaseHeartbeatExtendsTheClaim(): void
    {
        $id = JobQueue::enqueue(self::CMD);
        JobQueue::claim();

        $this->later(200);
        (new JobQueueLease($id, 300))->refresh(60);
        $this->assertSame(JobQueue::now() + 300, (int) $this->row($id)['lease_until'], 'never shorter than its lease');

        (new JobQueueLease($id, 300))->refresh(900);
        $this->assertSame(JobQueue::now() + 900, (int) $this->row($id)['lease_until']);

        $this->set($id, array('status' => 'done', 'lease_until' => null));
        (new JobQueueLease($id, 300))->refresh(900);
        $this->assertNull($this->row($id)['lease_until'], 'a finished job is not revived');
    }
}
