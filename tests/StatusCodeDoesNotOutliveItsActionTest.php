<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A status message must not outlive the action that produced it.
 *
 * status_code travels on the query string of a redirect, so it describes the
 * outcome of the action that redirected you -- not whatever you do next from
 * the same URL.
 *
 * Every controller copied it out of the request params unconditionally, and the
 * login form posts back to the URL it was served from. So a completed password
 * reset, which redirects to '...do=base.loginForm&status_code=3006', left that
 * code in the URL, and a FAILED login from that page rendered "your password
 * has been changed" beside "login failed". Two messages, contradicting each
 * other, describing two different actions, with nothing to say which was which
 * -- reported from a live install as "one success and one failure".
 *
 * A redirect always arrives as a GET, so scoping the carry-over to non-POST
 * keeps every legitimate use and drops exactly the stale case.
 */
final class StatusCodeDoesNotOutliveItsActionTest extends TestCase
{
    private function requestType( string $method ): void
    {
        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;

        $property = new \ReflectionProperty( $request, 'request_type' );
        $property->setAccessible( true );
        $property->setValue( $request, $method );
    }

    private function statusCodeSeenByTheView( string $method )
    {
        $this->requestType( $method );

        $controller = new \OWA\Module\Base\Controller\LoginForm(
            array( 'status_code' => 3006 ) );

        $controller->doAction();

        $data = new \ReflectionProperty( \OWA\Core\Controller::class, 'data' );
        $data->setAccessible( true );

        return ( (array) $data->getValue( $controller ) )['status_code'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->requestType( 'GET' );
    }

    /** The redirect that carries a status_code is a GET, and must still work. */
    public function testARedirectStillShowsItsStatusMessage(): void
    {
        $this->assertSame( 3006, $this->statusCodeSeenByTheView( 'GET' ) );
    }

    /** A form post is a new action and reports its own outcome, not the last one's. */
    public function testAFormPostDoesNotInheritThePreviousActionsStatus(): void
    {
        $this->assertNull(
            $this->statusCodeSeenByTheView( 'POST' ),
            'A failed login inherited "your password has been changed" from the URL the '
            . 'password reset redirected to.' );
    }
}
