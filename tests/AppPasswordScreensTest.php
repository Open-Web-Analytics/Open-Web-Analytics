<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';
require_once __DIR__ . '/AjaxRequestTrait.php';

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
    use AjaxRequestTrait;

    /** @var string[] users whose passwords to delete */
    private $users = array();

    protected function tearDown(): void
    {
        foreach ($this->users as $user_id) {
            AppPasswords::revokeAllForUser($user_id);
        }

        $this->signedInBy(null);

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

    /** The Add dialog's request, from a signed-in browser session. */
    private function create(array $params, bool $nonce = true): array
    {
        $this->signedInBy('cookies');

        $params += array('do' => 'base.myAppPasswordCreateAjax');

        if ($nonce) {
            $params['nonce'] = self::nonceFor('base.myAppPasswordCreateAjax');
        }

        return $this->ajax(new \OWA\Module\Base\Controller\MyAppPasswordCreateAjax($params));
    }

    /** The answer carries the token; the hash never leaves the server. */
    public function testCreatingAnswersWithTheToken(): void
    {
        $me = $this->signIn();

        $r = $this->create(array('name' => 'Export script', 'scope' => 'read'));
        $made = $r['json']['data'];

        $this->assertSame(201, $r['status']);
        $this->assertNull($r['json']['error']);
        $this->assertTrue(AppPasswords::looksLikeToken($made['token']));
        $this->assertSame('Export script', $made['name']);
        $this->assertSame('Read only', $made['scope_label']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $made['created_display']);
        $this->assertSame(substr($made['token'], 0, 12), $made['lookup_id']);
        $this->assertArrayNotHasKey('secret_hash', $made);

        $row = AppPasswords::verify($made['token']);
        $this->assertSame($me['user_id'], $row['user_id']);
        $this->assertSame('read', $row['scope']);
    }

    /**
     * A browser session only: a leaked application password or API key cannot
     * mint a successor that outlives revoking it. The rule is Ajax's; this
     * checks the endpoint inherits it.
     */
    public function testOnlyABrowserSessionCanCreate(): void
    {
        $me = $this->signIn();

        foreach (array('app_password', 'api_key') as $method) {

            $this->signedInBy($method);
            $c = new \OWA\Module\Base\Controller\MyAppPasswordCreateAjax(array(
                'do' => 'base.myAppPasswordCreateAjax', 'name' => 'Successor', 'scope' => 'full',
                'nonce' => self::nonceFor('base.myAppPasswordCreateAjax')));

            $this->assertSame(401, $this->ajax($c)['status'], $method);
        }

        $this->assertSame(array(), AppPasswords::forUser($me['user_id']));
    }

    public function testWithoutTheNonceNothingIsCreated(): void
    {
        $me = $this->signIn();

        $r = $this->create(array('name' => 'Export script', 'scope' => 'read'), false);

        $this->assertSame(403, $r['status']);
        $this->assertSame(array(), AppPasswords::forUser($me['user_id']));
    }

    public function testANameIsRequired(): void
    {
        $me = $this->signIn();

        $r = $this->create(array('name' => '   ', 'scope' => 'read'));

        $this->assertSame(422, $r['status']);
        $this->assertSame('Give the application password a name.', $r['json']['error']);
        $this->assertSame(array(), AppPasswords::forUser($me['user_id']));
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        $me = $this->signIn();

        $r = $this->create(array('name' => 'Admin script', 'scope' => 'admin'));

        $this->assertSame(422, $r['status']);
        $this->assertSame('The application password could not be created.', $r['json']['error']);
        $this->assertSame(array(), AppPasswords::forUser($me['user_id']));
    }

    /** Not a REST route: an application password cannot reach it at all. */
    public function testItIsNotARestRoute(): void
    {
        foreach ((array) \OWA\Core\CoreAPI::serviceSingleton()->getAllRestApiRoutes() as $versions) {
            foreach ((array) $versions as $names) {
                foreach ((array) $names as $methods) {
                    foreach ((array) $methods as $meta) {
                        $this->assertStringNotContainsString('AppPasswordCreate', (string) ($meta['class_name'] ?? ''));
                    }
                }
            }
        }
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
            'create'       => array(\OWA\Module\Base\Controller\MyAppPasswordCreateAjax::class, 'view_site_list', true),
            'revoke own'   => array(\OWA\Module\Base\Controller\MyAppPasswordRevoke::class, 'view_site_list', true),
            'admin revoke' => array(\OWA\Module\Base\Controller\UserAppPasswordRevoke::class, 'edit_users', true),
        );
    }

    public function testEveryActionIsRegistered(): void
    {
        $actions = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap('actions');

        foreach (array('base.myAppPasswords', 'base.myAppPasswordCreateAjax',
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

    /**
     * The screen posts nothing to itself. Add opens the dialog, whose form is
     * sent to base.myAppPasswordCreateAjax with its nonce; a new row is cloned
     * from the template, so it gets the same revoke form as a drawn one.
     */
    public function testTheScreenAddsThroughTheDialog(): void
    {
        $vars = array(
            'app_passwords' => array(array('lookup_id' => 'abcdefghijkl', 'name' => 'Export script',
                'scope' => 'read', 'created_at' => time(), 'last_used_at' => null, 'last_used_ip' => null)),
            'scopes'        => AppPasswords::scopes(),
            'enabled'       => true,
            'require_https' => true,
            'api_url'       => 'https://owa.example.com/api/index.php',
            'siteId'        => '',
        );

        $html = $this->render('my_app_passwords.php', $vars);

        // The dialog's form is sent with fetch(); nothing is posted to the screen itself.
        $this->assertMatchesRegularExpression(
            '#<form method="post" class="owa_appPasswordForm" novalidate\s+action="[^"]*do=base\.myAppPasswordCreateAjax"#',
            html_entity_decode($html));
        $dialog = substr($html, strpos($html, 'id="owa_appPasswordDialog"'));
        $this->assertStringContainsString('name="nonce"', $dialog);
        $this->assertStringContainsString('<button type="button" class="owa-button owa_appPasswordAdd">', $html);
        $this->assertStringContainsString('id="owa_appPasswordDialog"', $html);
        $this->assertStringContainsString('class="owa_appPasswordToken"', $html);

        $this->assertStringContainsString('name="lookup_id" data-field="lookup_id" value="abcdefghijkl"', $html);
        $this->assertStringContainsString('Never', $html);

        $template = substr($html, strpos($html, '<template id="owa_appPasswordRowTemplate">'));
        $template = substr($template, 0, strpos($template, '</template>'));
        $this->assertStringContainsString('base.myAppPasswordRevoke', $template);
        $this->assertStringContainsString('name="nonce"', $template);
        $this->assertStringContainsString('data-field="name"', $template);

        $this->assertMatchesRegularExpression('/<p class="owa_appPasswordsNone" hidden>/', $html);

        $vars['app_passwords'] = array();
        $empty = $this->render('my_app_passwords.php', $vars);
        $this->assertMatchesRegularExpression('/<table class="management owa_appPasswords" hidden>/', $empty);
        $this->assertMatchesRegularExpression('/<p class="owa_appPasswordsNone">/', $empty);
    }

    public function testTheScreenSaysWhenTheyAreTurnedOff(): void
    {
        $html = $this->render('my_app_passwords.php', array(
            'app_passwords' => array(), 'scopes' => AppPasswords::scopes(), 'enabled' => false,
            'require_https' => false, 'api_url' => '', 'siteId' => '',
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
