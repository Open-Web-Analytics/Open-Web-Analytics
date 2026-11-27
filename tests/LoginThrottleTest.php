<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Auth;
use OWA\Module\Base\Classes\LoginThrottle;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Failed sign-ins are counted per user name and per client address: after
 * 5 within 15 minutes, sign-ins by that name or from that address are
 * refused until 15 minutes after the last failure, with the answer a wrong
 * password gets. A successful sign-in clears the name's count.
 *
 * No database: the rows are kept in LoginThrottle::$rows. The table itself is
 * LoginThrottleStorageTest's.
 */
final class LoginThrottleTest extends TestCase
{
    const USER = 'owa-test-throttle';

    /** @var array<string, mixed>|null */
    private $server;

    protected function setUp(): void
    {
        LoginThrottle::$enabled = true;
        LoginThrottle::$rows    = [];

        $this->server = \OWA\Core\CoreAPI::serviceSingleton()->request->server;
        $this->from('198.51.100.20');
        $this->signOut();
    }

    protected function tearDown(): void
    {
        LoginThrottle::$enabled = false;
        LoginThrottle::$rows    = null;

        \OWA\Core\CoreAPI::serviceSingleton()->request->server = $this->server;
        $this->signOut();
    }

    private function from(string $address): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->server = ['REMOTE_ADDR' => $address] + (array) $this->server;
    }

    private function signOut(): void
    {
        $anon = new \OWA\Module\Base\Entity\User();
        $anon->set('user_id', '');
        $anon->set('role', 'everyone');

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $cu->loadNewUserByObject($anon);
        (new \ReflectionProperty($cu, 'is_authenticated'))->setValue($cu, false);
    }

    /** An Auth that reads its user from memory, writes no cookies and records the hashes it checks against. */
    private function auth(string $user_id = self::USER, string $password = 'the right password'): Auth
    {
        $user = new \OWA\Module\Base\Entity\User();
        $user->set('user_id', $user_id);
        $user->set('role', 'viewer');
        $user->set('password', password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
        $user->set('session_epoch', 0);

        return new class($user) extends Auth {

            public $checked = [];

            private $stub;

            public function __construct($stub)
            {
                parent::__construct();
                $this->stub = $stub;
            }

            function getUser()
            {
                $this->u = $this->stub;
            }

            protected function passwordMatches($typed, $stored)
            {
                $this->checked[] = $stored;

                return parent::passwordMatches($typed, $stored);
            }

            protected function emitCookie($name, $value, $expires, $secure, $httponly)
            {
            }
        };
    }

    private function failSignIns(int $times, string $user_id = self::USER): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->assertFalse($this->auth()->authByInput($user_id, 'a wrong password'));
            $this->signOut();
        }
    }

    public function testTheLimitsAreFiveFailuresInFifteenMinutes(): void
    {
        $this->assertSame(5, LoginThrottle::MAX_FAILURES);
        $this->assertSame(900, LoginThrottle::WINDOW);
        $this->assertSame(900, LoginThrottle::LOCKOUT);
    }

    public function testFourFailuresStillLetTheRightPasswordIn(): void
    {
        $this->failSignIns(4);

        $this->assertTrue($this->auth()->authByInput(self::USER, 'the right password'));
    }

    public function testAfterFiveFailuresTheRightPasswordIsRefused(): void
    {
        $this->failSignIns(5);

        $auth = $this->auth();

        $this->assertFalse($auth->authByInput(self::USER, 'the right password'));
        $this->assertSame([Auth::NO_USER_HASH], $auth->checked,
            'checked against the no-user hash only: the time a wrong password takes, and never the real one');
        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());
    }

    public function testARefusedAttemptIsNotCounted(): void
    {
        $this->failSignIns(5);
        $this->assertFalse($this->auth()->authByInput(self::USER, 'the right password'));

        $this->assertSame(5, LoginThrottle::$rows[LoginThrottle::nameKey(self::USER)]['failures']);
    }

    public function testTheNameIsRefusedFromAnotherAddress(): void
    {
        $this->failSignIns(5);
        $this->from('203.0.113.77');

        $this->assertFalse($this->auth()->authByInput(self::USER, 'the right password'));
    }

    public function testTheNameIsCountedWhateverItsCase(): void
    {
        $this->failSignIns(5, strtoupper(self::USER));

        $this->assertFalse($this->auth()->authByInput(self::USER, 'the right password'));
    }

    public function testTheAddressIsRefusedForAnotherName(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->failSignIns(1, 'owa-test-throttle-' . $i);
        }

        $this->assertFalse($this->auth('owa-test-other')->authByInput('owa-test-other', 'the right password'));

        $this->from('203.0.113.77');
        $this->assertTrue($this->auth('owa-test-other')->authByInput('owa-test-other', 'the right password'),
            'from another address the other name signs in');
    }

    /** The address is the client's, behind a proxy: X-Forwarded-For's last public hop. */
    public function testTheAddressIsTheClientBehindAProxy(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->server =
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99'] + (array) $this->server;

        $this->failSignIns(1, 'owa-test-a');

        $this->assertArrayHasKey(LoginThrottle::addressKey('198.51.100.99'), LoginThrottle::$rows);
        $this->assertArrayNotHasKey(LoginThrottle::addressKey('127.0.0.1'), LoginThrottle::$rows);
    }

    public function testASuccessfulSignInClearsTheNameButNotTheAddress(): void
    {
        $this->failSignIns(4);

        $this->assertTrue($this->auth()->authByInput(self::USER, 'the right password'));
        $this->signOut();

        $this->assertArrayNotHasKey(LoginThrottle::nameKey(self::USER), LoginThrottle::$rows);
        $this->assertSame(4, LoginThrottle::$rows[LoginThrottle::addressKey('198.51.100.20')]['failures'] ?? null);
    }

    public function testNothingIsStoredInTheClear(): void
    {
        $this->failSignIns(1);

        foreach (array_keys(LoginThrottle::$rows) as $key) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        }
    }

    // ------------------------------------------------------------------
    // The rule, by the clock
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function row(int $failures, int $first, int $last): array
    {
        return ['failures' => $failures, 'first_failure_at' => $first, 'last_failure_at' => $last];
    }

    public function testTheRefusalEndsFifteenMinutesAfterTheLastFailure(): void
    {
        $t   = 1000000;
        $row = $this->row(5, $t, $t + 600);

        $this->assertTrue(LoginThrottle::isLockedRow($row, $t + 600 + 899));
        $this->assertFalse(LoginThrottle::isLockedRow($row, $t + 600 + 900));
    }

    public function testFailuresOutsideTheWindowStartAgain(): void
    {
        $t = 1000000;

        $this->assertSame($this->row(5, $t, $t + 899), LoginThrottle::afterFailure($this->row(4, $t, $t + 800), $t + 899),
            'the fifth within 15 minutes of the first');
        $this->assertSame($this->row(1, $t + 900, $t + 900), LoginThrottle::afterFailure($this->row(4, $t, $t + 800), $t + 900),
            'fifteen minutes after the first, counting starts again');
    }

    public function testAnEndedRefusalCountsAgainFromOne(): void
    {
        $t = 1000000;

        $this->assertSame($this->row(1, $t + 2000, $t + 2000),
            LoginThrottle::afterFailure($this->row(5, $t, $t + 100), $t + 2000));
    }

    public function testRecordingOnTheClock(): void
    {
        $keys = LoginThrottle::keys('owa-test-clock', '198.51.100.30');
        $t    = 1000000;

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($keys, $t + $i * 60);
        }

        $this->assertTrue(LoginThrottle::isLocked($keys, $t + 240 + 899));
        $this->assertFalse(LoginThrottle::isLocked($keys, $t + 240 + 900));
        $this->assertTrue(LoginThrottle::isLocked([$keys[0]], $t + 300), 'the name alone');
        $this->assertTrue(LoginThrottle::isLocked([$keys[1]], $t + 300), 'the address alone');

        LoginThrottle::recordFailure(LoginThrottle::keys('owa-test-later', ''), $t + 5000);
        $this->assertArrayNotHasKey($keys[0], LoginThrottle::$rows, 'rows that no longer count are deleted');
    }

    public function testKeys(): void
    {
        $this->assertSame([], LoginThrottle::keys('', ''));
        $this->assertSame([LoginThrottle::nameKey('alice')], LoginThrottle::keys(' Alice ', ''));
        $this->assertSame([LoginThrottle::addressKey('203.0.113.1')], LoginThrottle::keys(null, '203.0.113.1'));
        $this->assertNotSame(LoginThrottle::nameKey('203.0.113.1'), LoginThrottle::addressKey('203.0.113.1'));
    }

    public function testTurnedOffNothingIsCountedOrRefused(): void
    {
        LoginThrottle::$enabled = false;

        $keys = LoginThrottle::keys('owa-test-off', '198.51.100.31');

        for ($i = 0; $i < 6; $i++) {
            LoginThrottle::recordFailure($keys);
        }

        $this->assertSame([], LoginThrottle::$rows);
        $this->assertFalse(LoginThrottle::isLocked($keys));
    }

    // ------------------------------------------------------------------
    // Application passwords: the address
    // ------------------------------------------------------------------

    public function testAnApplicationPasswordIsRefusedFromARefusedAddress(): void
    {
        $was = [
            'enabled' => \OWA\Core\CoreAPI::getSetting('base', 'app_passwords_enabled'),
            'https'   => \OWA\Core\CoreAPI::getSetting('base', 'app_passwords_require_https'),
        ];

        \OWA\Core\CoreAPI::setSetting('base', 'app_passwords_enabled', true);
        \OWA\Core\CoreAPI::setSetting('base', 'app_passwords_require_https', false);

        try {
            for ($i = 0; $i < 5; $i++) {
                LoginThrottle::recordFailure(LoginThrottle::keys('', '198.51.100.20'));
            }

            $this->assertFalse($this->auth()->authByAppPassword(str_repeat('a', 44)));
            $this->assertSame(5, LoginThrottle::$rows[LoginThrottle::addressKey('198.51.100.20')]['failures'],
                'refused before the token is looked up: a token not recognised would count a sixth');
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'app_passwords_enabled', $was['enabled']);
            \OWA\Core\CoreAPI::setSetting('base', 'app_passwords_require_https', $was['https']);
        }
    }
}
