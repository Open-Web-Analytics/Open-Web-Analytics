<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Module\Base\Classes\AppPasswords;

/**
 * A REST request authenticates by the application password in its
 * Authorization header, and by nothing else when that password does not hold.
 *
 * Drives Auth::authenticateUser() with the request's own server variables, as
 * a real request would set them.
 */
final class AuthAppPasswordTest extends RestControllerTestCase
{
    /** @var array the request's server variables before the test */
    private $server;

    /** @var string */
    private $method;

    /** @var array settings a test changed, to put back */
    private $settings = array();

    /** @var array the users whose passwords to delete */
    private $users = array();

    protected function setUp(): void
    {
        parent::setUp();

        $request      = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $this->server = $request->server;
        $this->method = $request->request_type;
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        $request               = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $request->server       = $this->server;
        $request->request_type = $this->method;
        $request->setParam('apiKey', '');
        unset($_SERVER['HTTPS']);

        foreach ($this->settings as $name => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $name, $value);
        }

        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'rest_api');

        foreach ($this->users as $user_id) {
            AppPasswords::revokeAllForUser($user_id);
        }

        parent::tearDown();
    }

    private function setting(string $name, $value): void
    {
        if (!array_key_exists($name, $this->settings)) {
            $this->settings[$name] = \OWA\Core\CoreAPI::getSetting('base', $name);
        }

        \OWA\Core\CoreAPI::setSetting('base', $name, $value);
    }

    /** A user and one of their passwords. */
    private function userWithPassword(string $scope = AppPasswords::SCOPE_FULL): array
    {
        $user = $this->makeUser('admin', 'apppw');
        $this->users[] = $user['user_id'];

        $made = AppPasswords::create($user['user_id'], 'Test client', $scope);
        $this->assertIsArray($made);

        return array($user, $made);
    }

    /**
     * Authenticate a request with these server variables.
     *
     * @return array auth_status, method, user_id
     */
    private function authenticate(array $server, string $method = 'GET'): array
    {
        $request               = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $request->server       = array_merge($this->server, $server);
        $request->request_type = $method;

        $this->resetCurrentUser();

        // One Auth per process; a request with no credential never sets the method.
        $auth = \OWA\Core\Auth::get_instance();
        $prop = new \ReflectionProperty($auth, 'auth_method');
        $prop->setAccessible(true);
        $prop->setValue($auth, '');

        $status = $auth->authenticateUser();
        $cu     = \OWA\Core\CoreAPI::getCurrentUser();

        return array(
            'auth_status' => (bool) $status['auth_status'],
            'method'      => $auth->getAuthMethod(),
            'user_id'     => $cu->isAuthenticated() ? (string) $cu->getUserData('user_id') : '',
        );
    }

    private static function bearer(string $token): array
    {
        return array('HTTP_AUTHORIZATION' => 'Bearer ' . $token);
    }

    private static function basic(string $user, string $token): array
    {
        return array('HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($user . ':' . $token));
    }

    public function testABearerTokenAuthenticatesAsItsUser(): void
    {
        list($user, $made) = $this->userWithPassword();

        $result = $this->authenticate(self::bearer($made['token']));

        $this->assertTrue($result['auth_status']);
        $this->assertSame('app_password', $result['method']);
        $this->assertSame($user['user_id'], $result['user_id']);
    }

    public function testBasicAuthNeedsThePasswordsOwnUser(): void
    {
        list($user, $made) = $this->userWithPassword();
        $other = $this->makeUser('admin', 'other');

        $this->assertTrue($this->authenticate(self::basic($user['user_id'], $made['token']))['auth_status']);
        $this->assertFalse($this->authenticate(self::basic($other['user_id'], $made['token']))['auth_status'],
            'A password authenticated a request that named another user.');
    }

    /** A credential that was named and did not hold is not retried as anything else. */
    public function testAFailedPasswordDoesNotFallThroughToTheApiKey(): void
    {
        list($user, $made) = $this->userWithPassword();
        $wrong = substr($made['token'], 0, 12) . str_repeat('x', 32);

        \OWA\Core\CoreAPI::serviceSingleton()->request->setParam('apiKey', $user['api_key']);

        $result = $this->authenticate(self::bearer($wrong));

        $this->assertFalse($result['auth_status']);
        $this->assertSame('app_password', $result['method']);
        $this->assertSame('', $result['user_id']);
    }

    /** A site behind HTTP Basic auth sends its own password; that is not ours. */
    public function testABasicPasswordThatIsNotATokenIsLeftAlone(): void
    {
        $user = $this->makeUser('admin', 'htpasswd');

        \OWA\Core\CoreAPI::serviceSingleton()->request->setParam('apiKey', $user['api_key']);

        $result = $this->authenticate(self::basic('staging', 'the site password'));

        $this->assertTrue($result['auth_status'], 'A site password blocked the apiKey.');
        $this->assertSame('api_key', $result['method']);
    }

    public function testAReadOnlyPasswordAuthenticatesOnlyAGet(): void
    {
        list(, $made) = $this->userWithPassword(AppPasswords::SCOPE_READ);

        $this->assertTrue($this->authenticate(self::bearer($made['token']), 'GET')['auth_status']);
        $this->assertTrue($this->authenticate(self::bearer($made['token']), 'HEAD')['auth_status']);

        foreach (array('POST', 'PUT', 'DELETE') as $method) {
            $this->assertFalse($this->authenticate(self::bearer($made['token']), $method)['auth_status'], $method);
        }
    }

    public function testARevokedPasswordFails(): void
    {
        list($user, $made) = $this->userWithPassword();
        AppPasswords::revoke($made['lookup_id'], $user['user_id']);

        $this->assertFalse($this->authenticate(self::bearer($made['token']))['auth_status']);
    }

    public function testADeletedUsersPasswordFails(): void
    {
        list($user, $made) = $this->userWithPassword();
        \OWA\Core\CoreAPI::entityFactory('base.user')->delete($user['id'], 'id');

        $this->assertFalse($this->authenticate(self::bearer($made['token']))['auth_status']);
    }

    public function testTurningThemOffRefusesEveryOne(): void
    {
        list(, $made) = $this->userWithPassword();
        $this->setting('app_passwords_enabled', false);

        $this->assertFalse($this->authenticate(self::bearer($made['token']))['auth_status']);
    }

    public function testPlainHttpIsRefusedUnlessAllowed(): void
    {
        list(, $made) = $this->userWithPassword();
        unset($_SERVER['HTTPS']);

        $this->assertFalse($this->authenticate(self::bearer($made['token']))['auth_status']);

        $this->setting('app_passwords_require_https', false);

        $this->assertTrue($this->authenticate(self::bearer($made['token']))['auth_status']);
    }

    /** The web screens are cookie sessions; a password is for the REST API. */
    public function testOutsideTheRestApiTheHeaderIsIgnored(): void
    {
        list(, $made) = $this->userWithPassword();
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'web_app');

        $result = $this->authenticate(self::bearer($made['token']));

        $this->assertFalse($result['auth_status']);
        $this->assertNotSame('app_password', $result['method']);
    }

    /** Each proxy appends, so the left end of the chain is the client's to choose. */
    public function testUseIsRecordedFromTheNearestPublicHop(): void
    {
        list($user, $made) = $this->userWithPassword();

        $this->authenticate(self::bearer($made['token']) + array(
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 203.0.113.5, 10.0.0.2',
        ));

        $row = AppPasswords::forUser($user['user_id'])[0];

        $this->assertSame('203.0.113.5', $row['last_used_ip']);
        $this->assertNotEmpty($row['last_used_at']);
    }
}
