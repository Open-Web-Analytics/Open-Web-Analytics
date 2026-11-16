<?php

use OWA\Core\Auth;
use OWA\Core\OverlayToken;

require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * An overlay token signs in only on the route it was made for, and only on the
 * routes an overlay reads.
 *
 * The REST router runs the route in `rest_params` (module/version/route) in
 * preference to `do`, so the token is checked against that route
 * (Auth::routedAction()). And Auth tries a token only where a controller takes
 * one (Controller::$accepts_overlay_token): the reports and recordings routes.
 */
final class OverlayTokenRouteTest extends RestControllerTestCase
{
    private const PARAMS = array('overlayToken', 'rest_params', 'do', 'constraints', 'siteId', 'propertyId', 'metrics', 'dimensions');

    protected function tearDown(): void
    {
        foreach (self::PARAMS as $p) {
            \OWA\Core\CoreAPI::setRequestParam($p, '');
        }

        $this->resetCurrentUser();

        parent::tearDown();
    }

    private function request(array $params): void
    {
        foreach (self::PARAMS as $p) {
            \OWA\Core\CoreAPI::setRequestParam($p, $params[$p] ?? '');
        }

        $this->resetCurrentUser();
    }

    public function testTheRoutedActionIsTheOneThatRuns(): void
    {
        $this->request(array('rest_params' => 'base/v1/users', 'do' => 'reports'));
        $this->assertSame('users', Auth::routedAction());

        $this->request(array('do' => 'reports'));
        $this->assertSame('reports', Auth::routedAction());
    }

    public function testATokenSignsInOnlyOnItsRouteAndOnlyWhereARouteNamesIt(): void
    {
        $user  = $this->makeUser('admin', 'overlay');
        $token = OverlayToken::mint($user['user_id'], 'reports', 'constraints', 'pagePath==/a');
        $auth  = Auth::get_instance();
        $base  = array('overlayToken' => $token, 'constraints' => 'pagePath==/a');

        // Its own route, on a controller that takes the token.
        $this->request($base + array('rest_params' => 'base/v1/reports'));
        $this->assertTrue($auth->authenticateUser(true)['auth_status']);

        // `do` names its route while rest_params routes elsewhere.
        $this->request($base + array('rest_params' => 'base/v1/users', 'do' => 'reports'));
        $this->assertNotTrue($auth->authenticateUser(true)['auth_status']);

        // A controller that does not take a token.
        $this->request($base + array('rest_params' => 'base/v1/reports'));
        $this->assertNotTrue($auth->authenticateUser()['auth_status']);
    }

    public function testOnlyTheOverlayRoutesTakeTheToken(): void
    {
        $takes = function (string $class): bool {
            $p = new \ReflectionProperty($class, 'accepts_overlay_token');
            return (bool) $p->getValue(new $class(array()));
        };

        $this->assertTrue($takes(\OWA\Module\Base\Controller\ReportsRest::class));
        $this->assertTrue($takes(\OWA\Module\Domstream\Controller\DomstreamsRestController::class));
        $this->assertFalse($takes(\OWA\Module\Base\Controller\UsersRest::class));
    }
}
