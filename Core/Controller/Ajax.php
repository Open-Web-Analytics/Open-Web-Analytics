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
 *  - The session cookies are the only credential accepted, as on every
 *    AdminController (acceptedAuthMethods). The other methods are not tried,
 *    so a login form's user_id and password cannot sign anyone in or set
 *    cookies here, and an application password is not read.
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

    /**
     * @param array<string, mixed> $params request params: name => value, as sent
     */
    public function __construct( $params ) {

        parent::__construct( $params );

        $this->setNonceRequired();
    }

    /**
     * @return mixed answer()'s data for a non-POST, else parent::doAction()'s
     *               result: whatever the action returned
     */
    public function doAction() {

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
     * @param  mixed       $data   the payload, any JSON-encodable value
     * @return array<string, mixed> the controller's data: name => value
     */
    protected function answer( $status, $error = null, $data = null ) {

        http_response_code( (int) $status );

        $this->set( 'ajax_error', $error );
        $this->set( 'ajax_data', $data );
        $this->setView( 'base.ajax' );

        return $this->data;
    }

    /**
     * @return array<string, mixed> answer()'s data
     */
    public function notAuthenticatedAction() {

        if ( \OWA\Core\Auth::get_instance()->refusedLegacyApiKey() ) {

            return $this->answer( 401, \OWA\Core\Controller::legacyApiKeyMessage() );
        }

        return $this->answer( 401, \OWA\Core\CoreAPI::t( 'You are signed out. Sign in again and retry.' ) );
    }

    /**
     * @return array<string, mixed> answer()'s data
     */
    public function nonceFailedAction() {

        if ( ! \OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated() ) {

            return $this->notAuthenticatedAction();
        }

        return $this->answer( 403, $this->getMsg( 2005 )['message'] );
    }

    /**
     * @param  string $additionalMessage already translated; follows the message
     * @return array<string, mixed> answer()'s data
     */
    public function authenticatedButNotCapableAction( $additionalMessage = '' ) {

        $message = $this->getMsg( 2003 )['message'];

        if ( ! empty( $additionalMessage ) ) {

            $message .= ' ' . $additionalMessage;
        }

        return $this->answer( 403, $message );
    }

    /**
     * @return array<string, mixed> answer()'s data
     */
    public function updateAction() {

        return $this->answer( 503, \OWA\Core\CoreAPI::t( 'Cannot perform action. OWA Updates required.' ) );
    }

    /**
     * The first validation message.
     *
     * @return array<string, mixed> answer()'s data
     */
    public function errorAction() {

        // Controller::get() reads request params, not data.
        $messages = array_values( array_filter( (array) ( $this->data['validation_errors'] ?? [] ), 'is_string' ) );

        return $this->answer( 422, $messages[0] ?? \OWA\Core\CoreAPI::t( 'The request was not valid.' ) );
    }
}
