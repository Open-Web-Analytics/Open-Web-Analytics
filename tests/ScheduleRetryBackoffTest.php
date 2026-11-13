<?php

use PHPUnit\Framework\TestCase;

/**
 * A scheduled job whose last run failed waits before it runs again.
 *
 * A failure does not satisfy the occurrence, so the job stayed due and ran on
 * every tick. ScheduleRunCli::retryAfter() spaces the attempts: as long as the
 * job has been failing, between a minute and an hour.
 */
final class ScheduleRetryBackoffTest extends TestCase
{
    private function state(array $values)
    {
        return new class($values) {
            private $v;
            public function __construct(array $v) { $this->v = $v; }
            public function get($k) { return $this->v[$k] ?? null; }
        };
    }

    private function retry(array $values): ?int
    {
        return \OWA\Module\Base\Controller\ScheduleRunCli::retryAfter($this->state($values));
    }

    public function testAJobThatDidNotFailIsNotHeldBack(): void
    {
        $this->assertNull($this->retry(['last_status' => 'ok', 'last_failure_at' => 1000]));
        $this->assertNull($this->retry(['last_status' => 'refused', 'last_failure_at' => 1000]));
    }

    public function testItWaitsAsLongAsItHasBeenFailing(): void
    {
        // Succeeded at 10,000, failing since, last failure at 10,600: ten minutes.
        $this->assertSame(10600 + 600, $this->retry([
            'last_status' => 'failed', 'last_success_at' => 10000, 'last_failure_at' => 10600]));
    }

    public function testTheWaitIsAtLeastAMinuteAndAtMostAnHour(): void
    {
        $this->assertSame(10010 + 60, $this->retry([
            'last_status' => 'failed', 'last_success_at' => 10000, 'last_failure_at' => 10010]));

        // A daily job that worked yesterday and fails today retries hourly, not every minute.
        $this->assertSame(100000 + 3600, $this->retry([
            'last_status' => 'failed', 'last_success_at' => 100000 - 86400, 'last_failure_at' => 100000]));
    }

    public function testAJobThatNeverSucceededDoublesWithEachFailure(): void
    {
        $waits = [];

        foreach ([1, 2, 3, 4, 5, 6, 7, 20] as $failures) {
            $waits[] = $this->retry(['last_status' => 'failed', 'last_failure_at' => 5000,
                                     'failure_count' => $failures]) - 5000;
        }

        $this->assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600], $waits);
    }
}
