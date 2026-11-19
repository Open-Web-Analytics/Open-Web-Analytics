<?php
namespace OWA\Module\Base\View;

/**
 * CORS Preflight Request Controller
 *
 * Responds to an OPTIONS preflight request made by browsers for non-simple HTTP CORS requests.
 */
class CorsPreflight extends \OWA\Core\View\RestApi {

    /**
     * The request headers a caller may send: Authorization, for an
     * application password; Content-Type, though the API reads only query
     * strings and form-encoded bodies, never JSON; and the legacy API key and
     * signature headers, so a request still sending them reaches the refusal
     * that names their replacement. Named rather than echoed back from
     * Access-Control-Request-Headers, which granted whatever a page asked for.
     */
    const ALLOWED_HEADERS = 'Authorization, X-API-KEY, X-SIGNATURE, Content-Type';

    /** The methods the API's routes answer. */
    const ALLOWED_METHODS = 'GET, POST, DELETE, OPTIONS';

    public function render() {

        /*
         * The origin is granted, or not, in RestApi::pre() -- only a tracked
         * site's origin gets Access-Control-Allow-Origin, so for any other the
         * browser refuses whatever this allows.
         */
        header( 'Access-Control-Allow-Headers: ' . self::ALLOWED_HEADERS );
        header( 'Access-Control-Allow-Methods: ' . self::ALLOWED_METHODS );
        header( 'Access-Control-Max-Age: 600' );

        $this->setResponseData( '' );
    }
}
