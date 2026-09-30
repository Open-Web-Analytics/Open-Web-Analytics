<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * While the suite runs, no scheduled job can start against its database.
 *
 * bootstrap_owa.php takes every registered job's lease for the life of the
 * PHPUnit process (owa_test_pause_scheduler()). Without it, the development
 * install's cron built the suite's fixture cubes mid-test, and failed to build
 * its own real cube while an update test had rolled a column back.
 */
final class SuitePausesSchedulerTest extends TestCase
{
    public function testEveryRegisteredJobIsHeldForTheRun(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('The leases live in the database.');
        }

        $jobs = array_keys(\OWA\Module\Base\Classes\JobStatus::jobs());

        $this->assertNotEmpty($jobs);

        foreach ($jobs as $name) {
            $cron = new \OWA\Module\Base\Classes\JobLease($name);
            $got  = $cron->acquire(60);

            if ($got) {
                $cron->release();
            }

            $this->assertFalse($got, "schedule-run could start \"$name\" while the suite runs");
        }
    }
}
