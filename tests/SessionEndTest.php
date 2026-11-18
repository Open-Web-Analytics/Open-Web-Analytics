<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Core\Auth;

/**
 * Signing out and changing the password move the user's session_epoch, so a
 * session cookie issued before stops signing anyone in, on every device.
 *
 * Against the user store: needs owa_user.session_epoch (Update087).
 */
final class SessionEndTest extends RestControllerTestCase
{
    /** @var array the state stores before the test */
    private $stores = [];

    protected function setUp(): void
    {
        parent::setUp();

        $table = \OWA\Core\CoreAPI::entityFactory('base.user')->getTableName();

        if (!\OWA\Core\CoreAPI::dbSingleton()->get_results("SHOW COLUMNS FROM $table LIKE 'session_epoch'")) {
            $this->markTestSkipped("$table has no session_epoch: this database has not had Update087");
        }

        $this->stores = (array) \OWA\Core\CoreAPI::serviceSingleton()->request->state->stores;
        $this->resetAuth();
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->state->stores = $this->stores;
        $this->resetAuth();
        $this->resetCurrentUser();

        parent::tearDown();
    }

    /** The one Auth per process, as it is before a request authenticates. */
    private function resetAuth(): void
    {
        $auth = Auth::get_instance();

        foreach (['auth_method' => null, '_is_user' => false] as $name => $value) {
            (new \ReflectionProperty($auth, $name))->setValue($auth, $value);
        }
    }

    private function row(string $user_id): \OWA\Module\Base\Entity\User
    {
        $user = new \OWA\Module\Base\Entity\User();
        $user->load($user_id, 'user_id');

        return $user;
    }

    private function credential(string $user_id): string
    {
        $user = $this->row($user_id);

        return Auth::sessionCredential($user_id, $user->get('session_epoch'), $user->get('password'),
            time() + Auth::SESSION_LIFETIME);
    }

    private function isValid(string $credential, string $user_id): bool
    {
        $user = $this->row($user_id);

        return Auth::isValidSessionCredential($credential, $user_id, $user->get('session_epoch'), $user->get('password'));
    }

    /** A request carrying the session cookies, authenticated as a screen would be. */
    private function signInByCookies(string $user_id, string $credential): bool
    {
        $this->resetAuth();
        $this->resetCurrentUser();

        $state = \OWA\Core\CoreAPI::serviceSingleton()->request->state;
        $state->stores['u'] = $user_id;
        $state->stores['p'] = $credential;

        return (bool) Auth::get_instance()->authenticateUser(['cookies'])['auth_status'];
    }

    /** Runs base.logout's action with these params and answers its data. */
    private function logout(array $params = []): array
    {
        $controller = new \OWA\Module\Base\Controller\Logout($params);
        $controller->action();

        return (array) $controller->data;
    }

