<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\JobQueue;
use OWA\Module\Base\Classes\SystemHealth;
use OWA\Module\Base\Classes\TrackerIngest;

/**
 * System Health: what Classes\SystemHealth finds, and that the
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
        JobQueue::$table          = self::JOBS;
        JobQueue::$frozen_at      = time();
        JobQueue::$also_queueable = ['partition-status', 'jobs-prune'];

        $this->intakeDir = sys_get_temp_dir() . '/owa-health-' . bin2hex(random_bytes(4)) . '/';
        TrackerIngest::$queue = new \OWA\Module\Base\Classes\FileEventQueue(['path' => $this->intakeDir]);
    }

    protected function tearDown(): void
    {
        JobQueue::$table          = null;
        JobQueue::$frozen_at      = null;
        JobQueue::$also_queueable = [];
        TrackerIngest::$queue   = null;
        TrackerIngest::$backend = null;
        \OWA\Core\Strings::useTranslations(null);

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

    /** Direct mode says only a failed write is queued, so no one reads it as every beacon going to the queue. */
    public function testTheQuietFindingSaysWhatIsQueuedInEachMode(): void
    {
        $before = \OWA\Core\CoreAPI::getSetting('base', 'queue_tracking_events');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', false);
            $direct = array_column(SystemHealth::intake()['findings'], 'detail', 'label');
            $this->assertSame('Beacons are written as they arrive. Only a beacon whose write fails is queued, '
                . 'and retried a minute later.', $direct['Direct'] ?? null);

            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);
            $queued = array_column(SystemHealth::intake()['findings'], 'detail', 'label');
            $this->assertArrayHasKey('Draining', $queued);
            $this->assertArrayNotHasKey('Direct', $queued);
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', $before);
        }
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

    /** A drained batch that cannot be archived is set aside, and red, with its reason. */
    public function testABatchSetAsideIsRed(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting('base', 'archive_old_events');
        \OWA\Core\CoreAPI::setSetting('base', 'archive_old_events', true);

        $q = TrackerIngest::$queue;
        $q->send(['v' => 1, 'type' => 'page_view', 'properties' => [], 'queued_at' => 1]);
        $got = $q->receive(10, 300);

        @mkdir($this->intakeDir . 'archive', 0775, true);
        chmod($this->intakeDir . 'archive', 0555);

        try {
            ob_start();
            $q->ack($got[0]);
            ob_end_clean();

            $this->assertSame('red', self::levels(SystemHealth::intake())['Batch set aside']);
        } finally {
            chmod($this->intakeDir . 'archive', 0775);
            \OWA\Core\CoreAPI::setSetting('base', 'archive_old_events', $was);
        }
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

    /**
     * A module's section comes after Base's (system_health_sections), and
     * one under a key of Base's is not taken.
     */
    public function testAModulesSectionsFollowBases(): void
    {
        $ed  = \OWA\Core\CoreAPI::getEventDispatch();
        $was = $ed->listenersByFilterType['system_health_sections'] ?? null;

        \OWA\Core\CoreAPI::registerFilter('system_health_sections', function ($sections) {
            $sections['owatest'] = SystemHealth::section('Module check', [SystemHealth::finding('yellow', 'Probe', 'Something to look at.')]);
            $sections['scheduler'] = SystemHealth::section('Not the scheduler', []);

            return $sections;
        });

        try {
            $sections = SystemHealth::sections();

            $this->assertSame('owatest', array_key_last($sections));
            $this->assertSame('yellow', $sections['owatest']['level']);
            $this->assertNotSame('Not the scheduler', $sections['scheduler']['title']);

            $t = new \OWA\Core\Template('base');
            $t->set('sections', $sections);
            $this->assertTrue($t->set_template('system_health.php'));
            $html = (string) $t->fetch();

            $this->assertStringContainsString('Module check', $html);
            $this->assertStringContainsString('Something to look at.', $html);
        } finally {
            if ($was === null) {
                unset($ed->listenersByFilterType['system_health_sections']);
            } else {
                $ed->listenersByFilterType['system_health_sections'] = $was;
            }
        }
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
        $sql  = [];
        $data = $this->queriesDuring(function () { return SystemHealth::data(); }, $sql);

        foreach (['Organizations', 'Properties', 'Profiles', 'Raw events', 'Retention', 'Fine partitions'] as $fact) {
            $this->assertArrayHasKey($fact, $data['facts']);
        }

        $this->assertMatchesRegularExpression('/^about [\d,]+, [\d.]+ (bytes|KB|MB|GB) on disk$/', $data['facts']['Raw events']);
        $this->assertGreaterThanOrEqual(count(\OWA\Module\Base\Classes\TrackerBundle::siteIds()),
            (int) $data['facts']['Profiles'], 'active Profiles include every live web one');

        $raw = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();

        $this->assertNotEmpty($sql, 'the queries were not seen, so this test cannot tell');
        $this->assertSame([], array_values(array_filter($sql, static function ($q) use ($raw) {
            return stripos($q, 'COUNT(') !== false && strpos($q, $raw) !== false;
        })), 'raw is never counted');

        $this->assertNotSame([], array_filter($sql, static function ($q) use ($raw) {
            return strpos($q, $raw) !== false;
        }), 'raw is sized from the server\'s statistics, which names it');

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

    /**
     * The installation section: versions, exactly the installer's environment
     * checks, the retired constants still set, and whether admin notices can
     * be e-mailed.
     */
    public function testTheInstallationIsItsVersionsAndTheInstallersChecks(): void
    {
        $install = SystemHealth::installation();

        $this->assertSame(['OWA', 'Server role', 'PHP', 'Schema', 'Tracker'], array_keys($install['facts']));
        $this->assertSame((string) \OWA\Core\Env::version(), $install['facts']['OWA']);

        $checks = \OWA\Module\Base\Classes\EnvironmentCheck::all((string) \OWA\Core\CoreAPI::getSetting('base', 'config_file'));
        $this->assertSame(
            array_merge(array_column($checks, 'name'), array_keys(\OWA\Core\ServerRole::upgradeNotices()), ['Admin notice e-mail']),
            array_column($install['findings'], 'label'),
            'the same checks the installer and cmd=instance-info read, then the retired constants, then notice mail');
    }

    /** OWA_BACKUP_DIR, when set: red inside what the web server serves, whoever can write it. */
    public function testTheBackupDirectoryIsShownWhenSet(): void
    {
        $saved = \OWA\Core\CoreAPI::getSetting('base', 'backup_dir');
        $dir   = sys_get_temp_dir() . '/owa-health-backup-' . getmypid();
        mkdir($dir, 0500);

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'backup_dir', '');
            $this->assertNull(SystemHealth::backupDir());

            \OWA\Core\CoreAPI::setSetting('base', 'backup_dir', \OWA\Core\Env::root() . '/owa-data');
            $this->assertSame('red', SystemHealth::backupDir()['level']);

            \OWA\Core\CoreAPI::setSetting('base', 'backup_dir', $dir);
            $this->assertSame(['green', $dir], [SystemHealth::backupDir()['level'], SystemHealth::backupDir()['detail']],
                'not writable here, which is the command\'s question');

            $this->assertContains('Backup directory', array_column(SystemHealth::installation()['findings'], 'label'));
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'backup_dir', $saved);
            rmdir($dir);
        }
    }

    /** Two automatic updates directories: red, since neither is guessed at. */
    public function testMoreThanOneUpdatesDirectoryIsShown(): void
    {
        $data  = \OWA\Core\Env::dataDir();
        $made  = [];

        $this->assertNull(SystemHealth::updatesDirs());

        try {
            foreach (['d', 'e'] as $c) {
                $made[] = $dir = $data . 'updates-' . str_repeat($c, 32);
                mkdir($dir, 0700);
            }

            $finding = SystemHealth::updatesDirs();
            $this->assertSame('red', $finding['level']);
            $this->assertStringContainsString('holds 2 automatic updates directories', $finding['detail']);
        } finally {
            foreach ($made as $dir) {
                @rmdir($dir);
            }
        }
    }

    /** An automatic updates file that is not cli.php's owner's: red, naming it. */
    public function testAnUpdateFileOfAnotherUserIsShown(): void
    {
        \OWA\Module\Base\Classes\AutoUpdate\InstallLock::$path = $lock = sys_get_temp_dir() . '/owa-foreign-' . bin2hex(random_bytes(4));

        try {
            touch($lock);
            $this->assertNull(SystemHealth::foreignUpdateFiles(fileowner($lock)), 'its own');

            $finding = SystemHealth::foreignUpdateFiles(fileowner($lock) + 1);
            $this->assertSame('red', $finding['level']);
            $this->assertStringContainsString(basename($lock) . ' belongs to', $finding['detail']);
        } finally {
            @unlink($lock);
            \OWA\Module\Base\Classes\AutoUpdate\InstallLock::$path = null;
        }
    }

    /** A failed install, from the moment it is recorded: red, reverted or broken. */
    public function testAFailedInstallIsShownFromTheStart(): void
    {
        $this->assertNull(SystemHealth::failedInstall(['version' => '2.0.3', 'error' => '']));

        $reverted = SystemHealth::failedInstall(['version' => '2.0.3', 'from' => '2.0.2', 'error' => 'Could not write Core/Env.php.', 'attempts' => 1, 'retry_after' => 1800000000]);
        $this->assertSame(['red', 'Installing OWA 2.0.3 failed'], [$reverted['level'], $reverted['label']]);
        $this->assertStringContainsString('The install was reverted: OWA 2.0.2 runs as before. It is tried again after', $reverted['detail'], 'before any notice is raised');

        $broken = SystemHealth::failedInstall(['version' => '2.0.3', 'from' => '2.0.2', 'error' => 'Update 095 failed', 'booted' => true, 'step' => 'schema']);
        $this->assertSame('red', $broken['level']);
        $this->assertStringContainsString('The install is broken and needs attention now', $broken['detail']);
    }

    /** What stops a release before anything changes: red, from the first check that finds it. */
    public function testAProblemBeforeTheCopyIsShownFromTheStart(): void
    {
        $finding = SystemHealth::updateProblem(['stage' => 'requirements', 'version' => '2.0.3', 'from' => '2.0.2', 'error' => 'It needs PHP 8.2 or later',
            'first_at' => 1800000000, 'at' => 1800000000]);
        $this->assertSame(['red', 'OWA 2.0.3 cannot be installed yet'], [$finding['level'], $finding['label']]);
        $this->assertStringContainsString("OWA 2.0.3's requirements are not met: It needs PHP 8.2 or later. Nothing was changed: OWA 2.0.2 runs as before.", $finding['detail']);
    }

    public function testTheInstallationNamesTheServerRole(): void
    {
        $saved = \OWA\Core\CoreAPI::getSetting('base', 'server_role');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'full');
            $this->assertSame('full (serves tracking, ui, rest, cli, install; runs the scheduler)',
                SystemHealth::installation()['facts']['Server role']);

            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector,api');
            $this->assertSame('collector,api (serves tracking, rest, cli)', SystemHealth::installation()['facts']['Server role']);
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'server_role', $saved);
        }
    }

    /** A queue backend with queueing off: said on a server that receives beacons, not on one that only drains. */
    public function testAQueueBackendWithQueueingOffIsSaid(): void
    {
        $saved = [
            'server_role'           => \OWA\Core\CoreAPI::getSetting('base', 'server_role'),
            'queue_tracking_events' => \OWA\Core\CoreAPI::getSetting('base', 'queue_tracking_events'),
        ];

        try {
            TrackerIngest::$backend = 'sqs';
            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', false);

            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'full');
            $full = array_column(SystemHealth::intake()['findings'], 'level', 'label');
            $this->assertSame('green', $full['SQS configured, tracking events not queued'] ?? null);
            $this->assertSame('sqs', SystemHealth::intake()['type']);

            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'api');
            $this->assertArrayNotHasKey('SQS configured, tracking events not queued',
                array_column(SystemHealth::intake()['findings'], 'level', 'label'));

            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'full');
            \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);
            $this->assertArrayNotHasKey('SQS configured, tracking events not queued',
                array_column(SystemHealth::intake()['findings'], 'level', 'label'));
        } finally {
            foreach ($saved as $key => $value) {
                \OWA\Core\CoreAPI::setSetting('base', $key, $value);
            }
        }
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
            $this->assertSame('3 beacons today and yesterday. By rule: NO_FETCH_METADATA 2, NO_CLIENT_HINTS 1, UA_ROBOT_TOKEN 1.',
                $finding['detail']);

            $intake = SystemHealth::intake();
            $this->assertSame('green', self::levels($intake)['Blocked as spam']);
            $this->assertSame('green', $intake['level'], 'blocking is not a warning');
        });
    }
    /** A hung drain is named with its host when the batch records one, in one translatable sentence either way. */
    public function testAHungDrainIsOneSentenceWithOrWithoutItsHost(): void
    {
        $holder = new \OWA\Module\Base\Classes\FileEventQueue(['path' => $this->intakeDir]);
        $holder->send(['v' => 1, 'type' => 'page_view', 'properties' => [], 'queued_at' => 1]);
        $holder->receive(10, 300);

        $state = glob($this->intakeDir . 'processing/*.state')[0];
        $row   = json_decode((string) file_get_contents($state), true);
        $row['held_since'] = time() - 3600;
        $batch = basename($state, '.state');

        $detail = function () {
            $found = array_column(SystemHealth::intake()['findings'], 'detail', 'label');
            return $found['Hung drain'] ?? null;
        };

        $row['host'] = 'worker-2';
        file_put_contents($state, json_encode($row));
        \OWA\Core\Strings::useTranslations([
            'Batch %1$s has been held for %2$s by process %3$s on %4$s. If that process is hung, end it and the next drain takes the batch over.'
                => 'Stapel %1$s seit %2$s von Prozess %3$s auf %4$s gehalten.',
            'Batch %1$s has been held for %2$s by process %3$s. If that process is hung, end it and the next drain takes the batch over.'
                => 'Stapel %1$s seit %2$s von Prozess %3$s gehalten.',
        ]);

        $this->assertSame('Stapel ' . $batch . ' seit 60 minutes von Prozess ' . getmypid() . ' auf worker-2 gehalten.', $detail());

        unset($row['host']);
        file_put_contents($state, json_encode($row));

        $this->assertSame('Stapel ' . $batch . ' seit 60 minutes von Prozess ' . getmypid() . ' gehalten.', $detail());
        unset($holder);
    }

    /** The words a job row and a status badge show are translated; the outcome a row is compared by is not. */
    public function testJobWordsAndBadgesAreTranslated(): void
    {
        \OWA\Core\Strings::useTranslations([
            \OWA\Core\Strings::key('running', 'job outcome') => 'läuft',
            \OWA\Core\Strings::key('failed', 'job outcome')  => 'fehlgeschlagen',
            'Action needed' => 'Handeln',
            'Attention'     => 'Achtung',
            'OK'            => 'Gut',
        ]);

        $this->assertSame('läuft', SystemHealth::outcomeLabel('running'));
        $this->assertSame('fehlgeschlagen', SystemHealth::outcomeLabel('failed'));
        $this->assertSame('ok', SystemHealth::outcomeLabel('ok'));
        $this->assertSame('something else', SystemHealth::outcomeLabel('something else'));
        $this->assertSame('', SystemHealth::outcomeLabel(''));

        foreach (SystemHealth::recurringJobs()['rows'] as $r) {
            $this->assertSame(SystemHealth::outcomeLabel($r['outcome']), $r['outcome_label']);
        }

        require_once \OWA\Core\Env::root() . '/modules/Base/templates/cube_status_badge.php';

        $this->assertStringContainsString('</span>Handeln</span>', owa_cube_status_badge('red'));
        $this->assertStringContainsString('</span>Achtung</span>', owa_cube_status_badge('yellow'));
        $this->assertStringContainsString('</span>Gut</span>', owa_cube_status_badge('green'));
    }

    /**
     * Run $fn, collecting the SQL the database singleton logs while it runs.
     *
     * @param list<string> $sql
     * @return mixed what $fn returns
     */
    private function queriesDuring(callable $fn, array &$sql)
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $was = $db->e;

        $db->e = new class ($was, $sql) {
            private $real;
            private $sql;

            public function __construct($real, array &$sql) { $this->real = $real; $this->sql = &$sql; }

            public function debug($message, $context = null)
            {
                if (is_string($message) && strpos($message, 'Query: ') === 0) {
                    $this->sql[] = substr($message, 7);
                }

                return $this->real->debug($message, $context);
            }

            public function __call($name, $args) { return $this->real->$name(...$args); }
        };

        try {
            return $fn();
        } finally {
            $db->e = $was;
        }
    }

    /** The web server able to change OWA's code, and not its owner: yellow, naming what it can write. */
    public function testCodeTheWebServerCanChange(): void
    {
        $root = sys_get_temp_dir() . '/owa-writable-' . bin2hex(random_bytes(4));
        mkdir($root . '/Core', 0775, true);
        mkdir($root . '/modules', 0775);
        file_put_contents($root . '/owa.php', '<?php');
        chmod($root . '/owa.php', 0664);
        $owner = fileowner($root . '/owa.php');

        try {
            $this->assertNull(SystemHealth::writableCode($root, $owner), 'its owner: nothing to tighten');

            $finding = SystemHealth::writableCode($root, $owner + 1);
            $this->assertSame('yellow', $finding['level']);
            $this->assertStringContainsString('(the OWA directory, owa.php, Core/, modules/)', $finding['detail'], 'no extensions/: nothing said of it');

            mkdir($root . '/extensions', 0775);
            $this->assertStringContainsString('(the OWA directory, owa.php, Core/, modules/, extensions/)', SystemHealth::writableCode($root, $owner + 1)['detail']);
            chmod($root . '/extensions', 0555);

            chmod($root . '/owa.php', 0444);
            chmod($root . '/Core', 0555);
            chmod($root . '/modules', 0555);
            $this->assertStringContainsString('(the OWA directory)', SystemHealth::writableCode($root, $owner + 1)['detail'],
                'a writable directory lets any file in it be replaced');

            chmod($root, 0555);
            chmod($root . '/Core', 0555);
            chmod($root . '/modules', 0555);
            $this->assertNull(SystemHealth::writableCode($root, $owner + 1), 'not writable: nothing to say');
        } finally {
            chmod($root, 0755);
            chmod($root . '/Core', 0755);
            chmod($root . '/modules', 0755);
            @chmod($root . '/extensions', 0755);
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testADisabledEndpointsValueOfNoUsableTypeIsShown(): void
    {
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $saved = $c->get('base', 'disabledEndpoints');

        try {
            $c->set('base', 'disabledEndpoints', 5);
            $finding = array_column(SystemHealth::installation()['findings'], null, 'label')['OWA_DISABLED_ENDPOINTS'] ?? null;
            $this->assertSame('yellow', $finding['level'] ?? null);
            $this->assertStringContainsString('of type int', (string) $finding['detail']);

            $c->set('base', 'disabledEndpoints', 'log.php, cli.php');
            $this->assertArrayNotHasKey('OWA_DISABLED_ENDPOINTS', array_column(SystemHealth::installation()['findings'], null, 'label'));
        } finally {
            $c->set('base', 'disabledEndpoints', $saved);
        }
    }

    public function testAMalformedUpdateWindowIsShown(): void
    {
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $saved = $c->get('base', 'auto_update_window');

        try {
            $c->set('base', 'auto_update_window', '2am-6am');
            $finding = array_column(SystemHealth::installation()['findings'], null, 'label')['Update Window'] ?? null;
            $this->assertSame('yellow', $finding['level'] ?? null);
            $this->assertSame(\OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck::ignoredWindow('2am-6am'), $finding['detail']);

            $c->set('base', 'auto_update_window', '02:00-06:00');
            $this->assertArrayNotHasKey('Update Window', array_column(SystemHealth::installation()['findings'], null, 'label'));
        } finally {
            $c->set('base', 'auto_update_window', $saved);
        }
    }
}
