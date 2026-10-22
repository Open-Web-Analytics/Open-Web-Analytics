<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Module\Base\Classes\AppPasswords;

/**
 * The application password screens: the signed-in user's own under My
 * Preferences, and the revoke-only list on an admin's user screen.
 *
 * Drives each controller's action() as the signed-in user. The nonce and
 * capability checks happen in doAction(), so those are asserted on the
 * controllers' declarations.
 */
final class AppPasswordScreensTest extends RestControllerTestCase
{
    /** @var string[] users whose passwords to delete */
    private $users = array();

    protected function tearDown(): void
    {
        foreach ($this->users as $user_id) {
            AppPasswords::revokeAllForUser($user_id);
        }

        parent::tearDown();
    }

    private function signIn(string $role = 'viewer'): array
    {
        $user = $this->authenticateAs($role);
        $this->users[] = $user['user_id'];

        return $user;
    }

    private function other(): array
    {
        $user = $this->makeUser('analyst', 'other');
        $this->users[] = $user['user_id'];

        return $user;
    }

    private function data($controller): array
    {
        $property = new \ReflectionProperty(\OWA\Core\Controller::class, 'data');
        $property->setAccessible(true);

        return (array) $property->getValue($controller);
    }

    private function run_(string $class, array $params = array()): array
    {
        $controller = new $class($params);
        $controller->action();

        return $this->data($controller);
    }

    private function render(string $template, array $vars): string
    {
        $t = new \OWA\Core\Template('base');

        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template($template));

