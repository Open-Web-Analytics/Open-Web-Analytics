<?php

use PHPUnit\Framework\TestCase;

/**
 * A state-changing action is not resumed across a login.
 *
 * notAuthenticatedAction() put the whole current URL into 'go' so the request
 * could be replayed after login. For a nonce-guarded action that cannot work:
 * createNonce() mixes in the current user_id, so a nonce minted while logged out
 * never verifies once logged in. The replay failed the nonce check, which routes
 * back to the same handler, and the user saw the login form again -- indis-
 * tinguishable from having typed the wrong password.
 *
 * Re-minting the nonce after login would be worse than the bug: a crafted link
 * could name any action and have the server issue a valid nonce for it.
 *
 * So 'go' is set only for actions that are not nonce-guarded.
 */
final class LoginRedirectNonceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * get_current_url() builds 'go' from the request, which does not exist under
     * CLI -- without this the redirect is empty for every case and the assertions
     * below would pass for the wrong reason.
     */
    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST']   = 'owa.example.test';
        $_SERVER['REQUEST_URI'] = '/owa/index.php?owa_do=base.sitesDelete&owa_nonce=abc123';

        // The referring page is now a candidate destination, so each test states
        // its own. Leaving a previous test's value in place would decide the
        // outcome here instead of the case under test.
        unset($_SERVER['HTTP_REFERER']);

        // resolveRedirectUrl() compares against this, so the fixture host has to
        // be the installation's own or every referrer below reads as foreign.
        \OWA\Core\CoreAPI::setSetting('base', 'public_url', 'https://owa.example.test/owa/');

        // notAuthenticatedAction() answers a REST request with a 401 body and
        // never sets 'go' at all. request_mode is global, so a REST test running
        // earlier in the suite would otherwise send these down that branch and
        // make the assertions meaningless.
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'admin_web');
    }

    /** The value queued for resumption after login, decoded. */
    private function goValue(bool $nonceRequired): string
    {
        $controller = new \OWA\Module\Base\Controller\Sites([]);

        if ($nonceRequired) {
            $controller->setNonceRequired();
        }

        $controller->notAuthenticatedAction();

        $prop = new ReflectionProperty($controller, 'data');
        $prop->setAccessible(true);
        $data = (array) $prop->getValue($controller);

        return urldecode((string) ($data['go'] ?? ''));
    }

    /**
     * A state-changing action is not resumed, but the page that offered it is --
     * that screen renders rather than writes, and will mint a nonce for the
     * authenticated identity.
     */
    public function testTheReferringPageIsResumedInsteadOfTheAction()
    {
        $_SERVER['HTTP_REFERER'] = 'https://owa.example.test/owa/index.php?owa_do=base.sites';

        $go = $this->goValue(true);

        $this->assertStringStartsWith(
            $_SERVER['HTTP_REFERER'],
            $go,
            'the screen the action was offered from should be resumed'
        );

        // Landing there with no explanation leaves the outcome ambiguous -- for
        // a delete especially, the user cannot tell whether it went through.
        $this->assertStringContainsString(
            'status_code=2006',
            $go,
            'the destination should carry the notice that nothing was carried out'
        );

        $this->assertStringNotContainsString(
            'sitesDelete',
            $go,
            'the action itself must never be queued for replay'
        );
    }

    /** The referrer is client-supplied, so a foreign one is not a destination. */
    public function testAnOffsiteReferrerIsDiscarded()
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.com/somewhere';

        $this->assertSame('', $this->goValue(true), 'an offsite referrer must not be resumed');
    }

    /** Resuming the blocked request itself would just fail the same check again. */
    public function testAReferrerPointingAtTheCurrentRequestIsDiscarded()
    {
        $_SERVER['HTTP_REFERER'] = \OWA\Core\Lib::get_current_url();

        $this->assertSame('', $this->goValue(true), 'the blocked request must not resume itself');
    }

    /** Nothing to resume when the browser sent no referrer at all. */
    public function testAMissingReferrerLeavesNothingToResume()
    {
        unset($_SERVER['HTTP_REFERER']);

        $this->assertSame('', $this->goValue(true));
    }

    /**
     * Arriving from the login screen means the previous request was already
     * turned away. Resuming it would return the user to the form they just
     * completed -- the same dead end the whole change exists to remove.
     */
    public function testAReferrerFromTheLoginScreenIsDiscarded()
    {
        foreach (
            [
                'https://owa.example.test/owa/index.php?owa_do=base.loginForm',
                'https://owa.example.test/owa/index.php?owa_do=base.login&owa_go=x',
            ] as $referer
        ) {
            $_SERVER['HTTP_REFERER'] = $referer;

            $this->assertSame(
                '',
                $this->goValue(true),
                sprintf('%s should not be resumed after login', $referer)
            );
        }
    }

    /** Run notAuthenticatedAction() on a controller and report whether 'go' was set. */
    private function setsGo(bool $nonceRequired): bool
    {
        $controller = new \OWA\Module\Base\Controller\Sites([]);

        if ($nonceRequired) {
            $controller->setNonceRequired();
        }

        $controller->notAuthenticatedAction();

        // set() writes to the controller's data (what the view is handed);
        // get() reads request params. Asserting via get() would report NULL for
        // every case and pass this whole file for the wrong reason.
        $prop = new ReflectionProperty($controller, 'data');
        $prop->setAccessible(true);
        $data = (array) $prop->getValue($controller);

        return isset($data['go']) && $data['go'] !== '';
    }

    /** The reported failure: the replayed request could never have verified. */
    public function testAStateChangingActionIsNotResumedAfterLogin()
    {
        $this->assertFalse(
            $this->setsGo(true),
            'a nonce-guarded action must not be replayed after login'
        );
    }

    /** Deep-linking to a report while logged out must still work. */
    public function testAReadOnlyActionIsStillResumed()
    {
        $this->assertTrue(
            $this->setsGo(false),
            'a read-only destination should still be resumed after login'
        );
    }

    /**
     * The behaviour keys off is_nonce_required, so that flag has to keep meaning
     * "state-changing write". Every controller setting it should be one, and no
     * report or view controller should.
     */
    public function testOnlyWriteControllersRequireANonce()
    {
        $withNonce = [];

        foreach (glob(__DIR__ . '/../modules/*/Controller/*.php') as $file) {
            if (strpos((string) file_get_contents($file), 'setNonceRequired') !== false) {
                $withNonce[] = basename($file, '.php');
            }
        }

        $this->assertNotEmpty($withNonce, 'no nonce-guarded controllers found at all');

        foreach ($withNonce as $name) {
            $this->assertMatchesRegularExpression(
                '/(Add|Edit|Delete|Update|Apply|Activate|Deactivate|Install)/',
                $name,
                sprintf(
                    '%s requires a nonce but does not look like a write. If it is read-only '
                    . 'it will silently lose its post-login redirect.',
                    $name
                )
            );
        }
    }

    /** No report/view controller may require a nonce, or it loses 'go' silently. */
    public function testNoReportControllerRequiresANonce()
    {
        foreach (glob(__DIR__ . '/../modules/*/Controller/*.php') as $file) {
            $name = basename($file, '.php');

            if (!preg_match('/(Report|Dashboard|View)/', $name)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'setNonceRequired',
                (string) file_get_contents($file),
                sprintf('%s is a read-only screen and must not require a nonce', $name)
            );
        }
    }
}
