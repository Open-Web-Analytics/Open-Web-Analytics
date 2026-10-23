<?php
namespace OWA\Core\Controller;

/**
 * An internal endpoint that the admin's own pages call with fetch(), answered
 * in JSON. Named <module>.<name>Ajax.
 *
 * Reached through index.php like a screen, never through the REST API, and
 * only by a signed-in browser:
 *
 *  - POST only (405).
 *  - The session cookies are the only credential accepted
 *    (setAuthMethods). Authentication is the controller pipeline's own; the
 *    other methods are simply not tried, so a login form's user_id and
 *    password cannot sign anyone in or set cookies here, and an API key or an
 *    application password is not read.
 *  - A nonce is required, checked like any admin form's.
 *  - Every outcome is JSON, including the refusals that answer a screen with a
 *    redirect or an HTML page: 401 not signed in, 403 not capable or a bad
 *    nonce, 422 invalid input, 503 updates pending.
 *  - No CORS headers: same origin only.
 *
 * The body is { "data": <payload or null>, "error": <message or null> }; see
 * View\Ajax. A concrete controller ends its action() with answer().
 */
class Ajax extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setNonceRequired();
        $this->setAuthMethods( array( 'cookies' ) );
    }

    function doAction() {

        if ( \OWA\Core\CoreAPI::serviceSingleton()->request->getRequestType() !== 'POST' ) {

            return $this->answer( 405, \OWA\Core\CoreAPI::t( 'This request must be a POST.' ) );
        }

        return parent::doAction();
    }

    /**
     * Sets the response and returns the controller's data, so action() can end
     * with `return $this->answer( ... )`.
     *
     * @param  int         $status HTTP status
     * @param  string|null $error  a message for the person, already translated
     * @param  mixed       $data   the payload
     * @return array
     */
    protected function answer( $status, $error = null, $data = null ) {

        http_response_code( (int) $status );

        $this->set( 'ajax_error', $error );
        $this->set( 'ajax_data', $data );
        $this->setView( 'base.ajax' );

        return $this->data;
    }

    function notAuthenticatedAction() {

        return $this->answer( 401, \OWA\Core\CoreAPI::t( 'You are signed out. Sign in again and retry.' ) );
    }

    function nonceFailedAction() {

        if ( ! \OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated() ) {

            return $this->notAuthenticatedAction();
        }

        return $this->answer( 403, $this->getMsg( 2005 )['message'] );
    }

    function authenticatedButNotCapableAction( $additionalMessage = '' ) {

        return $this->answer( 403, $this->getMsg( 2003 )['message'] );
    }

    function updateAction() {

        return $this->answer( 503, \OWA\Core\CoreAPI::t( 'Cannot perform action. OWA Updates required.' ) );
    }

    /** The first validation message. */
    function errorAction() {

        // Controller::get() reads request params, not data.
        $messages = array_values( array_filter( (array) ( $this->data['validation_errors'] ?? array() ), 'is_string' ) );

        return $this->answer( 422, $messages[0] ?? \OWA\Core\CoreAPI::t( 'The request was not valid.' ) );
    }
}
