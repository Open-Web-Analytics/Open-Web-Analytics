<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Lib;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The current page's URL as the browser addressed it (#863).
 *
 * Behind a reverse proxy on a sub-path, https://example.com/owa/index.php
 * reaches PHP as http://localhost:3508/index.php. The login's go= was built
 * from the second, so signing in sent the browser to a page it could not
 * reach.
 */
final class CurrentPublicUrlTest extends TestCase
{
    /** @var array */
    private $server;

    /** @var array settings before the test */
    private $settings = array();

    protected function setUp(): void
    {
        $this->server = $_SERVER;

        foreach (array('public_url', 'request_mode') as $name) {
            $this->settings[$name] = \OWA\Core\CoreAPI::getSetting('base', $name);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;

        foreach ($this->settings as $name => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $name, $value);
        }
    }

    private function request(string $public, string $host, string $script, string $uri): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'public_url', $public);

        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['HTTP_HOST']   = $host;
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['REQUEST_URI'] = $uri;
    }

    public function testBehindAProxyThatStripsTheSubPath(): void
    {
        $this->request('https://example.com/owa/', 'localhost:3508', '/index.php', '/index.php?do=base.reportingHome');

        $this->assertSame('https://example.com/owa/index.php?do=base.reportingHome', Lib::currentPublicUrl());
        $this->assertSame('http://localhost:3508/index.php?do=base.reportingHome', Lib::get_current_url(),
            'the URL the request was made to, which request signatures are checked against, is unchanged');
    }

    public function testBehindAProxyThatKeepsTheSubPath(): void
    {
        $this->request('https://example.com/owa/', 'internal', '/owa/index.php', '/owa/index.php?do=base.report');

        $this->assertSame('https://example.com/owa/index.php?do=base.report', Lib::currentPublicUrl());
    }

    public function testAnInstallAtTheRootOfItsHost(): void
    {
        $this->request('https://app.example.com/', 'app.example.com', '/index.php', '/index.php?do=base.report');

        $this->assertSame('https://app.example.com/index.php?do=base.report', Lib::currentPublicUrl());
    }

    public function testWithNoPublicUrlItIsTheRequestsOwn(): void
    {
        $this->request('', 'owa.example.com', '/owa/index.php', '/owa/index.php?do=base.report');

        $this->assertSame('http://owa.example.com/owa/index.php?do=base.report', Lib::currentPublicUrl());
    }

    /** The way back after signing in is the page the browser asked for. */
    public function testTheLoginReturnsToThePublicUrl(): void
    {
        $this->request('https://example.com/owa/', 'localhost:3508', '/index.php', '/index.php?do=base.reportingHome');
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'web_app');

        $screen = new class(array('do' => 'base.reportingHome')) extends \OWA\Core\Controller {
            function action() {}
        };
        $screen->notAuthenticatedAction();

        $data = (array) (new \ReflectionProperty(\OWA\Core\Controller::class, 'data'))->getValue($screen);

        $this->assertSame('base.loginForm', $data['do'] ?? null);
        $this->assertSame('https://example.com/owa/index.php?do=base.reportingHome', urldecode((string) $data['go']));
    }
}
