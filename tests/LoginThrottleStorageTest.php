<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\LoginThrottle;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * LoginThrottle on owa_login_failure: the same rule LoginThrottleTest checks
 * in memory, through its statements. Each test counts against a name
 * and an address of its own, and deletes them after.
 */
final class LoginThrottleStorageTest extends TestCase
{
    /** @var list<string> */
    private $keys = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable.');
        }

        LoginThrottle::$enabled = true;
        LoginThrottle::$rows    = null;

        $tok        = bin2hex(random_bytes(6));
        $this->keys = LoginThrottle::keys('owa-test-throttle-' . $tok, '198.51.100.' . random_int(1, 254) . '-' . $tok);
    }

    protected function tearDown(): void
    {
        if ($this->keys) {
            LoginThrottle::clear($this->keys);
        }

        LoginThrottle::$enabled = false;
    }

    /** @return array<string, mixed>|null */
    private function row(string $key): ?array
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row('SELECT failures, first_failure_at, last_failure_at FROM owa_login_failure WHERE subject = ?', [$key]);

        return is_array($row) ? array_map('intval', $row) : null;
    }

    public function testFiveFailuresRefuseUntilFifteenMinutesAfterTheLast(): void
    {
        $now = time();

        for ($i = 0; $i < 4; $i++) {
            LoginThrottle::recordFailure($this->keys, $now - 240 + $i * 60);
        }

        $this->assertFalse(LoginThrottle::isLocked($this->keys, $now));

        LoginThrottle::recordFailure($this->keys, $now);

        $this->assertSame(['failures' => 5, 'first_failure_at' => $now - 240, 'last_failure_at' => $now], $this->row($this->keys[0]));
        $this->assertSame(5, $this->row($this->keys[1])['failures'] ?? null);
        $this->assertTrue(LoginThrottle::isLocked($this->keys, $now + 899));
        $this->assertTrue(LoginThrottle::isLocked([$this->keys[1]], $now + 899), 'the address alone');
        $this->assertFalse(LoginThrottle::isLocked($this->keys, $now + 900));
    }

    public function testAFailureOutsideTheWindowStartsAgain(): void
    {
        $now = time();

        LoginThrottle::recordFailure($this->keys, $now - 1000);
        LoginThrottle::recordFailure($this->keys, $now - 950);
        LoginThrottle::recordFailure($this->keys, $now);

        $this->assertSame(['failures' => 1, 'first_failure_at' => $now, 'last_failure_at' => $now], $this->row($this->keys[0]));
    }

    public function testARefusalOutlivesItsWindow(): void
    {
        $now = time();

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($this->keys, $now - 1000 + $i * 60);
        }

        // The window has passed since the first failure, the lockout not since the last.
        LoginThrottle::recordFailure(LoginThrottle::keys('owa-test-other-' . bin2hex(random_bytes(4)), ''), $now);

        $this->assertTrue(LoginThrottle::isLocked($this->keys, $now));
    }

    /** Five attempts in one second, none settled yet: the sixth is refused, and taken back. */
    public function testTheSixthAttemptInTheSameSecondIsRefused(): void
    {
        $now = time();

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(LoginThrottle::attempt($this->keys, $now), "attempt $i is checked");
        }

        $this->assertFalse(LoginThrottle::attempt($this->keys, $now));
        $this->assertSame(5, $this->row($this->keys[0])['failures'] ?? null);
        $this->assertSame(5, $this->row($this->keys[1])['failures'] ?? null);
    }

    public function testASucceededAttemptClearsTheNameAndIsTakenBackFromTheAddress(): void
    {
        LoginThrottle::recordFailure($this->keys);
        $this->assertTrue(LoginThrottle::attempt($this->keys));

        LoginThrottle::succeeded($this->keys[0], $this->keys[1]);

        $this->assertNull($this->row($this->keys[0]));
        $this->assertSame(1, $this->row($this->keys[1])['failures'] ?? null);
    }

    public function testClearForgetsTheName(): void
    {
        LoginThrottle::recordFailure($this->keys);
        LoginThrottle::clear([$this->keys[0]]);

        $this->assertNull($this->row($this->keys[0]));
        $this->assertSame(1, $this->row($this->keys[1])['failures'] ?? null);
    }
}
