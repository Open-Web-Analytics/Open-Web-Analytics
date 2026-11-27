<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Actions a handler runs through CoreAPI::performAction(), with an event or
 * a user it builds, are not reached by a request: handleRequest() answers
 * them as an unknown action (Controller::IN_PROCESS_ONLY).
 */
final class InProcessOnlyActionsTest extends TestCase
{
    /** @return array<string, array{string, string}> action => [action, its controller] */
    public static function handlerActions(): array
    {
        return [
            'base.usersResetPassword' => ['base.usersResetPassword', \OWA\Module\Base\Controller\UsersResetPassword::class],
            'base.usersSetPassword'   => ['base.usersSetPassword', \OWA\Module\Base\Controller\UsersSetPassword::class],
            'base.usersNewAccount'    => ['base.usersNewAccount', \OWA\Module\Base\Controller\UsersNewAccount::class],
            'base.usersEmailChanged'  => ['base.usersEmailChanged', \OWA\Module\Base\Controller\UsersEmailChanged::class],
            'base.notifyNewSession'   => ['base.notifyNewSession', \OWA\Module\Base\Controller\NotifyNewSession::class],
            'base.processEvent'       => ['base.processEvent', \OWA\Module\Base\Controller\ProcessEvent::class],
            'base.processRequest'     => ['base.processRequest', \OWA\Module\Base\Controller\ProcessRequest::class],
            'domstream.processEvent'  => ['domstream.processEvent', \OWA\Module\Domstream\Controller\ProcessEvent::class],
        ];
    }

    /**
     * The controller declares it; and where its module is active, the
     * registered action is that controller and is refused. Domstream is
     * active only on an install that activated it.
     *
     * @dataProvider handlerActions
     */
    public function testAHandlersActionIsInProcessOnly(string $action, string $class): void
    {
        $this->assertTrue($class::IN_PROCESS_ONLY, $class);

        $map = \OWA\Core\CoreAPI::serviceSingleton()->getMapValue('actions', $action);

        if (!is_array($map)) {
            $this->assertStringStartsWith('domstream.', $action, 'every Base action is registered');

            return;
        }

        $this->assertSame(ltrim($class, '\\'), ltrim((string) $map['class_name'], '\\'));
        $this->assertTrue(\OWA\Core\CoreAPI::isInProcessOnlyAction($action));
    }

    public function testARequestsActionIsNot(): void
    {
        foreach (['base.loginForm', 'base.report', 'base.sitesAdd', 'base.nonsense', '', null] as $action) {
            $this->assertFalse(\OWA\Core\CoreAPI::isInProcessOnlyAction($action), var_export($action, true));
        }
    }

    /**
     * A request for one is answered as an unknown action, and its controller
     * does not run: it would fail on the event it was not given.
     *
     * @dataProvider handlerActions
     */
    public function testARequestForOneIsNotRun(string $action, string $class): void
    {
        $code = http_response_code();
        ob_start();

        try {
            $out = \OWA\Core\CoreAPI::handleRequest(['do' => $action], $action);
        } finally {
            $echoed = ob_get_clean();
            $status = http_response_code();
            http_response_code($code ?: 200);
        }

        if (defined('OWA_CLI')) {
            $this->assertNull($out);
        } else {
            $this->assertSame(404, $status);
            $this->assertStringContainsString(\OWA\Core\CoreAPI::t('The page could not be found.'), $echoed . (string) $out);
        }
    }

    /** performAction() still runs one, as the handlers call it. */
    public function testPerformActionStillRunsOne(): void
    {
        $controller = new class([]) extends \OWA\Core\Controller {
            const IN_PROCESS_ONLY = true;
            public static $ran = false;

            public function action()
            {
                self::$ran = true;
            }
        };

        \OWA\Core\CoreAPI::serviceSingleton()->setMapValue('actions', 'owatest.inProcessOnly', ['class_name' => get_class($controller)]);

        try {
            \OWA\Core\CoreAPI::performAction('owatest.inProcessOnly', []);
            $this->assertTrue($controller::$ran);
            $this->assertTrue(\OWA\Core\CoreAPI::isInProcessOnlyAction('owatest.inProcessOnly'));
        } finally {
            \OWA\Core\CoreAPI::serviceSingleton()->setMapValue('actions', 'owatest.inProcessOnly', null);
        }
    }
}
