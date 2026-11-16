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
 * (Auth::routedAction()). And Auth tries a token only where a controller names
 * `overlay_token` (setAuthMethods()): the reports and recordings routes.
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

        // The method of the last sign-in tried, which the next test's screens check.
        Auth::get_instance()->auth_method = null;

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

        // Its own route, on a controller that names the token.
        $this->request($base + array('rest_params' => 'base/v1/reports'));
        $this->assertTrue($auth->authenticateUser(array('overlay_token'))['auth_status']);

        // `do` names its route while rest_params routes elsewhere.
        $this->request($base + array('rest_params' => 'base/v1/users', 'do' => 'reports'));
        $this->assertNotTrue($auth->authenticateUser(array('overlay_token'))['auth_status']);

        // A controller that accepts any credential does not take a token.
        $this->request($base + array('rest_params' => 'base/v1/reports'));
        $this->assertNotTrue($auth->authenticateUser(null)['auth_status']);
    }

    public function testOnlyTheOverlayRoutesNameTheToken(): void
    {
        foreach (array(\OWA\Module\Base\Controller\ReportsRest::class, \OWA\Module\Domstream\Controller\DomstreamsRestController::class) as $class) {
            $this->assertContains('overlay_token', (array) (new $class(array()))->acceptedAuthMethods(), $class);
        }

        $users = new \OWA\Module\Base\Controller\UsersRest(array());
        $this->assertNotContains('overlay_token', (array) $users->acceptedAuthMethods());
    }
}
