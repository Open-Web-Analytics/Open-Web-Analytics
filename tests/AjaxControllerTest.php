<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';
require_once __DIR__ . '/AjaxRequestTrait.php';

/**
 * Core\Controller\Ajax: an internal JSON endpoint for a signed-in browser.
 *
 * Every rule is the controller pipeline's own rather than written per
 * endpoint: POST only, cookies the only accepted credential (inherited from
 * AdminController), a nonce, and a JSON answer for every refusal.
 */
final class AjaxControllerTest extends RestControllerTestCase
{
    use AjaxRequestTrait;

    const ACTION = 'base.testAjax';

    protected function tearDown(): void
    {
        $this->signedInBy(null);
        \OWA\Core\CoreAPI::setRequestParam('apiKey', '');

        parent::tearDown();
    }

    /** A minimal endpoint: requires $capability and a name, answers 201. */
    private function endpoint(array $params = array(), string $capability = 'view_site_list')
    {
        $params += array('do' => self::ACTION, 'name' => 'Example');

        return new class($params, $capability) extends \OWA\Core\Controller\Ajax {

            public $ran = false;

            function __construct($params, $capability)
            {
                $this->setRequiredCapability($capability);
                parent::__construct($params);
            }

            function validate()
            {
                $this->addValidation('name', $this->getParam('name'), 'required',
                    array('errorMsg' => 'Give it a name.'));
            }

            function action()
            {
                $this->ran = true;

                return $this->answer(201, null, array('name' => $this->getParam('name')));
            }
        };
    }

    private function signedInSession(string $role = 'viewer'): array
    {
        $user = $this->authenticateAs($role);
        $this->signedInBy('cookies');

        return $user;
    }

    public function testAnswersInJson(): void
    {
        $this->signedInSession();
        $c = $this->endpoint(array('nonce' => self::nonceFor(self::ACTION)));

        $r = $this->ajax($c);

        $this->assertTrue($c->ran);
        $this->assertSame(201, $r['status']);
        $this->assertSame(array('data' => array('name' => 'Example'), 'error' => null), $r['json']);
    }

    public function testOnlyAPost(): void
    {
        $this->signedInSession();
        $c = $this->endpoint(array('nonce' => self::nonceFor(self::ACTION)));

        $r = $this->ajax($c, 'GET');

        $this->assertFalse($c->ran);
        $this->assertSame(405, $r['status']);
        $this->assertNull($r['json']['data']);
        $this->assertNotEmpty($r['json']['error']);
    }

    public function testSignedOutIs401NotARedirect(): void
    {
        $c = $this->endpoint(array('nonce' => 'whatever'));

        $r = $this->ajax($c);

        $this->assertFalse($c->ran);
        $this->assertSame(401, $r['status']);
        $this->assertNotSame('redirect', $r['data']['view_method'] ?? null);
        $this->assertNotEmpty($r['json']['error']);
    }

    /** @dataProvider otherMethods */
    public function testAUserSignedInByAnotherMethodIsRefused(string $method): void
    {
        $this->authenticateAs('admin');
        $this->signedInBy($method);
        $c = $this->endpoint(array('nonce' => self::nonceFor(self::ACTION)));

        $r = $this->ajax($c);

        $this->assertFalse($c->ran, $method);
        $this->assertSame(401, $r['status'], $method);
    }

    public static function otherMethods(): array
    {
        return array(
            'application password' => array('app_password'),
            'login form'           => array('login_form'),
            'overlay token'        => array('overlay_token'),
        );
    }

    /**
     * A legacy API key is refused on an endpoint that reads only the session,
     * as everywhere: 401, with what to use instead, and the endpoint does not
     * run.
     */
    public function testALegacyApiKeyIsRefusedWithItsReplacementNamed(): void
    {
        \OWA\Core\CoreAPI::setRequestParam('apiKey', str_repeat('a', 32));

        $this->resetCurrentUser();
        $this->signedInBy(null);
        $c = $this->endpoint(array('nonce' => 'x'));
        $r = $this->ajax($c);

        $this->assertSame(401, $r['status']);
        $this->assertFalse($c->ran);
        $this->assertSame(\OWA\Core\Controller::legacyApiKeyMessage(), $r['json']['error'] ?? null);
    }

    /** A login form's fields on a cookies-only endpoint are parameters, not a sign-in. */
    public function testLoginFormFieldsDoNotSignAnyoneIn(): void
    {
        $user = $this->makeUser('admin', 'form');
        \OWA\Core\CoreAPI::setRequestParam('user_id', $user['user_id']);
        \OWA\Core\CoreAPI::setRequestParam('password', 'irrelevant');

        try {
            $this->resetCurrentUser();
            $this->signedInBy(null);

            $status = \OWA\Core\Auth::get_instance()->authenticateUser(array('cookies'));

            $this->assertFalse($status['auth_status']);
            $this->assertNotSame('login_form', \OWA\Core\Auth::get_instance()->getAuthMethod());
        } finally {
            \OWA\Core\CoreAPI::setRequestParam('user_id', '');
            \OWA\Core\CoreAPI::setRequestParam('password', '');
        }
    }

    public function testABadNonceIs403(): void
    {
        $this->signedInSession();
        $c = $this->endpoint(array('nonce' => 'stale0000'));

        $r = $this->ajax($c);

        $this->assertFalse($c->ran);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('expired', $r['json']['error']);
    }

    public function testNotCapableIs403(): void
    {
        $this->signedInSession('viewer');
        $c = $this->endpoint(array('nonce' => self::nonceFor(self::ACTION)), 'edit_users');

        $r = $this->ajax($c);

        $this->assertFalse($c->ran);
        $this->assertSame(403, $r['status']);
        $this->assertNotEmpty($r['json']['error']);
    }

    /** The refusal the pipeline gives is the message alone. */
    public function testNotCapableIsTheMessageAlone(): void
    {
        $data = $this->endpoint()->authenticatedButNotCapableAction();

        $this->assertSame('Your account does not have access to this page.', $data['ajax_error']);
    }

    /** A caller's additional message follows it. */
    public function testNotCapableCarriesTheAdditionalMessage(): void
    {
        $data = $this->endpoint()->authenticatedButNotCapableAction('Profile: zz-probe');

        $this->assertSame('Your account does not have access to this page. Profile: zz-probe', $data['ajax_error']);
    }

    public function testInvalidInputIs422WithTheMessage(): void
    {
        $this->signedInSession();
        $c = $this->endpoint(array('nonce' => self::nonceFor(self::ACTION), 'name' => ''));

        $r = $this->ajax($c);

        $this->assertFalse($c->ran);
        $this->assertSame(422, $r['status']);
        $this->assertSame('Give it a name.', $r['json']['error']);
    }

    public function testPendingUpdatesAre503NotARedirect(): void
    {
        $c = $this->endpoint();

        $data = (array) $c->updateAction();

        $this->assertSame(503, http_response_code());
        $this->assertSame('base.ajax', $data['view']);
        $this->assertNotSame('redirect', $data['view_method'] ?? null);
    }

    /** The rule is AdminController's, inherited rather than written here. */
    public function testTheRuleIsInherited(): void
    {
        $c    = $this->endpoint();
        $mode = \OWA\Core\CoreAPI::getSetting('base', 'request_type');

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'ui');

        try {
            $this->assertInstanceOf(\OWA\Core\AdminController::class, $c);
            $this->assertSame(array('cookies'), $c->acceptedAuthMethods());
            $this->assertTrue((bool) $c->is_nonce_required);
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'request_type', $mode);
        }
    }
}