        return $t->fetch();
    }

    public function testTheListShowsOnlyTheSignedInUsersPasswords(): void
    {
        $me    = $this->signIn();
        $other = $this->other();

        AppPasswords::create($me['user_id'], 'Mine', AppPasswords::SCOPE_READ);
        AppPasswords::create($other['user_id'], 'Theirs', AppPasswords::SCOPE_FULL);

        $data = $this->run_(\OWA\Module\Base\Controller\MyAppPasswords::class);

        $this->assertSame(array('Mine'), array_column($data['app_passwords'], 'name'));
        $this->assertSame('base.myAppPasswords', $data['subview']);
        $this->assertArrayNotHasKey('secret_hash', $data['app_passwords'][0]);
    }

    /**
     * Creating redirects; the list shows the token once.
     *
     * Rendering the token in the POST's own response meant a reload re-sent
     * the POST and made a second password. The token crosses the redirect in
     * a sealed cookie, not the URL, and the first GET expires it.
     */
    public function testCreatingRedirectsAndTheListShowsTheTokenOnce(): void
    {
        $me = $this->signIn();

        $create = $this->capturing(\OWA\Module\Base\Controller\MyAppPasswordCreate::class,
            array('name' => 'Export script', 'scope' => 'read'));
        $create->action();
        $data = $this->data($create);

        $this->assertSame('redirect', $data['view_method']);
        $this->assertSame('base.myAppPasswords', $data['do']);
        $this->assertCount(1, $create->cookies);
        $this->assertGreaterThan(time(), $create->cookies[0][1]);

        $made = AppPasswords::openReveal($create->cookies[0][0], $me['user_id']);
        $this->assertTrue(AppPasswords::looksLikeToken($made['token']));
        $this->assertStringNotContainsString(substr($made['token'], AppPasswords::LOOKUP_LENGTH),
            json_encode($data), 'The token rode in the redirect URL.');

        $name = \OWA\Core\CoreAPI::getSetting('base', 'ns') . 'app_password_reveal';
        $_COOKIE[$name] = $create->cookies[0][0];

        $list = $this->capturing(\OWA\Module\Base\Controller\MyAppPasswords::class);
        $list->action();
        $shown = $this->data($list);

        $this->assertSame($made['token'], $shown['new_app_password']['token']);
        $this->assertSame(array('Export script'), array_column($shown['app_passwords'], 'name'),
            'The list under the token already includes it.');
        $this->assertLessThan(time(), $list->cookies[0][1], 'The first GET did not expire the cookie.');

        $row = AppPasswords::verify($made['token']);
        $this->assertSame($me['user_id'], $row['user_id']);
        $this->assertSame('read', $row['scope']);

        // A reload: no token, and still one password.
        $again = $this->run_(\OWA\Module\Base\Controller\MyAppPasswords::class);
        $this->assertNull($again['new_app_password']);
        $this->assertCount(1, $again['app_passwords']);
    }

    /** A sealed token is shown only to its user, and not once revoked. */
    public function testTheListShowsNoTokenItShouldNot(): void
    {
        $me    = $this->signIn();
        $other = $this->other();
        $name  = \OWA\Core\CoreAPI::getSetting('base', 'ns') . 'app_password_reveal';

        $theirs = AppPasswords::create($other['user_id'], 'Theirs', AppPasswords::SCOPE_FULL);
        $_COOKIE[$name] = AppPasswords::sealReveal($theirs, $other['user_id']);
        $this->assertNull($this->run_(\OWA\Module\Base\Controller\MyAppPasswords::class)['new_app_password']);

        $mine = AppPasswords::create($me['user_id'], 'Mine', AppPasswords::SCOPE_FULL);
        AppPasswords::revoke($mine['lookup_id'], $me['user_id']);
        $_COOKIE[$name] = AppPasswords::sealReveal($mine, $me['user_id']);
        $this->assertNull($this->run_(\OWA\Module\Base\Controller\MyAppPasswords::class)['new_app_password']);

        $this->assertArrayNotHasKey($name, $_COOKIE);
    }

    /** The create or list controller, with its cookie writes recorded instead of sent. */
    private function capturing(string $class, array $params = array())
    {
        if ($class === \OWA\Module\Base\Controller\MyAppPasswordCreate::class) {

            return new class($params) extends \OWA\Module\Base\Controller\MyAppPasswordCreate {
                public $cookies = array();

                protected function writeRevealCookie($value, $expires)
                {
                    $this->cookies[] = array($value, $expires);
                }
            };
        }

        return new class($params) extends \OWA\Module\Base\Controller\MyAppPasswords {
            public $cookies = array();

            protected function writeRevealCookie($value, $expires)
            {
                $this->cookies[] = array($value, $expires);
            }
        };
    }

    public function testANameIsRequired(): void
    {
        $me = $this->signIn();

        $data = $this->run_(\OWA\Module\Base\Controller\MyAppPasswordCreate::class,
            array('name' => '   ', 'scope' => 'read'));

        $this->assertSame('redirect', $data['view_method']);
        $this->assertSame('base.myAppPasswords', $data['do']);
        $this->assertSame(3215, $data['error_code']);
        $this->assertSame(array(), AppPasswords::forUser($me['user_id']));
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        $me = $this->signIn();

        $data = $this->run_(\OWA\Module\Base\Controller\MyAppPasswordCreate::class,
            array('name' => 'Admin script', 'scope' => 'admin'));

        $this->assertSame(3216, $data['error_code']);
        $this->assertSame(array(), AppPasswords::forUser($me['user_id']));
    }

    public function testRevokingYourOwn(): void
    {
        $me   = $this->signIn();
        $made = AppPasswords::create($me['user_id'], 'Old', AppPasswords::SCOPE_FULL);

        $data = $this->run_(\OWA\Module\Base\Controller\MyAppPasswordRevoke::class,
            array('lookup_id' => $made['lookup_id']));

        $this->assertSame(3213, $data['status_code']);
        $this->assertSame('base.myAppPasswords', $data['do']);
        $this->assertNull(AppPasswords::verify($made['token']));
    }

    /** The user comes from the session; naming someone else's id does nothing. */
    public function testYouCannotRevokeSomeoneElsesFromYourScreen(): void
    {
        $this->signIn();
        $other  = $this->other();
        $theirs = AppPasswords::create($other['user_id'], 'Theirs', AppPasswords::SCOPE_FULL);

        $data = $this->run_(\OWA\Module\Base\Controller\MyAppPasswordRevoke::class,
            array('lookup_id' => $theirs['lookup_id'], 'user_id' => $other['user_id']));

        $this->assertSame(3214, $data['error_code']);
        $this->assertNotNull(AppPasswords::verify($theirs['token']));
    }

    public function testAnAdminRevokesAnotherUsersPassword(): void
    {
        $this->signIn('admin');
        $other  = $this->other();
        $theirs = AppPasswords::create($other['user_id'], 'Theirs', AppPasswords::SCOPE_FULL);

        $data = $this->run_(\OWA\Module\Base\Controller\UserAppPasswordRevoke::class,
            array('lookup_id' => $theirs['lookup_id'], 'user_id' => $other['user_id']));

        $this->assertSame(3213, $data['status_code']);
        $this->assertSame('base.usersProfile', $data['do']);
        $this->assertSame($other['user_id'], $data['user_id'], 'Back to that user\'s screen.');
        $this->assertNull(AppPasswords::verify($theirs['token']));
    }

    public function testAnAdminRevokeMustNameTheOwner(): void
    {
        $this->signIn('admin');
        $other  = $this->other();
        $theirs = AppPasswords::create($other['user_id'], 'Theirs', AppPasswords::SCOPE_FULL);
        $third  = $this->makeUser('viewer', 'third');

        $data = $this->run_(\OWA\Module\Base\Controller\UserAppPasswordRevoke::class,
            array('lookup_id' => $theirs['lookup_id'], 'user_id' => $third['user_id']));

        $this->assertSame(3214, $data['error_code']);
        $this->assertNotNull(AppPasswords::verify($theirs['token']));
    }

    /** @dataProvider controllers */
    public function testEachControllerIsGatedAndNonced(string $class, string $capability, bool $nonce): void
    {
        $controller = new $class(array());

        $this->assertSame($capability, $controller->getRequiredCapability());
        $this->assertSame($nonce, (bool) $controller->is_nonce_required);
    }

    public static function controllers(): array
    {
        return array(
            'the list'     => array(\OWA\Module\Base\Controller\MyAppPasswords::class, 'view_site_list', false),
            'create'       => array(\OWA\Module\Base\Controller\MyAppPasswordCreate::class, 'view_site_list', true),
            'revoke own'   => array(\OWA\Module\Base\Controller\MyAppPasswordRevoke::class, 'view_site_list', true),
            'admin revoke' => array(\OWA\Module\Base\Controller\UserAppPasswordRevoke::class, 'edit_users', true),
        );
    }

    public function testEveryActionIsRegistered(): void
    {
        $actions = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap('actions');

        foreach (array('base.myAppPasswords', 'base.myAppPasswordCreate',
                       'base.myAppPasswordRevoke', 'base.userAppPasswordRevoke') as $action) {

            $this->assertArrayHasKey($action, $actions);
            $this->assertTrue(class_exists($actions[$action]['class_name']), $action);
        }
    }

    public function testTheScreenIsInMyPreferences(): void
    {
        $controller = new \OWA\Module\Base\Controller\MyAppPasswords(array());

        $method = new \ReflectionMethod(\OWA\Core\Controller::class, 'getHierarchyNav');
        $method->setAccessible(true);

        $nav = (array) $method->invoke($controller, '');

        $this->assertContains('base.myAppPasswords', array_column($nav['My Preferences'], 'do'));
    }

    /** @dataProvider templates */
    public function testEveryFormIsNonced(string $template): void
    {
        $markup = file_get_contents(__DIR__ . '/../modules/Base/templates/' . $template);

        $this->assertSame(
            substr_count($markup, '<form method="post"'),
            substr_count($markup, 'createNonceFormField('));
    }

    public static function templates(): array
    {
        return array('my screen' => array('my_app_passwords.php'));
    }

    public function testTheScreenDrawsTheNewTokenOnce(): void
    {
        $vars = array(
            'app_passwords'    => array(array('lookup_id' => 'abcdefghijkl', 'name' => 'Export script',
                'scope' => 'read', 'created_at' => time(), 'last_used_at' => null, 'last_used_ip' => null)),
            'scopes'           => AppPasswords::scopes(),
            'enabled'          => true,
            'require_https'    => true,
            'api_url'          => 'https://owa.example.com/api/index.php',
            'new_app_password' => array('name' => 'Export script', 'token' => str_repeat('T', 44)),
            'siteId'           => '',
        );

        $html = $this->render('my_app_passwords.php', $vars);

        $this->assertSame(1, substr_count($html, str_repeat('T', 44)));
        $this->assertStringContainsString('name="lookup_id" value="abcdefghijkl"', $html);
        $this->assertStringContainsString('Never', $html);

        $vars['new_app_password'] = null;
        $this->assertStringNotContainsString(str_repeat('T', 44), $this->render('my_app_passwords.php', $vars));
    }

    public function testTheScreenSaysWhenTheyAreTurnedOff(): void
    {
        $html = $this->render('my_app_passwords.php', array(
            'app_passwords' => array(), 'scopes' => AppPasswords::scopes(), 'enabled' => false,
            'require_https' => false, 'api_url' => '', 'new_app_password' => null, 'siteId' => '',
        ));

        $this->assertStringContainsString('turned off on this', $html);
    }

    /** The admin sees names and last use, with a revoke button, and no secret. */
    public function testTheUserScreenListsAnotherUsersPasswords(): void
    {
        $other = $this->other();
        AppPasswords::create($other['user_id'], 'Their script', AppPasswords::SCOPE_FULL);

        $profile = \OWA\Core\CoreAPI::entityFactory('base.user');
        $profile->load($other['id'], 'id');

        $view = new \OWA\Module\Base\View\UsersProfile();
        $view->body = new \OWA\Core\Template('base');
        $view->t    = new \OWA\Core\Template('base');
        $view->data = array('profile' => $profile->_getProperties(), 'edit' => true);
        $view->render(array());

        $html = $view->body->fetch();

        $this->assertStringContainsString('Their script', $html);
        $this->assertStringContainsString('base.userAppPasswordRevoke', $html);
        $this->assertStringContainsString('Read and write', $html);
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $html, 'A hash is on the page.');
    }

    /** user_id is the username: a password left behind would sign in its next holder. */
    public function testDeletingAUserDeletesTheirPasswords(): void
    {
        $other = $this->other();
        $made  = AppPasswords::create($other['user_id'], 'Theirs', AppPasswords::SCOPE_FULL);

        \OWA\Core\CoreAPI::supportClassFactory('base', 'userManager')->deleteUser($other['user_id']);

        $this->assertSame(array(), AppPasswords::forUser($other['user_id']));
        $this->assertNull(AppPasswords::verify($made['token']));
    }

    public function testTheSettingsAreOnTheMainConfigurationPage(): void
    {
        $sets = array_column(
            \OWA\Module\Base\Classes\SettingsForm::pageFieldSets('base.optionsGeneral'), null, 'id');

        $this->assertSame(array('app_passwords_enabled', 'app_passwords_require_https'),
            $sets['base.restApi']['settings'] ?? null);
    }
}
