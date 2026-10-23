<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';
require_once __DIR__ . '/AjaxRequestTrait.php';

/**
 * An admin screen (AdminController) reads the session and nothing else.
 *
 * An API key is not read there, and a login form's user_id and password on a
 * screen are parameters, not a sign-in that writes the session cookies ahead
 * of the nonce check. base.login declares the login form. Served by the REST
 * API, the same classes take the API's credentials.
 */
final class WebAppAuthMethodsTest extends RestControllerTestCase
{
    use AjaxRequestTrait;

    /**
     * Registered actions that are plain Controllers, and why. Everything else
     * a browser reaches is an AdminController; a new screen added as a plain
     * Controller fails testEverySignedInScreenIsAnAdminController until it is
     * moved, or named here with its reason.
     */
    private const PLAIN = array(
        // before sign-in
        'base.login', 'base.loginForm', 'base.logout',
        'base.passwordResetForm', 'base.passwordResetRequest', 'base.usersChangePassword',
        'base.usersNewAccount', 'base.usersPasswordEntry', 'base.usersResetPassword', 'base.usersSetPassword',
        // must run while updates are pending, which AdminController blocks
        'base.updates', 'base.updatesApply',
        // tracking, from log.php and its event queue
        'base.processEvent', 'base.processRequest', 'base.notifyNewSession', 'domstream.processEvent',
        // the REST API's CORS preflight
        'base.corsPreflight',
        // opens on the tracked site and hands over to an overlay token
        'base.overlayLauncher',
    );

    /** @var array settings and server variables to put back */
    private $saved = array();

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = array(
            'mode'   => \OWA\Core\CoreAPI::getSetting('base', 'request_mode'),
            'server' => $_SERVER,
        );

        // A refusal on a screen builds its go= URL from the request.
        $_SERVER['HTTP_HOST']   = 'owa.example.com';
        $_SERVER['REQUEST_URI'] = '/index.php?do=base.testScreen';

        $this->mode('web_app');
        $this->signedInBy(null);
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', $this->saved['mode']);
        $_SERVER = $this->saved['server'];

        foreach (array('apiKey', 'user_id', 'password') as $param) {
            \OWA\Core\CoreAPI::setRequestParam($param, '');
        }

        $this->signedInBy(null);

        parent::tearDown();
    }

    private function mode(string $mode): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', $mode);
    }

    /** An admin screen: needs a signed-in user who can view the site list. */
    private function screen()
    {
        return new class(array('do' => 'base.testScreen')) extends \OWA\Core\AdminController {

            public $ran = false;

            function __construct($params)
            {
                $this->setRequiredCapability('view_site_list');
                parent::__construct($params);
            }

            function action()
            {
                $this->ran = true;
            }
        };
    }

    private function sentToLogin(array $data): bool
    {
        return ($data['view_method'] ?? '') === 'redirect' && ($data['do'] ?? '') === 'base.loginForm';
    }

    public function testAnApiKeyIsNotReadOnAScreen(): void
    {
        $admin = $this->makeUser('admin', 'key');
        \OWA\Core\CoreAPI::setRequestParam('apiKey', $admin['api_key']);

        $screen = $this->screen();
        $data = (array) $screen->doAction();

        $this->assertFalse($screen->ran);
        $this->assertTrue($this->sentToLogin($data));
        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());

        // The control: the same key opens the same class served by the REST API.
        $this->resetCurrentUser();
        $this->signedInBy(null);
        $this->mode('rest_api');

        $screen = $this->screen();
        $screen->doAction();

        $this->assertTrue($screen->ran, 'the key itself is valid');
    }

    /** Not tried at all: the branch that writes the session cookies never runs. */
    public function testLoginFormFieldsOnAScreenAreNotASignIn(): void
    {
        $user = $this->makeUser('admin', 'form');
        \OWA\Core\CoreAPI::setRequestParam('user_id', $user['user_id']);
        \OWA\Core\CoreAPI::setRequestParam('password', 'anything');

        $screen = $this->screen();
        $data = (array) $screen->doAction();

        $this->assertFalse($screen->ran);
        $this->assertTrue($this->sentToLogin($data));
        $this->assertNotSame('login_form', \OWA\Core\Auth::get_instance()->getAuthMethod());
    }

    public function testAUserSignedInByAnotherMethodIsRefused(): void
    {
        $this->authenticateAs('admin');
        $this->signedInBy('api_key');

        $screen = $this->screen();
        $screen->doAction();

        $this->assertFalse($screen->ran);
    }

    /**
     * Signed in by code in this process -- the CLI, a module's auth_status
     * filter, a host embedding OWA -- with no request credential at all. The
     * list governs what a request may present, so this is not refused.
     */
    public function testAUserSignedInByCodeIsNotARequestCredential(): void
    {
        $this->authenticateAs('viewer');
        $this->signedInBy(null);

        $screen = $this->screen();
        $screen->doAction();

        $this->assertTrue($screen->ran);
    }

    public function testTheSessionOpensTheScreen(): void
    {
        $this->authenticateAs('viewer');
        $this->signedInBy('cookies');

        $screen = $this->screen();
        $screen->doAction();

        $this->assertTrue($screen->ran);
    }

    public function testLoginDeclaresTheFormThenTheSession(): void
    {
        $login = new \OWA\Module\Base\Controller\Login(array());

        $this->assertSame(array('login_form', 'cookies'), $login->acceptedAuthMethods());
    }

    public function testTheRestApiTakesItsOwnCredentials(): void
    {
        $this->mode('rest_api');

        $this->assertNull($this->screen()->acceptedAuthMethods());
        $this->assertNull((new \OWA\Module\Base\Controller\ReportsRest(array()))->acceptedAuthMethods());
    }

    /** A CLI command is an AdminController too, and runs as the user the CLI signs in. */
    public function testTheCliRunsAsItsOwnUser(): void
    {
        $this->mode('cli');

        $this->assertNull($this->screen()->acceptedAuthMethods());
    }

    /**
     * Every registered action a browser reaches signed in is an
     * AdminController. REST routes, CLI commands and the installer have their
     * own bases; PLAIN lists the rest, each with its reason.
     */
    public function testEverySignedInScreenIsAnAdminController(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $rest    = array();

        foreach ((array) $service->getAllRestApiRoutes() as $versions) {
            foreach ((array) $versions as $names) {
                foreach ((array) $names as $methods) {
                    foreach ((array) $methods as $meta) {
                        $rest[ltrim((string) $meta['class_name'], '\\')] = true;
                    }
                }
            }
        }

        $plain = array();

        foreach ((array) $service->getMap('actions') as $do => $action) {

            $class = ltrim((string) ($action['class_name'] ?? ''), '\\');

            // Production controllers only: other tests leave stubs in the shared
            // map, some cleared to null.
            if ($class === '' || !class_exists($class)
                || strpos((string) (new \ReflectionClass($class))->getFileName(), '/tests/') !== false) {
                continue;
            }

            if (isset($rest[$class])
                || is_a($class, \OWA\Core\AdminController::class, true)
                || is_a($class, \OWA\Core\Controller\Install::class, true)
                || preg_match('/Cli$/', $class)) {
                continue;
            }

            $plain[] = $do;
        }

        // A subset, not equal: a module's actions are registered only while it
        // is active (domstream.processEvent on an install without Domstream).
        $this->assertSame(array(), array_values(array_diff($plain, self::PLAIN)),
            'A plain Controller accepts any credential. Make a signed-in screen an AdminController, '
            . 'or name it in PLAIN with its reason.');
    }
}
