<?php

use PHPUnit\Framework\TestCase;

/**
 * A nonce failure is answered on its own terms, not as a missing session.
 *
 * Both conditions used to reach notAuthenticatedAction(), so someone already
 * signed in was shown the login form. That is untrue and unactionable -- they
 * re-enter credentials that were never the problem -- and it is why the report
 * on #979 read as an authentication failure rather than an expired token.
 *
 * A nonce carries a time window and the user_id it was minted for, so it lapses
 * for perfectly valid sessions: a form left open too long, or one rendered
 * before signing in as somebody else.
 */
final class NonceFailureResponseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST']   = 'owa.example.test';
        $_SERVER['REQUEST_URI'] = '/owa/index.php?owa_do=base.sitesDelete';

        // request_mode is global and decides which branch answers, so a REST
        // test running earlier would otherwise steer these.
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'admin_web');
    }

    private function respondTo(bool $authenticated): array
    {
        // setAuthStatus() assigns true regardless of its argument, so the flag
        // has to be set directly to express "not signed in" -- the same
        // workaround RestControllerTestCase uses. Setting it through the public
        // method would make both cases authenticated and the assertions below
        // would compare two identical responses.
        $currentUser = \OWA\Core\CoreAPI::getCurrentUser();
        $flag = new ReflectionProperty($currentUser, 'is_authenticated');
        $flag->setAccessible(true);
        $flag->setValue($currentUser, $authenticated);

        $controller = new \OWA\Module\Base\Controller\Sites([]);
        $controller->setNonceRequired();
        $controller->nonceFailedAction();

        $prop = new ReflectionProperty($controller, 'data');
        $prop->setAccessible(true);

        return (array) $prop->getValue($controller);
    }

    /** Signed in: the response must not be a request for credentials. */
    public function testAnAuthenticatedUserIsNotAskedToLogInAgain()
    {
        $data = $this->respondTo(true);

        $this->assertSame(
            'base.error',
            $data['view'] ?? null,
            'a signed-in user with a bad nonce should not be sent to the login form'
        );

        $this->assertArrayNotHasKey(
            'go',
            $data,
            'nothing should be queued for replay after a nonce failure'
        );
    }

    /** And the message has to say what actually happened. */
    public function testTheMessageDescribesAnExpiredForm()
    {
        $data = $this->respondTo(true);
        $msg  = strtolower(json_encode($data['error_msg'] ?? []));

        $this->assertStringNotContainsString(
            'password',
            $msg,
            'the message must not suggest the credentials were wrong'
        );

        $this->assertMatchesRegularExpression(
            '/(expired|no longer valid|signed in)/',
            $msg,
            'the message should explain that the form lapsed'
        );
    }

    /** Not signed in: the login form is still the right answer. */
    public function testAnUnauthenticatedUserIsStillSentToLogin()
    {
        $data = $this->respondTo(false);

        $this->assertNotSame(
            'base.error',
            $data['view'] ?? null,
            'someone with no session should still be asked to authenticate'
        );
    }

    /** The two conditions must not share a response. */
    public function testTheTwoConditionsAnswerDifferently()
    {
        $this->assertNotEquals(
            $this->respondTo(true),
            $this->respondTo(false),
            'a bad nonce and a missing session are different conditions'
        );
    }
}
