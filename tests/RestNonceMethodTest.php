<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * A REST request the session signs in carries a nonce for its route AND its
 * HTTP method. The notifications routes share one name and differ by method:
 * the nonce of the read a page embeds does not mark read or dismiss.
 */
final class RestNonceMethodTest extends RestControllerTestCase
{
    private $authMethod;

    private $requestType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authMethod  = \OWA\Core\Auth::get_instance()->auth_method;
        $this->requestType = \OWA\Core\CoreAPI::serviceSingleton()->request->request_type;

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'rest');
        $this->authenticateAs('admin');
        \OWA\Core\Auth::get_instance()->auth_method = 'cookies';
    }

    protected function tearDown(): void
    {
        \OWA\Core\Auth::get_instance()->auth_method = $this->authMethod;
        \OWA\Core\CoreAPI::serviceSingleton()->request->request_type = $this->requestType;
        http_response_code(200);

        parent::tearDown();
    }

    private const CONTROLLERS = [
        'GET'    => \OWA\Module\Base\Controller\NotificationsRest::class,
        'POST'   => \OWA\Module\Base\Controller\NotificationMarkReadRest::class,
        'DELETE' => \OWA\Module\Base\Controller\NotificationDismissRest::class,
    ];

    /** Whether a request by $method with the nonce minted for $mintedFor is refused as unauthenticated. */
    private function refused(string $method, string $mintedFor): bool
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->request_type = $method;
        http_response_code(200);

        $resp = $this->callEndpoint(self::CONTROLLERS[$method], 'notificationsRestController.php', [
            'do'             => 'notifications',
            'module'         => 'base',
            'version'        => 'v1',
            'notificationId' => 'rest-nonce-' . $this->tok,
            'nonce'          => \OWA\Core\CoreAPI::createRestApiNonce('v1', 'base', 'notifications', $mintedFor),
        ]);

        return $resp['status'] === 401;
    }

    public function testANonceMintedForAGetIsAcceptedOnTheGet(): void
    {
        $this->assertFalse($this->refused('GET', 'GET'));
    }

    public function testANonceMintedForAGetIsRefusedOnAPostOrDeleteOfTheSameRoute(): void
    {
        $this->assertTrue($this->refused('POST', 'GET'), 'a POST took the GET nonce');
        $this->assertTrue($this->refused('DELETE', 'GET'), 'a DELETE took the GET nonce');
    }

    public function testEachMethodAcceptsItsOwnNonce(): void
    {
        $this->assertFalse($this->refused('POST', 'POST'));
        $this->assertFalse($this->refused('DELETE', 'DELETE'));
        $this->assertTrue($this->refused('GET', 'DELETE'), 'a GET took the DELETE nonce');
    }
}