    /** The nonce in the first href or form of $html that runs base.logout. */
    private static function logoutNonce(string $html): ?string
    {
        if (preg_match('/href="[^"]*do=base\.logout[^"]*?nonce=([a-z0-9]+)/i', $html, $m)) {
            return $m[1];
        }

        if (preg_match('/value="base\.logout">\s*<input type="hidden" name="nonce" value="([a-z0-9]+)"/i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    public function testANewUserIsAtEpochZero(): void
    {
        $user = $this->makeUser('viewer');

        $this->assertSame(0, (int) $this->row($user['user_id'])->get('session_epoch'));
        $this->assertTrue($this->signInByCookies($user['user_id'], $this->credential($user['user_id'])));
    }

    public function testSigningOutEndsEverySessionOfTheUser(): void
    {
        $user   = $this->makeUser('viewer');
        $id     = $user['user_id'];
        $here   = $this->credential($id);
        $other  = $this->credential($id);
        $before = (int) $this->row($id)->get('session_epoch');

        $this->assertTrue($this->signInByCookies($id, $here));
        $this->assertSame('cookies', Auth::get_instance()->getAuthMethod());

        $data = $this->logout(['nonce' => \OWA\Core\CoreAPI::createNonce('base.logout')]);

        $this->assertSame('redirect', $data['view_method'] ?? null);
        $this->assertSame('base.loginForm', $data['do'] ?? null);
        $this->assertSame($before + 1, (int) $this->row($id)->get('session_epoch'));
        $this->assertFalse($this->isValid($here, $id));
        $this->assertFalse($this->isValid($other, $id), 'the session on another device');
        $this->assertFalse($this->signInByCookies($id, $here));
        $this->assertTrue($this->signInByCookies($id, $this->credential($id)), 'a new sign-in');
    }

    public function testSigningOutWithACookieThatNoLongerHoldsChangesNothing(): void
    {
        $user   = $this->makeUser('viewer');
        $id     = $user['user_id'];
        $before = (int) $this->row($id)->get('session_epoch');

        $this->assertFalse($this->signInByCookies($id, (time() - 1) . '.' . str_repeat('a', 64)));

        $data = $this->logout(['nonce' => 'anything']);

        $this->assertSame('base.loginForm', $data['do'] ?? null, 'nobody signed in goes to the login form');
        $this->assertArrayNotHasKey('view', $data);
        $this->assertSame($before, (int) $this->row($id)->get('session_epoch'));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function withoutANonce(): array
    {
        return [
            'no nonce'                  => [[]],
            'a wrong nonce'             => [['nonce' => 'abcdef0123']],
            'another action\'s nonce'   => [['nonce' => '{base.myProfileSave}']],
        ];
    }

    /**
     * A link from anywhere but OWA's own Log out: asked first, still signed in.
     *
     * @dataProvider withoutANonce
     * @param array<string,mixed> $params
     */
    public function testWithoutAValidNonceTheUserIsAskedAndStaysSignedIn(array $params): void
    {
        $user   = $this->makeUser('viewer');
        $id     = $user['user_id'];
        $issued = $this->credential($id);
        $before = (int) $this->row($id)->get('session_epoch');

        $this->assertTrue($this->signInByCookies($id, $issued));

        if (($params['nonce'] ?? '') === '{base.myProfileSave}') {
            $params['nonce'] = \OWA\Core\CoreAPI::createNonce('base.myProfileSave');
        }

        $data = $this->logout($params);

        $this->assertSame('base.logoutConfirm', $data['view'] ?? null);
        $this->assertNotSame('redirect', $data['view_method'] ?? null);
        $this->assertTrue(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());
        $this->assertSame($before, (int) $this->row($id)->get('session_epoch'));
        $this->assertTrue($this->isValid($issued, $id));
    }

    public function testTheConfirmPageAsksAndItsButtonSignsOut(): void
    {
        $user   = $this->makeUser('viewer');
        $id     = $user['user_id'];
        $issued = $this->credential($id);
        $before = (int) $this->row($id)->get('session_epoch');

        $this->assertTrue($this->signInByCookies($id, $issued));

        $html = (string) \OWA\Core\CoreAPI::displayView($this->logout());

        $this->assertStringContainsString(\OWA\Core\CoreAPI::t('Do you want to log out?'), $html);
        $this->assertStringContainsString('value="' . \OWA\Core\CoreAPI::t('Log out') . '"', $html);
        $this->assertStringContainsString(\OWA\Core\CoreAPI::t('Stay signed in'), $html, 'the way back');

        $nonce = self::logoutNonce($html);
        $this->assertNotNull($nonce, 'the form carries a nonce');

        $data = $this->logout(['action' => 'base.logout', 'nonce' => $nonce]);

        $this->assertSame('base.loginForm', $data['do'] ?? null);
        $this->assertSame($before + 1, (int) $this->row($id)->get('session_epoch'));
        $this->assertFalse($this->isValid($issued, $id));
    }

    public function testTheLogOutLinkInTheUserMenuCarriesANonce(): void
    {
        $user = $this->makeUser('viewer');
        $id   = $user['user_id'];

        $this->assertTrue($this->signInByCookies($id, $this->credential($id)));

        $t = new \OWA\Core\Template();
        $t->set_template('header.php');
        $html = (string) $t->fetch();

        $this->assertStringContainsString('owa_userMenuLogout', $html, 'precondition: the menu is drawn');
        $this->assertSame(\OWA\Core\CoreAPI::createNonce('base.logout'), self::logoutNonce($html));
    }

    /** The profile screen, the emailed reset and the CLI all set a password through updateUserPassword(). */
    public function testANewPasswordEndsEverySessionOfTheUser(): void
    {
        $user   = $this->makeUser('viewer');
        $id     = $user['user_id'];
        $issued = $this->credential($id);
        $before = (int) $this->row($id)->get('session_epoch');

        $this->assertTrue($this->signInByCookies($id, $issued));

        $manager = \OWA\Core\CoreAPI::supportClassFactory('base', 'userManager');
        $this->assertNotFalse($manager->updateUserPassword(['user_id' => $id, 'password' => 'a new password ' . $this->tok]));

        $this->assertSame($before + 1, (int) $this->row($id)->get('session_epoch'));
        $this->assertFalse($this->isValid($issued, $id));
        $this->assertFalse($this->signInByCookies($id, $issued));
    }
}
