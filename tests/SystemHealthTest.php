<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\JobQueue;
use OWA\Module\Base\Classes\SystemHealth;
use OWA\Module\Base\Classes\TrackerIngest;

/**
 * System Health (PLAN 2.30.5): what Classes\SystemHealth finds, and that the
 * screen shows it. The job queue and the tracker intake are scratch copies
 * of the test's own, so the findings are about state the test made.
 */
final class SystemHealthTest extends TestCase
{
    private const JOBS = 'owa_job_queue_phpunit_health';

    private string $intakeDir = '';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('reads the job queue and the scheduler state');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . self::JOBS);
        $db->query('CREATE TABLE ' . self::JOBS . ' LIKE owa_job_queue');
        JobQueue::$table     = self::JOBS;
        JobQueue::$frozen_at = time();

        $this->intakeDir = sys_get_temp_dir() . '/owa-health-' . bin2hex(random_bytes(4)) . '/';
        TrackerIngest::$queue = new \OWA\Module\Base\Classes\FileEventQueue(['path' => $this->intakeDir]);
    }

    protected function tearDown(): void
    {
        JobQueue::$table     = null;
        JobQueue::$frozen_at = null;
        TrackerIngest::$queue = null;

        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::JOBS);
        }

        exec('rm -rf ' . escapeshellarg($this->intakeDir));
    }

    private static function levels(array $section): array
    {
        return array_column($section['findings'], 'level', 'label');
    }

    public function testAQuietQueueAndIntakeAreGreen(): void
    {
        $this->assertSame('green', SystemHealth::queue()['level']);
        $this->assertSame('green', SystemHealth::intake()['level']);
    }

    /** The plan's counts and age; a job due five minutes and not started means nothing is draining the queue. */
    public function testAJobDueTooLongIsRedAndAFailedOneYellow(): void
    {
        JobQueue::enqueue('partition-status');
        JobQueue::$frozen_at += SystemHealth::QUEUE_DUE_TOO_LONG + 60;

        $queue = SystemHealth::queue();
        $this->assertSame('red', $queue['level']);
        $this->assertSame(1, $queue['stats']['due']);
        $this->assertSame(SystemHealth::QUEUE_DUE_TOO_LONG + 60, $queue['stats']['oldest_due_age']);
        $this->assertSame('red', self::levels($queue)['Not draining']);

        $id = JobQueue::enqueue('jobs-prune');
        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE ' . self::JOBS
            . " SET status = 'failed', last_error = 'it broke' WHERE id = ?", [$id]);

        $queue = SystemHealth::queue();
        $this->assertSame('yellow', self::levels($queue)['Failed jobs']);
        $this->assertSame('it broke', $queue['failed'][0]['last_error'], 'failed jobs are listed with their errors');
    }

    public function testDeadLettersAndABacklogAreReported(): void
    {
        $q = TrackerIngest::$queue;
        $envelope = ['v' => 1, 'type' => 'page_view', 'properties' => [], 'queued_at' => 1];

        $q->send($envelope);
        $q->deadLetter($q->receive(10, 300)[0], 'gave up');

        $intake = SystemHealth::intake();
        $this->assertSame('yellow', self::levels($intake)['Dead letters']);
        $this->assertSame(1, $intake['dead']);

        $q->send($envelope);
        touch($this->intakeDir . 'events.txt', time() - SystemHealth::INTAKE_BACKLOG_AGE - 60);

        $this->assertSame('red', self::levels(SystemHealth::intake())['Backlog']);
    }

    public function testEveryRegisteredJobIsListed(): void
    {
        $jobs = SystemHealth::recurringJobs();

        $this->assertSame(array_keys(\OWA\Module\Base\Classes\JobStatus::jobs()), array_column($jobs['rows'], 'name'));
        $this->assertContains($jobs['level'], ['green', 'yellow', 'red']);
    }

    public function testAWorstLevelIsTheSectionsLevel(): void
    {
        $this->assertSame('red', SystemHealth::worst(['green', 'red', 'yellow']));
        $this->assertSame('yellow', SystemHealth::worst(['green', 'yellow']));
        $this->assertSame('green', SystemHealth::worst([]));
    }

    /** The screen shows every section, the findings' commands, and the failed jobs; a template error would render nothing. */
    public function testTheScreenRendersWhatItFinds(): void
    {
        $id = JobQueue::enqueue('jobs-prune');
        \OWA\Core\CoreAPI::dbSingleton()->query('UPDATE ' . self::JOBS
            . " SET status = 'failed', last_error = '<b>it broke</b>' WHERE id = ?", [$id]);

        $t = new \OWA\Core\Template('base');
        $t->set('sections', SystemHealth::sections());
        $this->assertTrue($t->set_template('system_health.php'));
        $html = (string) $t->fetch();

        foreach (['Scheduler', 'Recurring jobs', 'Job queue', 'Tracker ingest'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        $this->assertStringContainsString('cmd=jobs-retry', $html);
        $this->assertStringContainsString('drain-tracker-ingest', $html, 'the recurring jobs are listed');
        $this->assertStringContainsString('&lt;b&gt;it broke&lt;/b&gt;', $html, 'a job\'s error is escaped');
    }

    public function testThePageIsInTheSettingsNav(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $this->assertSame(\OWA\Module\Base\Controller\SystemHealth::class,
            $s->getMapValue('actions', 'base.systemHealth')['class_name']);
    }
}
