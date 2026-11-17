<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The debug log names request parameters, event properties and state keys,
 * and never writes their values: a value can be a password, a password reset
 * key or a sign-in cookie.
 */
final class DebugLogNamesNotValuesTest extends TestCase
{
    private const SECRET = 'debug-log-secret-7f3a91';

    private $get;
    private $post;
    private $server;
    private $cookie;

    protected function setUp(): void
    {
        $this->get    = $_GET;
        $this->post   = $_POST;
        $this->server = $_SERVER;
        $this->cookie = $_COOKIE;

        $_GET    = array();
        $_POST   = array();
        $_COOKIE = array();

        $_SERVER['HTTP_HOST']   = 'owa.example.test';
        $_SERVER['SERVER_PORT'] = 80;
        $_SERVER['REQUEST_URI'] = '/owa/index.php';
        unset($_SERVER['HTTP_COOKIE']);
    }

    protected function tearDown(): void
    {
        $_GET    = $this->get;
        $_POST   = $this->post;
        $_SERVER = $this->server;
        $_COOKIE = $this->cookie;
    }

    /** @return string every message logged while $fn ran, at any level */
    private function captureLog(callable $fn): string
    {
        $e = \OWA\Core\CoreAPI::errorSingleton();

        $handler = new \Monolog\Handler\TestHandler(\Monolog\Logger::DEBUG);
        $e->logger->pushHandler($handler);

        // Before setHandler() runs, log() buffers instead of emitting.
        $was_init = $e->init;
        $e->init  = true;

        try {
            $fn();
        } finally {
            $e->init = $was_init;
            $e->logger->popHandler();
        }

        return implode("\n", array_column($handler->getRecords(), 'message'));
    }

    public function testAPostedPasswordIsNamedButNotWritten(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET  = array('do' => 'base.installBase');
        $_POST = array('user_id' => 'admin', 'password' => self::SECRET);

        $log = $this->captureLog(function () {
            new \OWA\Core\RequestContainer();
        });

        $this->assertStringNotContainsString(self::SECRET, $log);
        $this->assertStringContainsString('password', $log);
    }

    public function testAStateValueIsNotWritten(): void
    {
        $state = new \OWA\Module\Base\Classes\State();

        $log = $this->captureLog(function () use ($state) {
            $state->setState('p', '', self::SECRET);
        });

        $this->assertStringNotContainsString(self::SECRET, $log);
        $this->assertStringContainsString('store: p', $log);
    }
}
