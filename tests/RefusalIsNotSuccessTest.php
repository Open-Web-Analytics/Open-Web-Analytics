<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * A request the controller refused must not be reported as done.
 *
 * A missing or expired nonce answered through finishActionCall(), which calls
 * the controller's success() when the handler returns nothing. So opening
 * base.usersAdd as a plain link -- no nonce, no form data -- redirected to the
 * user list with "Success! User added", having added nobody. A cookie-signed
 * REST create without a nonce answered 201 in place of its 401.
 *
 * Driven through doAction(), where the nonce is checked. NonceFailureResponseTest
 * calls nonceFailedAction() directly, so it never saw what ran after it.
 */
final class RefusalIsNotSuccessTest extends RestControllerTestCase
{
    private $authMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authMethod = \OWA\Core\Auth::get_instance()->auth_method;
    }

    protected function tearDown(): void
    {
        \OWA\Core\Auth::get_instance()->auth_method = $this->authMethod;
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'rest_api');
        http_response_code(200);

        parent::tearDown();
    }

    private function data($controller): array
    {
        $property = new \ReflectionProperty(\OWA\Core\Controller::class, 'data');
        $property->setAccessible(true);

        return (array) $property->getValue($controller);
    }

    private function newUserParams(): array
    {
        return array(
            'user_id'       => 'refused-' . $this->tok,
            'email_address' => 'refused-' . $this->tok . '@owatest.example.com',
            'real_name'     => 'Refused ' . $this->tok,
            'role'          => 'viewer',
        );
    }

    /** @dataProvider webWrites */
    public function testAWebWriteWithoutANonceShowsTheRefusal(string $class, int $successCode): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'web_app');
        $this->authenticateAs('admin');

        $controller = new $class($this->newUserParams());
        $data = $controller->doAction();

        $this->assertSame('base.error', $data['view'] ?? null, 'the expired-form screen');
        $this->assertNotSame($successCode, $data['status_code'] ?? null, 'no success message');
        $this->assertArrayNotHasKey('redirect_action', $data);
        $this->assertSame($data, $this->data($controller));
    }

    public static function webWrites(): array
    {
        return array(
            'add a user'    => array(\OWA\Module\Base\Controller\UsersAdd::class, 3000),
            'delete a user' => array(\OWA\Module\Base\Controller\UsersDelete::class, 3004),
        );
    }

    public function testNoUserIsAddedWithoutANonce(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'web_app');
        $this->authenticateAs('admin');

        $params = $this->newUserParams();
        (new \OWA\Module\Base\Controller\UsersAdd($params))->doAction();

        $this->assertFalse($this->userExists($params['user_id']));
    }

    /** The control: with a valid nonce the same request adds the user and says so. */
    public function testWithANonceTheUserIsAdded(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'web_app');
        $this->authenticateAs('admin');

        $params = $this->newUserParams();
        $params['nonce'] = \OWA\Core\CoreAPI::createNonce('base.usersAdd');
        $params['do'] = 'base.usersAdd';

        $data = (new \OWA\Module\Base\Controller\UsersAdd($params))->doAction();

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->load($params['user_id'], 'user_id');
        $this->trackForCleanup('base.user', $u->get('id'), 'id');

        $this->assertSame(3000, $data['status_code'] ?? null);
        $this->assertNotEmpty($u->get('id'));
    }

    /** A cookie-signed REST create with no nonce is a 401, not a 201. */
    public function testACookieRestCreateWithoutANonceIsUnauthorised(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'rest_api');
        $this->authenticateAs('admin');
        \OWA\Core\Auth::get_instance()->auth_method = 'cookies';

        $params = $this->newUserParams();
        $data = (new \OWA\Module\Base\Controller\AddUserRest($params))->doAction();

        $this->assertSame(401, http_response_code());
        $this->assertSame('base.restApi', $data['view'] ?? null);
        $this->assertFalse($this->userExists($params['user_id']));
    }
}
