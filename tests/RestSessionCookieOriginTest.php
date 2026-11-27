<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Core\Auth;

/**
 * The REST API signs a request in by the session cookies only when it is
 * same-origin with OWA (RequestOrigin::isSameOrigin()): no Origin or OWA's
 * own, and no Sec-Fetch-Site or `same-origin`. Any other request is judged
 * as one that carried no session, and its cookies are left in place. An
 * application password is read whatever the origin.
 */
final class RestSessionCookieOriginTest extends RestControllerTestCase
{
    private const OWN = 'https://owa.example.com';

    /** @var array<string, mixed> what a test changed, to put back */
    private $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;

        $this->saved = [
            'server' => $request->server,
            'stores' => $request->state->stores,
            'url'    => \OWA\Core\CoreAPI::getSetting('base', 'public_url'),
            'method' => Auth::get_instance()->getAuthMethod(),
        ];

        \OWA\Core\CoreAPI::setSetting('base', 'public_url', self::OWN . '/');
    }

    protected function tearDown(): void
    {
        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $request->server = $this->saved['server'];
        $request->state->stores = $this->saved['stores'];
        \OWA\Core\CoreAPI::setSetting('base', 'public_url', $this->saved['url']);
        $this->resetAuth($this->saved['method']);

        parent::tearDown();
    }

    private function resetAuth($method = null): void
    {
        $auth = Auth::get_instance();

        foreach (['auth_method' => $method, '_is_user' => false] as $name => $value) {
            (new \ReflectionProperty($auth, $name))->setValue($auth, $value);
        }
    }

    /** A signed-in user's session cookies, valid now. */
    private function session(): array
    {
        $user = $this->makeUser('admin', 'cookieorigin');
        $row  = new \OWA\Module\Base\Entity\User();
        $row->load($user['user_id'], 'user_id');

        return [$user['user_id'], Auth::sessionCredential($user['user_id'], $row->get('session_epoch'),
            $row->get('password'), time() + Auth::SESSION_LIFETIME)];
    }

    /** Authenticate a REST request carrying these cookies and server variables. */
    private function authenticate(array $cookies, array $server): bool
    {
        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $request->server = array_merge((array) $this->saved['server'], $server);

        foreach ($cookies as $name => $value) {
            $request->state->stores[$name] = $value;
        }

        $this->resetAuth('');
        $this->resetCurrentUser();

        return (bool) Auth::get_instance()->authenticateUser(['overlay_token', 'app_password', 'cookies'])['auth_status'];
    }

    public function testACrossOriginRequestIsNotSignedInByItsSession(): void
    {
        [$user_id, $credential] = $this->session();

        $this->assertFalse($this->authenticate(['u' => $user_id, 'p' => $credential],
            ['HTTP_ORIGIN' => 'https://shop.example.com', 'HTTP_SEC_FETCH_SITE' => 'same-site']));
        $this->assertTrue(Auth::get_instance()->refusedCrossOriginCookies());
        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());
    }

    public function testCrossSiteFetchMetadataWithNoOriginIsNotSignedIn(): void
    {
        [$user_id, $credential] = $this->session();

        $this->assertFalse($this->authenticate(['u' => $user_id, 'p' => $credential], ['HTTP_SEC_FETCH_SITE' => 'cross-site']));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function sameOrigin(): array
    {
        return [
            'no Origin'               => [[]],
            'OWA\'s own Origin'       => [['HTTP_ORIGIN' => self::OWN, 'HTTP_SEC_FETCH_SITE' => 'same-origin']],
            'same-origin, no Origin'  => [['HTTP_SEC_FETCH_SITE' => 'same-origin']],
        ];
    }

    /** @dataProvider sameOrigin */
    public function testASameOriginRequestIsSignedInByItsSession(array $server): void
    {
        [$user_id, $credential] = $this->session();

        $this->assertTrue($this->authenticate(['u' => $user_id, 'p' => $credential], $server));
        $this->assertSame('cookies', Auth::get_instance()->getAuthMethod());
        $this->assertSame($user_id, (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData('user_id'));
    }

    public function testAnAppPasswordFromAnotherOriginStillSignsIn(): void
    {
        $user = $this->makeUser('admin', 'cookieoriginapp');
        $made = \OWA\Module\Base\Classes\AppPasswords::create($user['user_id'], 'Cross-origin client',
            \OWA\Module\Base\Classes\AppPasswords::SCOPE_FULL);

        try {
            $_SERVER['HTTPS'] = 'on';

            $this->assertTrue($this->authenticate([], [
                'HTTPS'               => 'on',
                'HTTP_ORIGIN'         => 'https://shop.example.com',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
                'HTTP_AUTHORIZATION'  => 'Bearer ' . $made['token'],
            ]));
            $this->assertSame('app_password', Auth::get_instance()->getAuthMethod());
        } finally {
            unset($_SERVER['HTTPS']);
            \OWA\Module\Base\Classes\AppPasswords::revokeAllForUser($user['user_id']);
        }
    }

    /** A refused session is not cleared: the request did not end it. */
    public function testTheCookiesOfARefusedRequestAreKept(): void
    {
        [$user_id, $credential] = $this->session();

        $controller = new class([]) extends \OWA\Core\Controller {
            public function __construct($params)
            {
                parent::__construct($params);
                $this->setRequiredCapability('view_reports');
                $this->setAuthMethods(['cookies']);
            }

            public function action() {}

            public function data(): array
            {
                return $this->data;
            }
        };

        $this->authenticate(['u' => $user_id, 'p' => $credential], ['HTTP_ORIGIN' => 'https://shop.example.com']);
        $this->resetAuth('');
        $this->resetCurrentUser();

        $status = http_response_code() ?: 200;

        try {
            $controller->doAction();

            $this->assertSame('base.restApi', $controller->data()['view'] ?? null);
            $this->assertSame(401, http_response_code());
            $this->assertSame($credential, \OWA\Core\CoreAPI::serviceSingleton()->request->state->stores['p'] ?? null);
        } finally {
            http_response_code($status);
        }
    }
}
