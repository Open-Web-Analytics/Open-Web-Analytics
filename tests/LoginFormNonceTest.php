<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * base.login takes a nonce made of a random value in a cookie of the
 * browser's own (Login::formNonce()), so a page elsewhere cannot post the
 * form; a failed one draws the form again with a fresh nonce. And a user name
 * that does not exist is checked against a hash all the same.
 */
final class LoginFormNonceTest extends TestCase
{
    private $cookies;

    protected function setUp(): void
    {
        $this->cookies = $_COOKIE;
        unset($_COOKIE[$this->cookieName()]);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
    }

    private function cookieName(): string
    {
        return \OWA\Core\CoreAPI::NS . \OWA\Module\Base\Controller\Login::FORM_COOKIE;
    }

    public function testTheFormsNonceVerifiesInTheBrowserThatGotIt(): void
    {
        $nonce = \OWA\Module\Base\Controller\Login::formNonce();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $_COOKIE[$this->cookieName()]);
        $this->assertTrue(\OWA\Module\Base\Controller\Login::formNonceIsValid($nonce));
        $this->assertSame($nonce, \OWA\Module\Base\Controller\Login::formNonce(), 'one value per browser');
    }

    public function testANonceOfTheWindowBeforeVerifies(): void
    {
        $tick = \OWA\Core\CoreAPI::getNonceTimeInterval();

        $this->assertTrue(\OWA\Module\Base\Controller\Login::formNonceIsValid(
            \OWA\Module\Base\Controller\Login::formNonce($tick - 1)));
        $this->assertFalse(\OWA\Module\Base\Controller\Login::formNonceIsValid(
            \OWA\Module\Base\Controller\Login::formNonce($tick - 2)));
    }

    public function testAnotherBrowsersNonceDoesNotVerify(): void
    {
        $theirs = \OWA\Module\Base\Controller\Login::formNonce();

        $_COOKIE[$this->cookieName()] = str_repeat('a', 32);
        $this->assertFalse(\OWA\Module\Base\Controller\Login::formNonceIsValid($theirs));

        unset($_COOKIE[$this->cookieName()]);
        $this->assertFalse(\OWA\Module\Base\Controller\Login::formNonceIsValid($theirs), 'no cookie');
        $this->assertFalse(\OWA\Module\Base\Controller\Login::formNonceIsValid(['x']));
    }

    /** The same for every visitor not signed in: what a page elsewhere could fetch. */
    public function testTheActionsOwnNonceDoesNotVerify(): void
    {
        \OWA\Module\Base\Controller\Login::formNonce();

        $this->assertFalse(\OWA\Module\Base\Controller\Login::formNonceIsValid(
            \OWA\Core\CoreAPI::createNonce('base.login')));
    }

    public function testAFormWithoutAValidNonceIsDrawnAgainAndSignsNobodyIn(): void
    {
        $login = new \OWA\Module\Base\Controller\Login([
            'user_id' => 'owatest-login-user', 'password' => 'x', 'nonce' => 'not-it', 'go' => '',
        ]);
        $login->action();

        $this->assertSame('base.loginForm', $login->data['view'] ?? null);
        $this->assertSame(2006, $login->data['error_code'] ?? null);
        $this->assertSame('owatest-login-user', $login->data['user_id'] ?? null);
        $this->assertNotSame('', (string) ($login->getMsg(2006)['message'] ?? ''));
        $this->assertNotSame('login_form', \OWA\Core\Auth::get_instance()->getAuthMethod());
    }

    /**
     * doAction() authenticates before action() whenever session cookies are
     * present: until action() has checked the nonce, only the session is
     * accepted, so a link carrying a user_id and password signs no signed-in
     * browser into another account.
     */
    public function testBeforeItsNonceIsCheckedOnlyTheSessionIsAccepted(): void
    {
        $login = new \OWA\Module\Base\Controller\Login([
            'user_id' => 'owatest-login-user', 'password' => 'x', 'nonce' => 'not-it', 'go' => '',
        ]);

        $this->assertSame(['cookies'], $login->acceptedAuthMethods());

        $login->action();

        $this->assertSame(['cookies'], $login->acceptedAuthMethods(), 'still, after a nonce that did not verify');
    }

    /** A password is never taken from a URL: a GET, even with a nonce that verifies, draws the form again. */
    public function testASignInByGetIsRefused(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $login = new \OWA\Module\Base\Controller\Login([
                'user_id' => 'owatest-login-user', 'password' => 'x', 'go' => '',
                'nonce' => \OWA\Module\Base\Controller\Login::formNonce(),
            ]);
            $login->action();

            $this->assertSame('base.loginForm', $login->data['view'] ?? null);
            $this->assertSame(2006, $login->data['error_code'] ?? null);
            $this->assertNotSame('login_form', \OWA\Core\Auth::get_instance()->getAuthMethod());
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    public function testTheFormCarriesTheNonce(): void
    {
        $view = \OWA\Core\CoreAPI::viewFactory('base.loginForm', []);
        $view->setData([]);
        $html = (string) $view->assembleView([]);

        $this->assertSame(1, preg_match('~name="nonce" value="([^"]+)"~', $html, $m), 'a nonce field');
        $this->assertTrue(\OWA\Module\Base\Controller\Login::formNonceIsValid(html_entity_decode($m[1])));
    }

    public function testAnUnknownUserNameIsCheckedAgainstAHash(): void
    {
        $this->assertSame('bcrypt', password_get_info(\OWA\Core\Auth::NO_USER_HASH)['algoName']);

        $auth = new class extends \OWA\Core\Auth {
            public $checked = [];

            public function __construct()
            {
            }

            public function getUser()
            {
                $this->u = \OWA\Core\CoreAPI::entityFactory('base.user');
            }

            protected function passwordMatches($typed, $stored)
            {
                $this->checked[] = $stored;

                return parent::passwordMatches($typed, $stored);
            }
        };

        $auth->credentials = ['user_id' => 'owatest-no-such-user', 'new_password' => 'a-password'];

        $this->assertFalse($auth->isUser());
        $this->assertSame([\OWA\Core\Auth::NO_USER_HASH], $auth->checked);
    }
}
