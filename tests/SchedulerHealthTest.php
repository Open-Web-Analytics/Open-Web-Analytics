<?php

require_once __DIR__ . '/CliControllerTestCase.php';

/**
 * The "you never added the cron entry" banner.
 *
 * The scheduler is one crontab line, and the likeliest failure is that nobody
 * added it. Nothing then runs, and a scheduler that is not running produces no
 * output -- so without this check the failure is completely silent. These cases
 * pin what may be inferred and, just as importantly, what may not.
 */
final class SchedulerHealthTest extends CliControllerTestCase
{
    /** @var array */
    private $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        \OWA\Core\CoreAPI::entityFactory('base.scheduled_job')->createTable();

        // Preserve whatever this installation actually has; these cases rewrite
        // the table wholesale.
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $this->saved = (array) $db->get_results('SELECT * FROM owa_scheduled_job');

        $db->query('DELETE FROM owa_scheduled_job');
        \OWA\Module\Base\Classes\SchedulerHealth::forget();
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DELETE FROM owa_scheduled_job');

        foreach ($this->saved as $row) {
            $row = (array) $row;
            $cols = implode(',', array_keys($row));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . $db->prepare((string) $v) . "'", $row));
            $db->query("INSERT INTO owa_scheduled_job ($cols) VALUES ($vals)");
        }

        \OWA\Module\Base\Classes\SchedulerHealth::forget();

        parent::tearDown();
    }

    private function seed(int $last_run_at): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query(sprintf(
            "INSERT INTO owa_scheduled_job (id, job_name, last_status, last_message, last_params, last_run_at, run_count)
             VALUES (%d, 'probe-job', 'ok', '-', '-', %d, 1)",
            crc32('probe-job'), $last_run_at
        ));

        \OWA\Module\Base\Classes\SchedulerHealth::forget();
    }

    /**
     * An empty state table is EXACT evidence: the dispatcher is the only thing
     * that writes rows, and it materialises one per job on its first tick.
     */
    public function testAnEmptyTableMeansTheCronEntryWasNeverAdded()
    {
        $nag = \OWA\Module\Base\Classes\SchedulerHealth::problem();

        $this->assertIsArray($nag, 'an empty table must raise the banner');
        $this->assertStringContainsString('not running', $nag['headline']);
        $this->assertStringContainsString('cron', $nag['message']);
    }

    /** A dispatcher that ran recently is healthy and must say nothing. */
    public function testARecentRunSaysNothing()
    {
        $this->seed(time() - 3600);

        $this->assertNull(\OWA\Module\Base\Classes\SchedulerHealth::problem());
    }

    /**
     * Silence shorter than the longest shipped schedule is NOT evidence of
     * failure. partition-rotate is monthly, so a healthy installation writes
     * nothing for weeks — crying wolf here would teach people to ignore the
     * banner, which costs more than noticing a dead scheduler late.
     */
    public function testSilenceShorterThanAMonthIsNotAFailure()
    {
        foreach ([2, 10, 25, 35] as $days) {
            $this->seed(time() - ($days * 86400));

            $this->assertNull(
                \OWA\Module\Base\Classes\SchedulerHealth::problem(),
                "$days days of silence is normal for a monthly job and must not warn"
            );

            \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_scheduled_job');
        }
    }

    /** Past the window, it reports a stop — and distinguishes it from never. */
    public function testProlongedSilenceReportsAStop()
    {
        $this->seed(time() - (45 * 86400));

        $nag = \OWA\Module\Base\Classes\SchedulerHealth::problem();

        $this->assertIsArray($nag);
        $this->assertStringContainsString('may have stopped', $nag['headline'],
            'a scheduler that ran once and stopped is a different problem from one never installed');
        $this->assertStringNotContainsString('not running', $nag['headline']);
    }

    /**
     * On an installation that has not applied the update the table does not
     * exist. Db::query() swallows that and returns falsy, and the right answer
     * is silence: the updates-required path already has that install's
     * attention, and a banner about a missing table would only confuse.
     */
    public function testAMissingTableSaysNothing()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query('CREATE TABLE owa_scheduled_job_hidden LIKE owa_scheduled_job');
        $db->query('DROP TABLE owa_scheduled_job');

        \OWA\Module\Base\Classes\SchedulerHealth::forget();

        try {
            $this->assertNull(
                \OWA\Module\Base\Classes\SchedulerHealth::problem(),
                'a pre-update install must not be nagged about a table it does not have'
            );

        } finally {
            $db->query('CREATE TABLE owa_scheduled_job LIKE owa_scheduled_job_hidden');
            $db->query('DROP TABLE owa_scheduled_job_hidden');
            \OWA\Module\Base\Classes\SchedulerHealth::forget();
        }
    }

    /** The answer is memoised: it cannot change within one request. */
    public function testTheAnswerIsMemoisedPerRequest()
    {
        $first = \OWA\Module\Base\Classes\SchedulerHealth::problem();
        $this->assertIsArray($first, 'starts empty, so starts nagging');

        // Change the underlying facts without clearing the memo.
        $this->seed(time());
        // seed() forgets, so re-establish the memo and then change again.
        \OWA\Module\Base\Classes\SchedulerHealth::problem();
        \OWA\Core\CoreAPI::dbSingleton()->query('DELETE FROM owa_scheduled_job');

        $this->assertNull(
            \OWA\Module\Base\Classes\SchedulerHealth::problem(),
            'the memoised answer stands for the rest of the request'
        );
    }

    /**
     * The banner offers a line to paste, so it has to be this installation's
     * real one -- not a documentation example with a placeholder path.
     */
    public function testTheCronLineIsThisInstallationsOwn()
    {
        $line = \OWA\Module\Base\Classes\SchedulerHealth::cronLine();

        $this->assertStringStartsWith('* * * * *', $line, 'every minute: the schedules decide the rest');
        $this->assertStringContainsString('cmd=schedule-run', $line);
        $this->assertStringContainsString(rtrim(OWA_DIR, '/'), $line, 'a real path, not a placeholder');
        $this->assertStringNotContainsString('/path/to', $line);
    }

    /**
     * The template itself: it must render for someone who can act, and stay
     * silent for someone who cannot.
     *
     * The detection is unit-tested above, but a banner nobody sees -- or one
     * shown to a viewer who cannot edit a crontab -- would pass every one of
     * those cases. This exercises the partial.
     */
    public function testTheBannerRendersOnlyForUsersWhoCanActOnIt()
    {
        // The table is empty (setUp), so there is something to say.
        $this->assertIsArray(\OWA\Module\Base\Classes\SchedulerHealth::problem());

        foreach ([true => 'capable', false => 'not capable'] as $capable => $label) {

            $view = new class($capable) {
                private $capable;
                public function __construct($capable) { $this->capable = $capable; }
                public function getCurrentUser() {
                    return new class($this->capable) {
                        private $capable;
                        public function __construct($capable) { $this->capable = $capable; }
                        public function isCapable($cap) { return $this->capable; }
                    };
                }
                public function out($s) { echo htmlspecialchars((string) $s); }
            };

            \OWA\Module\Base\Classes\SchedulerHealth::forget();

            ob_start();
            include OWA_BASE_MODULE_DIR . 'templates/scheduler_nag.php';
            $html = ob_get_clean();

            if ($capable) {
                $this->assertStringContainsString('Scheduled maintenance is not running', $html,
                    'an admin must be told the cron entry is missing');
                $this->assertStringContainsString('cmd=schedule-run', $html,
                    'and given the line to paste');
                $this->assertStringContainsString('owa-scheduler-nag', $html);
            } else {
                $this->assertSame('', trim($html),
                    'a user who cannot edit a crontab must not be shown a banner about one');
            }
        }
    }

    /** The banner escapes what it prints; the path comes from configuration. */
    public function testTheBannerEscapesItsOutput()
    {
        $view = new class {
            public function getCurrentUser() {
                return new class { public function isCapable($cap) { return true; } };
            }
            public function out($s) { echo htmlspecialchars((string) $s); }
        };

        \OWA\Module\Base\Classes\SchedulerHealth::forget();

        ob_start();
        include OWA_BASE_MODULE_DIR . 'templates/scheduler_nag.php';
        $html = ob_get_clean();

        // The only markup should be ours; nothing interpolated raw.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(
            substr_count($html, '<div'), substr_count($html, '</div>'),
            'balanced markup'
        );
    }
}
