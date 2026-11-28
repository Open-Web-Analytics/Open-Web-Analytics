<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Auth;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The `p` session cookie: `<expiry>.<hmac>` over the user_id, the user's
 * session_epoch, the expiry and a fragment of the password hash. It expires,
 * slides forward when less than a day is left, and ends when the epoch or the
 * password moves.
 */
final class SessionCredentialTest extends TestCase
{
    const USER = 'owa-test-session';

    /** @var array */
    private $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_PORT']);
        $_SERVER['SERVER_PORT'] = 80;
        $this->signOut();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $this->signOut();
    }

    /** The current user is a process singleton: back to nobody. */
    private function signOut(): void
    {
        $anon = new \OWA\Module\Base\Entity\User();
        $anon->set('user_id', '');
        $anon->set('role', 'everyone');

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $cu->loadNewUserByObject($anon);
        (new \ReflectionProperty($cu, 'is_authenticated'))->setValue($cu, false);
    }

    private function user(int $epoch = 0, string $password = 'a password of my own'): \OWA\Module\Base\Entity\User
    {
        $user = new \OWA\Module\Base\Entity\User();
        $user->set('user_id', self::USER);
        $user->set('role', 'viewer');
        $user->set('password', \OWA\Core\Lib::encryptPassword($password));
        $user->set('session_epoch', $epoch);

        return $user;
    }

    private static function credentialFor(\OWA\Module\Base\Entity\User $user, int $expires): string
    {
        return Auth::sessionCredential($user->get('user_id'), $user->get('session_epoch'), $user->get('password'), $expires);
    }

    private static function valid(string $credential, \OWA\Module\Base\Entity\User $user, ?int $now = null): bool
    {
        return Auth::isValidSessionCredential($credential, $user->get('user_id'), $user->get('session_epoch'),
            $user->get('password'), $now);
    }

    /** An Auth that reads $user instead of the database and records the cookies it writes. */
    private function auth(\OWA\Module\Base\Entity\User $user): Auth
    {
        return new class($user) extends Auth {

            public $cookies = [];

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

            protected function emitCookie($name, $value, $expires, $secure, $httponly)
            {
                $this->cookies[$name] = compact('value', 'expires', 'secure', 'httponly');
            }
        };
    }

    public function testTheValueIsTheExpiryAndAnHmac(): void
    {
        $user       = $this->user();
        $expires    = time() + Auth::SESSION_LIFETIME;
        $credential = self::credentialFor($user, $expires);

        $this->assertMatchesRegularExpression('/^' . $expires . '\.[a-f0-9]{64}$/', $credential);
        $this->assertSame($expires, Auth::sessionCredentialExpiry($credential));
        $this->assertTrue(self::valid($credential, $user));
    }

    public function testTheLifetimeIsTwoDaysAndTheRefreshThresholdOne(): void
    {
        $this->assertSame(2 * 86400, Auth::SESSION_LIFETIME);
        $this->assertSame(86400, Auth::SESSION_REFRESH_BELOW);
    }

    public function testAnExpiredValueSignsNobodyIn(): void
    {
        $user       = $this->user();
        $now        = time();
        $credential = self::credentialFor($user, $now + 60);

        $this->assertTrue(self::valid($credential, $user, $now + 59));
        $this->assertFalse(self::valid($credential, $user, $now + 60));
        $this->assertFalse(self::valid($credential, $user, $now + 3600));
    }

    public function testATamperedExpirySignsNobodyIn(): void
    {
        $user       = $this->user();
        $now        = time();
        $credential = self::credentialFor($user, $now - 60);
        $hmac       = explode('.', $credential)[1];

        $this->assertFalse(self::valid($credential, $user, $now));
        $this->assertFalse(self::valid(($now + Auth::SESSION_LIFETIME) . '.' . $hmac, $user, $now));
    }

    public function testATamperedHmacSignsNobodyIn(): void
    {
        $user       = $this->user();
        $credential = self::credentialFor($user, time() + 3600);
        $last       = substr($credential, -1);
        $tampered   = substr($credential, 0, -1) . ($last === '0' ? '1' : '0');

        $this->assertTrue(self::valid($credential, $user));
        $this->assertFalse(self::valid($tampered, $user));
        $this->assertFalse(self::valid(strtoupper($credential), $user));
    }

    /** @return array<string,array{mixed}> */
    public static function notCredentials(): array
    {
        return [
            // what sign-in set before: an HMAC with no expiry
            'the old form'     => [hash_hmac('sha256', self::USER, 'key')],
            'no hmac'          => [(string) (time() + 3600)],
            'a short hmac'     => [(time() + 3600) . '.' . str_repeat('a', 63)],
            'a negative expiry' => ['-1.' . str_repeat('a', 64)],
            'empty'            => [''],
            'an array'         => [['a' => 'b']],
            'null'             => [null],
        ];
    }

    /**
     * @dataProvider notCredentials
     * @param mixed $value
     */
    public function testAValueNotOfTheFormSignsNobodyIn($value): void
    {
        $user = $this->user();

        $this->assertNull(Auth::sessionCredentialExpiry($value));
        $this->assertFalse(Auth::isValidSessionCredential($value, self::USER, 0, $user->get('password')));
        $this->assertFalse(Auth::sessionCredentialNeedsRefresh($value));
    }

    public function testAnotherUsersValueSignsNobodyIn(): void
    {
        $user       = $this->user();
        $credential = Auth::sessionCredential('someone-else', 0, $user->get('password'), time() + 3600);

        $this->assertFalse(self::valid($credential, $user));
    }

    public function testEndingTheSessionsEndsAnIssuedValue(): void
    {
        $user       = $this->user(4);
        $credential = self::credentialFor($user, time() + 3600);

        $this->assertTrue(self::valid($credential, $user));

        $user->endSessions();

        $this->assertSame(5, $user->get('session_epoch'));
        $this->assertFalse(self::valid($credential, $user));
    }

    public function testANewPasswordEndsAnIssuedValue(): void
    {
        $user       = $this->user();
        $credential = self::credentialFor($user, time() + 3600);

        $user->set('password', \OWA\Core\Lib::encryptPassword('another password'));

        $this->assertFalse(self::valid($credential, $user));
    }

    public function testAUserWithNoStoredEpochIsAtZero(): void
    {
        $user = $this->user();
        $user->set('session_epoch', null);

        $this->assertTrue(self::valid(Auth::sessionCredential(self::USER, 0, $user->get('password'), time() + 60), $user));
    }

    public function testSigningInWritesBothCookiesHttpOnly(): void
    {
        $user = $this->user(2);
        $auth = $this->auth($user);

        $this->assertTrue($auth->authByInput(self::USER, 'a password of my own'));

        $this->assertSame(self::USER, $auth->cookies['u']['value']);
        $this->assertTrue($auth->cookies['u']['httponly']);
        $this->assertTrue($auth->cookies['p']['httponly']);

        $p = $auth->cookies['p'];
        $this->assertEqualsWithDelta(time() + Auth::SESSION_LIFETIME, $p['expires'], 5);
        $this->assertSame($p['expires'], Auth::sessionCredentialExpiry($p['value']));
        $this->assertTrue(self::valid($p['value'], $user));
    }

    public function testAWrongPasswordWritesNoCookie(): void
    {
        $auth = $this->auth($this->user());

        $this->assertFalse($auth->authByInput(self::USER, 'not the password'));
        $this->assertSame([], $auth->cookies);
    }

    /** 1.x stored md5( lower( password ) . length ); the whole hash must match, not a fragment of it. */
    public function testAPasswordStoredBy1xMatchesOnlyInFull(): void
    {
        $user = $this->user();
        $user->set('password', \OWA\Core\Lib::encryptOldPassword('a 1.x password'));

        $this->assertTrue($this->auth($user)->authByInput(self::USER, 'a 1.x password'));
        $this->signOut();

        $user->set('password', substr(\OWA\Core\Lib::encryptOldPassword('other'), 0, 8)
            . substr(\OWA\Core\Lib::encryptOldPassword('a 1.x password'), 8, 4)
            . substr(\OWA\Core\Lib::encryptOldPassword('other'), 12));

        $this->assertFalse($this->auth($user)->authByInput(self::USER, 'a 1.x password'));
    }

    public function testTheCookieSignsInByItself(): void
    {
        $user = $this->user(3);
        $auth = $this->auth($user);

        $this->assertTrue($auth->authByCookies(self::USER, self::credentialFor($user, time() + Auth::SESSION_LIFETIME)));
        $this->assertTrue(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());
    }

    public function testACookieFromBeforeTheEpochMovedSignsNobodyIn(): void
    {
        $user       = $this->user(3);
        $credential = self::credentialFor($user, time() + Auth::SESSION_LIFETIME);
        $user->endSessions();

        $this->assertFalse($this->auth($user)->authByCookies(self::USER, $credential));
        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());
    }

    public function testACookieWithMoreThanADayLeftIsNotReissued(): void
    {
        $user = $this->user();
        $auth = $this->auth($user);

        $this->assertTrue($auth->authByCookies(self::USER,
            self::credentialFor($user, time() + Auth::SESSION_REFRESH_BELOW + 3600)));
        $this->assertSame([], $auth->cookies);
    }

    public function testACookieWithLessThanADayLeftIsReissued(): void
    {
        $user = $this->user();
        $auth = $this->auth($user);

        $this->assertTrue($auth->authByCookies(self::USER,
            self::credentialFor($user, time() + Auth::SESSION_REFRESH_BELOW - 3600)));

        $this->assertSame(['p'], array_keys($auth->cookies), 'p only: u is kept');

        $p = $auth->cookies['p'];
        $this->assertEqualsWithDelta(time() + Auth::SESSION_LIFETIME, $p['expires'], 5);
        $this->assertTrue(self::valid($p['value'], $user));
        $this->assertTrue($p['httponly']);
    }

    public function testTheRefreshThreshold(): void
    {
        $user = $this->user();
        $now  = time();

        $this->assertFalse(Auth::sessionCredentialNeedsRefresh(
            self::credentialFor($user, $now + Auth::SESSION_REFRESH_BELOW), $now));
        $this->assertTrue(Auth::sessionCredentialNeedsRefresh(
            self::credentialFor($user, $now + Auth::SESSION_REFRESH_BELOW - 1), $now));
    }

    public function testAnExpiredCookieIsNotReissued(): void
    {
        $user = $this->user();
        $auth = $this->auth($user);

        $this->assertFalse($auth->authByCookies(self::USER, self::credentialFor($user, time() - 1)));
        $this->assertSame([], $auth->cookies);
    }

    /** @return array<string,array{array<string,mixed>,bool}> */
    public static function requests(): array
    {
        return [
            'http'                     => [[], false],
            'https'                    => [['HTTPS' => 'on'], true],
            'https at a proxy'         => [['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '127.0.0.1'], true],
            'http at a proxy'          => [['HTTP_X_FORWARDED_PROTO' => 'http', 'REMOTE_ADDR' => '127.0.0.1'], false],
            'https said by a client'   => [['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '203.0.113.10'], false],
        ];
    }

    /**
     * Secure on the requests Lib::isHttps() calls HTTPS.
     *
     * @dataProvider requests
     * @param array<string,mixed> $server
     */
    public function testTheCookiesAreSecureOnAnHttpsRequest(array $server, bool $secure): void
    {
        foreach ($server as $name => $value) {
            $_SERVER[$name] = $value;
        }

        $user = $this->user();
        $auth = $this->auth($user);

        $this->assertTrue($auth->authByInput(self::USER, 'a password of my own'));
        $this->assertSame($secure, $auth->cookies['u']['secure']);
        $this->assertSame($secure, $auth->cookies['p']['secure']);
    }
}
