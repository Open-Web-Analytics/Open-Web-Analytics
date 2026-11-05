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

    /**
     * A pending update is not a scheduler finding: while one is pending every
     * admin screen shows the updater, so this one could never show it.
     * cmd=schedule-status reports it.
     */
    public function testAPendingUpdateIsNotASchedulerFinding(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $c       = \OWA\Core\CoreAPI::configSingleton();
        $flag    = $service->update_required;
        $schema  = $c->get('base', 'schema_version');

        try {
            $service->update_required = true;
            $c->set('base', 'schema_version', 1);

            $this->assertTrue(\OWA\Core\CoreAPI::isSchemaUpdateRequired(), 'the fixture makes a schema update pending');

            $labels = array_keys(self::levels(SystemHealth::scheduler()));

            $this->assertNotContains('Schema updates pending', $labels);
            $this->assertNotContains('Update pending', $labels);
        } finally {
            $service->update_required = $flag;
            $c->set('base', 'schema_version', $schema);
        }
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

        foreach (['Scheduler', 'Recurring jobs', 'Job queue', 'Tracker ingest', 'Tracker bundles', 'Data', 'Installation'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        $this->assertStringContainsString('cmd=jobs-retry', $html);
        $this->assertStringContainsString('drain-tracker-ingest', $html, 'the recurring jobs are listed');
        $this->assertMatchesRegularExpression('#<code title="Ingests the beacons[^"]*">drain-tracker-ingest</code>#', $html,
            'each job\'s description is on its name, from its registration');
        $this->assertStringContainsString('&lt;b&gt;it broke&lt;/b&gt;', $html, 'a job\'s error is escaped');
    }

    /** A fixture build and output directory for the bundle section, as TrackerBundleTest makes them. */
    private function bundleFixture(bool $built = true): array
    {
        $root = sys_get_temp_dir() . '/owa-health-bundles-' . bin2hex(random_bytes(4)) . '/';
        mkdir($root . 'dist', 0700, true);
        mkdir($root . 'tracker', 0700, true);

        if ($built) {
            file_put_contents($root . 'dist/owa.tracker.js', '/*core*/');
            file_put_contents($root . 'dist/owa.domstream.js', '/*chunk*/');
            file_put_contents($root . 'dist/owa.tracker.manifest.json', json_encode([
                'core'    => ['file' => 'owa.tracker.js', 'sha256' => hash('sha256', '/*core*/')],
                'plugins' => ['domstream' => ['file' => 'owa.domstream.js', 'sha256' => hash('sha256', '/*chunk*/')]],
            ]));
        }

        $was = [\OWA\Module\Base\Classes\TrackerBundle::$distDir, \OWA\Module\Base\Classes\TrackerBundle::$outDir,
            \OWA\Module\Base\Classes\TrackerBundle::$buildDir];
        \OWA\Module\Base\Classes\TrackerBundle::$distDir  = $root . 'dist/';
        \OWA\Module\Base\Classes\TrackerBundle::$buildDir = $root . 'dist/';   // the fixture's manifest is beside its files
        \OWA\Module\Base\Classes\TrackerBundle::$outDir  = $root . 'tracker/';

        return [$root, $was];
    }

    private function dropBundleFixture(array $fixture): void
    {
        [\OWA\Module\Base\Classes\TrackerBundle::$distDir, \OWA\Module\Base\Classes\TrackerBundle::$outDir,
            \OWA\Module\Base\Classes\TrackerBundle::$buildDir] = $fixture[1];
        exec('rm -rf ' . escapeshellarg($fixture[0]));
    }

    public function testWithoutABuildTheBundlesAreRed(): void
    {
        $fixture = $this->bundleFixture(false);

        try {
            $this->assertSame('red', self::levels(SystemHealth::bundles())['Not built']);
        } finally {
            $this->dropBundleFixture($fixture);
        }
    }

    /** Every live Profile is listed; ones not published are attention. */
    public function testUnpublishedBundlesAreReported(): void
    {
        $live = \OWA\Module\Base\Classes\TrackerBundle::siteIds();

        if (!$live) {
            $this->markTestSkipped('needs a live web Profile');
        }

        $fixture = $this->bundleFixture();
        $c       = \OWA\Core\CoreAPI::configSingleton();
        $was     = $c->get('base', 'tracker_build');

        try {
            $bundles = SystemHealth::bundles();
            $this->assertSame($live, array_column($bundles['rows'], 'site_id'));
            $this->assertSame('not published', $bundles['rows'][0]['state'], 'nothing published into the fixture yet');
            $this->assertSame('yellow', self::levels($bundles)['Not current']);

            foreach ($live as $site_id) {
                \OWA\Module\Base\Classes\TrackerBundle::publish($site_id);
            }
            $c->set('base', 'tracker_build', \OWA\Module\Base\Module::requiredTrackerBuild());
            $this->assertSame('green', SystemHealth::bundles()['level'], 'all current');

            // A newer build is an update pending, which this screen is never
            // shown during; the section does not report it.
            $c->set('base', 'tracker_build', 'an-older-build');
            $this->assertSame('green', SystemHealth::bundles()['level']);
        } finally {
            $c->set('base', 'tracker_build', $was);
            $this->dropBundleFixture($fixture);
        }
    }

    /** What the installation holds, and how long it keeps it; raw's size from the server's statistics, not a count. */
    public function testTheDataSectionCountsTheHierarchyAndEstimatesRaw(): void
    {
        $data = SystemHealth::data();

        foreach (['Organizations', 'Properties', 'Profiles', 'Raw events', 'Retention', 'Fine partitions'] as $fact) {
            $this->assertArrayHasKey($fact, $data['facts']);
        }

        $this->assertMatchesRegularExpression('/^about [\d,]+, [\d.]+ (bytes|KB|MB|GB) on disk$/', $data['facts']['Raw events']);
        $this->assertGreaterThanOrEqual(count(\OWA\Module\Base\Classes\TrackerBundle::siteIds()),
            (int) $data['facts']['Profiles'], 'active Profiles include every live web one');

        $source = (string) file_get_contents(dirname(__DIR__) . '/modules/Base/Classes/SystemHealth.php');
        $this->assertStringContainsString('->tableSize( $raw )', $source, 'raw is sized from the server\'s statistics');
        $this->assertDoesNotMatchRegularExpression('/COUNT\(\*\)[^;]*\$raw/', $source, 'never counted');

        $size = \OWA\Core\CoreAPI::dbSingleton()->tableSize('owa_event_raw');
        $this->assertIsInt($size['rows']);
        $this->assertGreaterThan(0, $size['bytes']);
        $this->assertNull(\OWA\Core\CoreAPI::dbSingleton()->tableSize('not a table'), 'a name that cannot be one is refused');
    }

    /** No event-data window is said plainly: nothing is deleted. */
    public function testRetentionWithoutAWindowSaysNothingIsDeleted(): void
    {
        $was  = \OWA\Core\CoreAPI::getSetting('base', 'scheduled_jobs');
        $raw  = \OWA\Core\CoreAPI::getSetting('base', 'raw_retention_months');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'raw_retention_months', 0);
            $this->assertStringStartsWith('nothing is deleted', SystemHealth::data()['facts']['Retention']);

            \OWA\Core\CoreAPI::setSetting('base', 'raw_retention_months', 24);
            $this->assertStringStartsWith('events older than 24 months are deleted', SystemHealth::data()['facts']['Retention']);

            \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', ['rotate-partitions' => ['schedule' => 'off']]);
            $this->assertSame('yellow', self::levels(SystemHealth::data())['Not rotated']);
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'scheduled_jobs', $was);
            \OWA\Core\CoreAPI::setSetting('base', 'raw_retention_months', $raw);
        }
    }

    /** The installation section: versions, and exactly the installer's environment checks. */
    public function testTheInstallationIsItsVersionsAndTheInstallersChecks(): void
    {
        $install = SystemHealth::installation();

        $this->assertSame(['OWA', 'PHP', 'Schema', 'Tracker'], array_keys($install['facts']));
        $this->assertSame((string) OWA_VERSION, $install['facts']['OWA']);

        $checks = \OWA\Module\Base\Classes\EnvironmentCheck::all((string) \OWA\Core\CoreAPI::getSetting('base', 'config_file'));
        $this->assertSame(array_column($checks, 'name'), array_column($install['findings'], 'label'),
            'the same list the installer and cmd=instance-info read');
    }

    public function testThePageIsInTheSettingsNav(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $this->assertSame(\OWA\Module\Base\Controller\SystemHealth::class,
            $s->getMapValue('actions', 'base.systemHealth')['class_name']);
    }

    /** A scratch owa_spam_block for the block-count finding, emptied of anything else. */
    private function withSpamBlocks(callable $test): void
    {
        $table   = 'spam_block_phpunit_health';
        $scratch = \OWA\Core\CoreAPI::entityFactory('base.spam_block');
        $scratch->setTableName($table);

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS owa_' . $table);
        $this->assertTrue($scratch->createTable());

        \OWA\Module\Base\Classes\Spam\Blocks::$table = 'owa_' . $table;

        try {
            $test();
        } finally {
            \OWA\Module\Base\Classes\Spam\Blocks::$table = null;
            $db->query('DROP TABLE IF EXISTS owa_' . $table);
        }
    }

    public function testNothingBlockedShowsNothing(): void
    {
        $this->withSpamBlocks(function () {
            $this->assertNull(SystemHealth::spamBlocked());
            $this->assertArrayNotHasKey('Blocked as spam', self::levels(SystemHealth::intake()));
        });
    }

    /** Today and yesterday, in total and by rule, as information rather than a warning. */
    public function testBlockedBeaconsAreAGreenFindingWithTheirCounts(): void
    {
        $this->withSpamBlocks(function () {
            $today     = (int) date('Ymd');
            $yesterday = (int) date('Ymd', strtotime('-1 day'));
            $older     = (int) date('Ymd', strtotime('-3 days'));

            \OWA\Module\Base\Classes\Spam\Blocks::record($today, 'site-a', ['NO_FETCH_METADATA', 'NO_CLIENT_HINTS']);
            \OWA\Module\Base\Classes\Spam\Blocks::record($today, 'site-b', ['NO_FETCH_METADATA']);
            \OWA\Module\Base\Classes\Spam\Blocks::record($yesterday, 'site-a', ['UA_ROBOT_TOKEN']);
            \OWA\Module\Base\Classes\Spam\Blocks::record($older, 'site-a', ['UA_ROBOT_TOKEN']);

            $this->assertSame(['*' => 3, 'NO_FETCH_METADATA' => 2, 'NO_CLIENT_HINTS' => 1, 'UA_ROBOT_TOKEN' => 1],
                \OWA\Module\Base\Classes\Spam\Blocks::since($yesterday), 'the older day is not counted');

            $finding = SystemHealth::spamBlocked();

            $this->assertSame('green', $finding['level']);
            $this->assertSame('Blocked as spam', $finding['label']);
            $this->assertSame('3 beacon(s) today and yesterday. By rule: NO_FETCH_METADATA 2, NO_CLIENT_HINTS 1, UA_ROBOT_TOKEN 1.',
                $finding['detail']);

            $intake = SystemHealth::intake();
            $this->assertSame('green', self::levels($intake)['Blocked as spam']);
            $this->assertSame('green', $intake['level'], 'blocking is not a warning');
        });
    }
}
