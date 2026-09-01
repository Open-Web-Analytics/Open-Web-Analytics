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

        // The referring page decides whether a way back is offered, so each test
        // states its own rather than inheriting a previous one's.
        unset($_SERVER['HTTP_REFERER']);

        // getReferringPage() resolves against this; without a matching host every
        // referrer below would read as foreign and the link would never appear.
        \OWA\Core\CoreAPI::setSetting('base', 'public_url', 'https://owa.example.test/owa/');
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

        /*
         * ReportingHome stands in for "any ordinary controller". It used to be
         * base.sites, the roster screen, which was retired when reporting
         * started landing on the last Profile viewed -- nothing here depends on
         * which controller it is, only that it is one.
         */
        $controller = new \OWA\Module\Base\Controller\ReportingHome([]);
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
            '/(expired|no longer valid)/',
            $msg,
            'the message should explain that the form lapsed'
        );
    }

    /**
     * "Start the action again" is only actionable if the way back is offered.
     * The referring page is the screen that rendered the expired form.
     */
    public function testTheWayBackIsOffered()
    {
        $_SERVER['HTTP_REFERER'] = 'https://owa.example.test/owa/index.php?owa_do=base.optionsGeneral';

        $data = $this->respondTo(true);

        $this->assertStringContainsString(
            'owa_do=base.optionsGeneral',
            (string) ($data['error_msg'] ?? ''),
            'the screen the form came from should be linked'
        );
    }

    /** The referrer reaches an href, so it cannot be trusted as markup. */
    public function testTheWayBackIsEscaped()
    {
        $_SERVER['HTTP_REFERER'] =
            'https://owa.example.test/owa/index.php?owa_do=base.reportingHome&x="><script>alert(1)</script>';

        $msg = (string) ($this->respondTo(true)['error_msg'] ?? '');

        $this->assertStringNotContainsString('<script>', $msg, 'markup must not survive into the page');
        $this->assertStringNotContainsString('"><', $msg, 'the attribute must not be breakable');
    }

    /** Nothing to link to when the referrer is unusable; the message still stands. */
    public function testTheMessageStandsAloneWithoutAReferrer()
    {
        unset($_SERVER['HTTP_REFERER']);

        $msg = (string) ($this->respondTo(true)['error_msg'] ?? '');

        $this->assertNotSame('', $msg, 'the explanation is still required');
        $this->assertStringNotContainsString('<a href', $msg, 'no link when there is nowhere to send them');
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
