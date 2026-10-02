<?php

require_once __DIR__ . '/CliControllerTestCase.php';

use OWA\Module\Base\Classes\JobQueue;

/**
 * schedule-run drains the one-off queue (PLAN 2.30.5): each job through the
 * same controller path as a scheduled job, its outcome recorded on its row.
 *
 * Against a scratch copy of owa_job_queue, so a real tick never takes these
 * jobs. The commands run for real, and are ones that act on that same scratch
 * table or refuse.
 */
final class ScheduleRunDrainTest extends CliControllerTestCase
{
    private const TABLE = 'owa_job_queue_phpunit_drain';

    protected function setUp(): void
    {
        parent::setUp();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query('CREATE TABLE ' . self::TABLE . ' LIKE owa_job_queue');

        JobQueue::$table = self::TABLE;
    }

    protected function tearDown(): void
    {
        JobQueue::$table = null;

        \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);

        parent::tearDown();
    }

    private function jobStatus(string $id): string
    {
        return (string) ((array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT status FROM ' . self::TABLE . ' WHERE id = ?', array($id)))['status'];
    }

    private function drain(): void
    {
        $runner = new \OWA\Module\Base\Controller\ScheduleRunCli(array());
        $m      = new ReflectionMethod($runner, 'drainQueue');
        $m->setAccessible(true);

        ob_start();
        try {
            $m->invoke($runner, time() + 60);
        } finally {
            ob_end_clean();
        }
    }

    public function testAQueuedCommandRunsAndIsDone(): void
    {
        $id = JobQueue::enqueue('jobs-prune');

        $this->drain();

        $this->assertSame('done', $this->jobStatus($id));
    }

    /** A refusal is an answer, not a failure: the job is not retried. */
    public function testARefusedCommandIsDoneNotRetried(): void
    {
        // jobs-forget with no id refuses.
        $id = JobQueue::enqueue('jobs-forget');

        $this->drain();

        $this->assertSame('done', $this->jobStatus($id));
    }

    public function testTheJobsCommandsActOnTheQueue(): void
    {
        $id = JobQueue::enqueue('jobs-prune');
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'UPDATE ' . self::TABLE . " SET status = 'failed' WHERE id = ?", array($id));

        $this->runCli('jobs-retry', array('id' => $id));
        $this->assertSame('pending', $this->jobStatus($id));

        $this->runCli('jobs-forget', array('id' => $id));
        $this->assertSame('', $this->jobStatus($id));
    }

    private function runCli(string $command, array $params): void
    {
        $this->assertNotNull($this->commandClass($command), "$command is registered");

        $class = array(
            'jobs-retry'  => \OWA\Module\Base\Controller\JobsRetryCli::class,
            'jobs-forget' => \OWA\Module\Base\Controller\JobsForgetCli::class,
        )[$command];

        ob_start();
        try {
            (new $class($params))->doAction();
        } finally {
            ob_end_clean();
        }
    }
}
