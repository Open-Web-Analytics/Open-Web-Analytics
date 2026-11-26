<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The suite holds every scheduled job's lease while it runs
 * (owa_test_pause_scheduler()), owned as phpunit:<host>:<pid>. A killed run
 * never releases them; the next run on the host takes back a lease whose
 * PHPUnit has gone, and leaves any other.
 */
final class TestLeaseOwnerTest extends TestCase
{
    public function testThisRunOwnsItsLeasesByHostAndPid(): void
    {
        $this->assertSame('phpunit:' . gethostname() . ':' . getmypid(), owa_test_lease_owner());
        $this->assertFalse(owa_test_lease_is_orphaned(owa_test_lease_owner()), 'never its own');
    }

    public function testAnEndedRunsLeaseIsTakenBack(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', 'echo getmypid();'], [1 => ['pipe', 'w']], $pipes);
        $pid  = (int) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);

        $this->assertGreaterThan(0, $pid);
        $this->assertTrue(owa_test_lease_is_orphaned('phpunit:' . gethostname() . ':' . $pid), 'its pid runs nothing');
    }

    public function testALeaseHeldByALiveRunOrAnotherHostOrTheSchedulerIsLeft(): void
    {
        $this->assertTrue(owa_test_lease_is_orphaned('phpunit:' . gethostname() . ':1'), 'pid 1 is not PHPUnit: the pid was reused');
        $this->assertFalse(owa_test_lease_is_orphaned('phpunit:another-host:' . getmypid()));
        $this->assertFalse(owa_test_lease_is_orphaned('3a30cc89f3c4301d'), 'a scheduler run\'s random owner');
        $this->assertFalse(owa_test_lease_is_orphaned(''), 'unheld');
    }
}
