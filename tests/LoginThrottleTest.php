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

    /** The limits these tests are written for: the shipped defaults. */
    const LIMITS = ['login_throttle_max_failures' => 5, 'login_throttle_window' => 900];

    /** @var array<string, mixed>|null */
    private $server;

    /** @var array<string, mixed> the limits before setUp() */
    private $limits = [];

    protected function setUp(): void
    {
        LoginThrottle::$enabled = true;
        LoginThrottle::$rows    = [];

        // Whatever the install's config says: a scratch install for the e2e
        // run sets OWA_LOGIN_THROTTLE_MAX_FAILURES to 0.
        foreach (self::LIMITS as $name => $value) {
            $this->limits[$name] = \OWA\Core\CoreAPI::getSetting('base', $name);
            \OWA\Core\CoreAPI::setSetting('base', $name, $value);
        }

        $this->server = \OWA\Core\CoreAPI::serviceSingleton()->request->server;
        $this->from('198.51.100.20');
        $this->signOut();
    }

    protected function tearDown(): void
    {
        LoginThrottle::$enabled = false;
        LoginThrottle::$rows    = null;

        foreach ($this->limits as $name => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $name, $value);
        }

        $this->limits = [];

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
        $defaults = \OWA\Module\Base\Classes\Settings::baseDefaults()['base'];
        $this->assertSame(5, $defaults['login_throttle_max_failures'], 'the setting\'s default');
        $this->assertSame(900, $defaults['login_throttle_window'], 'the setting\'s default');
        $this->assertSame(5, LoginThrottle::maxFailures(), 'read from the setting');
    }

    public function testTheLimitsAreSetByConfigConstants(): void
    {
        $this->assertSame('OWA_LOGIN_THROTTLE_MAX_FAILURES',
            \OWA\Module\Base\Classes\Settings::constantForSetting('base', 'login_throttle_max_failures'));
        $this->assertSame('OWA_LOGIN_THROTTLE_WINDOW',
            \OWA\Module\Base\Classes\Settings::constantForSetting('base', 'login_throttle_window'));
    }

    /** @param array<string, int> $settings */
    private function withSettings(array $settings, callable $fn): void
    {
        $was = [];

        foreach ($settings as $k => $v) {
            $was[$k] = \OWA\Core\CoreAPI::getSetting('base', $k);
            \OWA\Core\CoreAPI::setSetting('base', $k, $v);
        }

        try {
            $fn();
        } finally {
            foreach ($was as $k => $v) {
                \OWA\Core\CoreAPI::setSetting('base', $k, $v);
            }
        }
    }

    public function testARaisedLimitAndAShorterWindow(): void
    {
        $this->withSettings(['login_throttle_max_failures' => 7, 'login_throttle_window' => 60], function () {

            $keys = LoginThrottle::keys('owa-test-raised', '198.51.100.50');
            $t    = 1000000;

            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue(LoginThrottle::attempt($keys, $t), "attempt $i");
                LoginThrottle::failed($keys, $t);
            }

            $this->assertFalse(LoginThrottle::attempt($keys, $t + 59), 'the eighth, within 60 seconds of the last');
            $this->assertTrue(LoginThrottle::attempt($keys, $t + 60), '60 seconds after the last');
        });
    }

    public function testALimitOfZeroCountsAndRefusesNothing(): void
    {
        $this->withSettings(['login_throttle_max_failures' => 0], function () {

            $this->failSignIns(6);

            $this->assertSame([], LoginThrottle::$rows);
            $this->assertTrue($this->auth()->authByInput(self::USER, 'the right password'));
        });
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

    /** @param list<int> $times */
    private function failedAt(string $key, array $times): void
    {
        foreach ($times as $t) {
            $this->assertTrue(LoginThrottle::attempt([$key], $t));
            LoginThrottle::failed([$key], $t);
        }
    }

    public function testFailuresOutsideTheWindowStartAgain(): void
    {
        $t   = 1000000;
        $key = LoginThrottle::nameKey('owa-test-window');

        $this->failedAt($key, [$t, $t + 200, $t + 400, $t + 800, $t + 899]);
        $this->assertSame($this->row(5, $t, $t + 899), LoginThrottle::$rows[$key], 'the fifth within 15 minutes of the first');

        LoginThrottle::$rows = [];
        $this->failedAt($key, [$t, $t + 200, $t + 400, $t + 800, $t + 900]);
        $this->assertSame($this->row(1, $t + 900, $t + 900), LoginThrottle::$rows[$key],
            'fifteen minutes after the first, counting starts again');
    }

    public function testAnEndedRefusalCountsAgainFromOne(): void
    {
        $t   = 1000000;
        $key = LoginThrottle::nameKey('owa-test-ended');

        $this->failedAt($key, [$t, $t + 25, $t + 50, $t + 75, $t + 100]);
        $this->assertFalse(LoginThrottle::attempt([$key], $t + 999), 'refused until 15 minutes after the last');
        $this->failedAt($key, [$t + 2000]);

        $this->assertSame($this->row(1, $t + 2000, $t + 2000), LoginThrottle::$rows[$key]);
    }

    /**
     * Attempts made at once are each counted before any password is checked,
     * so a sixth is refused while the first five are still being checked.
     */
    public function testTheSixthAttemptInTheSameSecondIsRefused(): void
    {
        $t    = 1000000;
        $keys = LoginThrottle::keys('owa-test-burst', '198.51.100.40');

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(LoginThrottle::attempt($keys, $t), "attempt $i is checked");
        }

        $this->assertFalse(LoginThrottle::attempt($keys, $t), 'none of the five has failed yet');
        $this->assertFalse(LoginThrottle::attempt([$keys[1]], $t), 'the address alone');
        $this->assertSame(5, LoginThrottle::$rows[$keys[0]]['failures'], 'a refused attempt is taken back');
    }

    /** The same through the login form: five wrong passwords in one second, then the right one. */
    public function testTheRightPasswordIsRefusedAfterFiveFailuresInOneSecond(): void
    {
        $keys = LoginThrottle::keys(self::USER, '198.51.100.20');
        $t    = time();

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::attempt($keys, $t);
        }

        foreach ($keys as $key) {
            LoginThrottle::failed([$key], $t);
        }

        $this->assertFalse($this->auth()->authByInput(self::USER, 'the right password'));
    }

    public function testASuccessTakesItsAttemptBackFromTheAddress(): void
    {
        $keys = LoginThrottle::keys('owa-test-ok', '198.51.100.41');

        $this->assertTrue(LoginThrottle::attempt($keys));
        LoginThrottle::succeeded($keys[0], $keys[1]);

        $this->assertArrayNotHasKey($keys[0], LoginThrottle::$rows);
        $this->assertSame(0, LoginThrottle::$rows[$keys[1]]['failures']);
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
