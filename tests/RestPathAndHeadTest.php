<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The REST path after module/version/route, and HEAD.
 *
 * A segment past the route's params_order indexed a name that is not there
 * (api/base/v1/sites/x on a route with none). HEAD read no parameters and had
 * no route, though a read-only application password allows it.
 */
final class RestPathAndHeadTest extends TestCase
{
    /** @var array */
    private $get;
    /** @var array */
    private $server;

    protected function setUp(): void
    {
        $this->get    = $_GET;
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET    = $this->get;
        $_SERVER = $this->server;
    }

    public function testSegmentsAreNamedInTheRoutesOrder(): void
    {
        $this->assertSame(
            ['user_id' => 'owatest@example.com'],
            \OWA\Core\CoreAPI::restPathParams(['owatest@example.com'], ['user_id']));
    }

    public function testNoSegmentsNameNothing(): void
    {
        $this->assertSame([], \OWA\Core\CoreAPI::restPathParams([], ['user_id']));
        $this->assertSame([], \OWA\Core\CoreAPI::restPathParams([], null));
    }

    public function testATrailingSlashNamesNothing(): void
    {
        $this->assertSame([], \OWA\Core\CoreAPI::restPathParams([''], null));
        $this->assertSame(['report_name' => 'x'], \OWA\Core\CoreAPI::restPathParams(['x', ''], ['report_name']));
    }

    /** More segments than names is no route, answered as an unknown one is. */
    public function testASegmentPastTheRoutesNamesIsRefused(): void
    {
        $this->assertNull(\OWA\Core\CoreAPI::restPathParams(['x'], null));
        $this->assertNull(\OWA\Core\CoreAPI::restPathParams(['x'], []));
        $this->assertNull(\OWA\Core\CoreAPI::restPathParams(['a', 'b'], ['user_id']));
    }

    public function testAHeadRequestReadsItsQueryString(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_SERVER['HTTP_HOST']      = 'owa.example.test';
        $_SERVER['SERVER_PORT']    = 80;
        $_SERVER['REQUEST_URI']    = '/owa/api/index.php';
        $_GET = ['owa_metrics' => 'pageViews'];

        $r = new \OWA\Core\RequestContainer();

        $this->assertSame('HEAD', $r->getRequestType());
        $this->assertSame('pageViews', $r->getParam('metrics'));
    }

    public function testAHeadRequestTakesTheGetRoute(): void
    {
        $get = \OWA\Core\CoreAPI::lookupRestRoute('GET', 'base', 'v1', 'reports');

        $this->assertNotEmpty($get, 'the GET route is registered at boot');
        $this->assertSame($get, \OWA\Core\CoreAPI::lookupRestRoute('HEAD', 'base', 'v1', 'reports'));
    }

    public function testAHeadRequestTakesNoOtherMethodsRoute(): void
    {
        $this->assertEmpty(\OWA\Core\CoreAPI::lookupRestRoute('HEAD', 'base', 'v1', 'siteUsers'),
            'siteUsers is POST only');
    }
}
