<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A request carrying ?pk= and a 'u' state value reached a 1.x login-by-URL-
 * passkey branch that called authenticateUserByUrlPasskey() with one argument
 * of two: an ArgumentCountError -- a 500 -- for anyone who sent one. Nothing
 * issued those links, so the branch is gone and such a request is simply not
 * authenticated.
 */
final class AuthUrlPasskeyRemovedTest extends TestCase
{
    public function testAPasskeyParamWithAUserStateIsNotAnErrorAndDoesNotAuthenticate(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('authentication reads the user store');
        }

        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated(), 'precondition: nobody signed in');

        // 'u' arrives as a cookie, which the request's state holds as a store.
        $state = \OWA\Core\CoreAPI::serviceSingleton()->request->state;
        $had   = array_key_exists('u', (array) $state->stores);
        $was   = $had ? $state->stores['u'] : null;

        \OWA\Core\CoreAPI::setRequestParam('pk', md5('alice'));
        $state->stores['u'] = 'alice';
        $this->assertSame('alice', \OWA\Core\CoreAPI::getStateParam('u'), 'precondition: the user state is set');

        try {
            $auth = \OWA\Core\Auth::get_instance();
            $this->assertFalse($auth->authenticateUser()['auth_status'], 'a URL passkey is not a credential');
        } finally {
            \OWA\Core\CoreAPI::setRequestParam('pk', null);
            if ($had) {
                $state->stores['u'] = $was;
            } else {
                unset($state->stores['u']);
            }
        }

        $this->assertFalse(method_exists(\OWA\Core\Auth::class, 'authenticateUserByUrlPasskey'));
    }
}
