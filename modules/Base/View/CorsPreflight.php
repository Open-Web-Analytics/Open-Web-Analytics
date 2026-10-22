<?php
namespace OWA\Module\Base\View;


/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */


/**
 * CORS Preflight Request Controller
 * 
 * Responds to an OPTIONS preflight request made by browsers for non-simple HTTP CORS requests. 
 *
 */


/**
 * cors preflight response.
 * 
 */
class CorsPreflight extends \OWA\Core\View\RestApi {

    /**
     * The request headers the API reads: an application password, an API key
     * and its signature, and a JSON body. Named rather than echoed back from
     * Access-Control-Request-Headers, which granted whatever a page asked for.
     */
    const ALLOWED_HEADERS = 'Authorization, X-API-KEY, X-SIGNATURE, Content-Type';

    /** The methods the API's routes answer. */
    const ALLOWED_METHODS = 'GET, POST, DELETE, OPTIONS';

    function render() {

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

?>
