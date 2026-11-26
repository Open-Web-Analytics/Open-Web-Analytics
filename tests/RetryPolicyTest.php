<?php

use OWA\Module\Base\Classes\RetryPolicy;
use OWA\Module\Base\Controller\ScheduleRunCli;
use PHPUnit\Framework\TestCase;

/**
 * One retry policy for recurring and one-off jobs: max_attempts and backoff,
 * meaning the same in both. Only where the waits are counted from differs.
 */
final class RetryPolicyTest extends TestCase
{
    private const SLOT = 1000000;

    /** @return array{max_attempts: int|null, backoff: list<int>} */
    private function policy(?int $max, array $backoff): array
    {
        return ['max_attempts' => $max, 'backoff' => $backoff];
    }

    public function testAUsablePolicyHasNoProblem(): void
    {
        foreach ([[], ['max_attempts' => 1], ['max_attempts' => null], ['backoff' => [0]],
                  ['max_attempts' => 5, 'backoff' => [60, 300]], RetryPolicy::RECURRING, RetryPolicy::ONE_OFF] as $ok) {
            $this->assertNull(RetryPolicy::problem($ok), json_encode($ok));
        }
    }

    public function testAnUnusablePolicyIsNamed(): void
    {
        $cases = [
            'not an array'     => [3, 'is an array'],
            'unknown key'      => [['attempts' => 3], 'has no attempts'],
            'zero attempts'    => [['max_attempts' => 0], 'max_attempts'],
            'attempts as text' => [['max_attempts' => '3'], 'max_attempts'],
            'no waits'         => [['backoff' => []], 'backoff'],
            'negative wait'    => [['backoff' => [60, -1]], 'backoff'],
            'waits as a map'   => [['backoff' => ['a' => 60]], 'backoff'],
            'wait as text'     => [['backoff' => ['60']], 'backoff'],
        ];

        foreach ($cases as $label => [$policy, $said]) {
            $problem = RetryPolicy::problem($policy);
            $this->assertNotNull($problem, $label);
            $this->assertStringContainsString($said, $problem, $label);
        }
    }

    /** The wait before attempt n is backoff step n-2, the last repeating: the queue's rule. */
    public function testTheWaitBeforeEachAttempt(): void
    {
        $p = $this->policy(5, [10, 20]);

        $this->assertSame([10, 20, 20, 20], array_map(fn ($n) => RetryPolicy::waitBefore($p, $n), [2, 3, 4, 5]));
    }

    public function testOnlyALimitedPolicyIsEverSpent(): void
    {
        $this->assertFalse(RetryPolicy::attemptsSpent($this->policy(3, [60]), 2));
        $this->assertTrue(RetryPolicy::attemptsSpent($this->policy(3, [60]), 3));
        $this->assertTrue(RetryPolicy::attemptsSpent($this->policy(1, [60]), 1));
        $this->assertFalse(RetryPolicy::attemptsSpent($this->policy(null, [60]), 10000));
    }

    /** A recurring run's attempt n may start at its scheduled time plus the first n-1 waits. */
    public function testEachAttemptsWindowOpensAfterTheWaitsBeforeIt(): void
    {
        $p = RetryPolicy::RECURRING;

        $this->assertSame([0, 60, 180, 420, 900, 1860, 3780, 7380, 10980],
            array_map(fn ($n) => RetryPolicy::windowOpens($p, self::SLOT, $n) - self::SLOT, range(1, 9)));
    }

    /** The attempt a run is on is the window its last start fell in: no counter to keep. */
    public function testTheAttemptIsTheWindowItStartedIn(): void
    {
        $p = RetryPolicy::RECURRING;

        foreach ([-1 => 0, 0 => 1, 59 => 1, 60 => 2, 179 => 2, 180 => 3, 7379 => 7, 7380 => 8, 10979 => 8, 10980 => 9] as $offset => $attempt) {
            $this->assertSame($attempt, RetryPolicy::attemptOf($p, self::SLOT, self::SLOT + $offset), "started $offset s after");
        }
    }

    /** windowOpens() and attemptOf() agree, past the listed steps too. */
    public function testWindowsAndAttemptsAgree(): void
    {
        foreach ([RetryPolicy::RECURRING, $this->policy(null, [300]), $this->policy(4, [10, 0, 30])] as $p) {
            for ($n = 1; $n <= 40; $n++) {
                $opens = RetryPolicy::windowOpens($p, self::SLOT, $n);
                $this->assertSame($n, RetryPolicy::attemptOf($p, self::SLOT, $opens), "attempt $n opens at $opens");
            }
        }
    }

    /** A run that starts days late lands in a late window, arithmetically, not by walking every window. */
    public function testALateStartLandsInALateWindow(): void
    {
        $p = RetryPolicy::RECURRING;

        // 7,380 s of listed steps open attempt 8; the rest of three days is 69 whole hours.
        $this->assertSame(8 + 69, RetryPolicy::attemptOf($p, self::SLOT, self::SLOT + 3 * 86400));
    }

    public function testThePolicyInWords(): void
    {
        $this->assertSame('until the next run, waiting 1 minute and doubling to 1 hour, then every 1 hour',
            RetryPolicy::describe(RetryPolicy::RECURRING, RetryPolicy::KIND_RECURRING));
        $this->assertSame('up to 3 attempts, waiting 1 minute then 5 minutes',
            RetryPolicy::describe(RetryPolicy::ONE_OFF, RetryPolicy::KIND_ONE_OFF));
        $this->assertSame('up to 5 attempts, waiting 1 minute, then every 5 minutes',
            RetryPolicy::describe($this->policy(5, [60, 300]), RetryPolicy::KIND_ONE_OFF));
        $this->assertSame('one attempt, not retried',
            RetryPolicy::describe($this->policy(1, [60]), RetryPolicy::KIND_RECURRING));
        $this->assertSame('until it succeeds, every 10 minutes',
            RetryPolicy::describe($this->policy(null, [600]), RetryPolicy::KIND_ONE_OFF));
    }

    /** ok and refused answer the occurrence; a failure only when it gave up or was the last attempt. */
    public function testWhenARecurringRunIsAnswered(): void
    {
        $limited = $this->policy(3, [60]);
        $open    = $this->policy(null, [60]);

        $this->assertTrue(ScheduleRunCli::settle(['outcome' => 'ok'], $limited, 1)['answered']);
        $this->assertTrue(ScheduleRunCli::settle(['outcome' => 'refused'], $limited, 1)['answered']);

        $this->assertFalse(ScheduleRunCli::settle(['outcome' => 'failed'], $limited, 2)['answered']);
        $this->assertFalse(ScheduleRunCli::settle(['outcome' => 'failed'], $open, 500)['answered']);
        $this->assertFalse(ScheduleRunCli::settle(['outcome' => 'failed', 'final' => false], $limited, 1)['answered']);

        $spent = ScheduleRunCli::settle(['outcome' => 'failed', 'message' => 'it broke'], $limited, 3);
        $this->assertTrue($spent['answered']);
        $this->assertSame('No attempts left, not retried before the next run: it broke', $spent['message']);

        $gave_up = ScheduleRunCli::settle(['outcome' => 'failed', 'final' => true, 'message' => 'key rejected'], $open, 1);
        $this->assertTrue($gave_up['answered']);
        $this->assertSame('Gave up, not retried before the next run: key rejected', $gave_up['message']);

        // A forced run is no attempt at an occurrence: only a give-up says it is not retried.
        $this->assertFalse(ScheduleRunCli::settle(['outcome' => 'failed'], $limited, 0)['answered']);
    }
}
