<?php
namespace OWA\Core\View;

/**
 * Rest API view
 *
 * This view assembles the response to REST API requests
 */
class RestApi extends \OWA\Core\View {
    
    public function __construct() {
        
        parent::__construct();
        
        // load templates
        $this->t->set_template('wrapper_blank.php');
        
        $this->body->set_template('restApiResponse.php');
    }
    
    /**
     * Used to set values of the response that we do not want the
     * abstract view to worry about or have ot deal with.
     *
     * @return void
     */
    public function pre() {

        // The response is JSON, never JSONP: a body wrapped in a caller-named
        // function is a same-origin-policy bypass. The overlays fetch over CORS.
        $type = 'json';

       // set header if the request is from the API endpoint. Could be an internal request.
       
       if ( \OWA\Core\RequestType::is( \OWA\Core\RequestType::REST )) {
           
            \OWA\Core\Lib::setContentTypeHeader( $type );
            
            // set cahce-control header to avid downstream caching.
            header("Cache-Control: max-age=0");

            // add CORS request headers
            $this->addCorsHeaders();
       }

       
        // Generate GUID for response
        $request = \OWA\Core\CoreAPI::getRequest();

        $this->body->set('request_id', $request->guid );
                
        $error = [];
        
        // set error msgs
        if ( array_key_exists( 'error_msg', $this->data ) ) {
            
            $error[] = $this->data['error_msg'];
        }
        
        if ( array_key_exists( 'validation_errors', $this->data ) ) {
            
            $error[] = $this->data['validation_errors'];
        }
        
        $http_response = [
            
            'status_code'   => http_response_code()
        ];
        
        $this->body->set('http_response', $http_response);
        $this->body->set('data', '');
        $this->body->set('error', $error);
    }
    
    /**
     * The response's data: the value of the key a controller named with restResponse().
     *
     * @return void
     */
    public function render() {

        if ( ! empty( $this->data['response_key'] ) ) {

            $this->setResponseData( $this->get( $this->data['response_key'] ) );
        }
    }

    /**
     * Sets the data payload of the response
     *
     * @param  mixed $data the payload, any type: an entity in it is reduced to its public properties
     * @return void
     */
    public function setResponseData( $data ) {

        $this->body->set( 'response_data', self::toResponseData( $data ) );
    }

    /**
     * Reduces entities to their public properties before they are serialized.
     *
     * Handed an entity, json_encode() would otherwise emit every public member it
     * happens to have -- the property bag, but also _tableProperties, wasPersisted,
     * cache and dirty, none of which are API surface. Worse, the payload becomes
     * whatever the entity currently holds, so a newly added sensitive column ships
     * the moment it exists unless someone remembers to strip it at the call site.
     *
     * Doing it here rather than in each view makes the safe path the default one:
     * an entity cannot reach a response without passing through this.
     *
     * Anything that is not an entity is returned untouched, so views that already
     * assemble plain arrays are unaffected.
     *
     * @param  mixed $data
     * @param  int   $depth  Guards against a cyclic graph; entity payloads are
     *                       shallow, so exceeding this means something is wrong.
     * @return mixed
     */
    protected static function toResponseData( $data, $depth = 0 ) {

        if ( $depth > 10 ) {

            return null;
        }

        if ( $data instanceof \OWA\Core\Entity ) {

            return self::toPropertyBag( $data );
        }

        if ( is_array( $data ) ) {

            $out = [];

            foreach ( $data as $k => $v ) {

                $out[ $k ] = self::toResponseData( $v, $depth + 1 );
            }

            return $out;
        }

        return $data;
    }

    /**
     * An entity as { properties: { <column>: { value: ... } } }.
     *
     * The shape the REST API publishes: consumers, the WordPress plugin among
     * them, read properties.<col>.value.
     *
     * Built FROM getPublicProperties() rather than from the raw property bag:
     * internals (_tableProperties, wasPersisted, cache, dirty) never enter, and
     * a private column -- a user's password -- is dropped before this sees it.
     *
     * Only 'value' is emitted per column, so no schema detail rides along in a
     * public payload.
     *
     * @return array{properties: array<string, array{value: mixed}>} a column's value is of the column's type
     */
    protected static function toPropertyBag( \OWA\Core\Entity $entity ) {

        $properties = [];

        foreach ( (array) $entity->getPublicProperties() as $column => $value ) {

            $properties[ $column ] = [ 'value' => $value ];
        }

        return [ 'properties' => $properties ];
    }
    
    /**
     * Decides whether an Origin belongs to one of this installation's sites.
     *
     * The rule is the overlays' (ProfileDomains::originOf()): an http or https
     * origin whose host is a live web Profile's domain or a subdomain of it.
     * The heatmap launcher and the player open pages by the same rule, so a
     * page an overlay opens on is answered here. Hosts are compared in full and
     * case-insensitively, without a port and a leading `www.`; `evil-example.com`
     * is not `example.com`.
     *
     * Pure: no request, no database, no headers. See tests/CorsOriginMatchTest.
     *
     * @param    mixed    $origin    the request's Origin header as read: anything
     *                               but a non-empty string is refused
     * @param    array<mixed> $sites  site rows as getSitesList() returns them;
     *                               an element that is not an array is skipped
     * @return    string|null    the Origin to echo back, or null to refuse
     */
    public static function matchAllowedOrigin( $origin, array $sites ) {

        if ( ! is_string( $origin ) || $origin === '' ) {

            return null;
        }

        $allowed = \OWA\Module\Base\Classes\ProfileDomains::originOf( $origin,
            \OWA\Module\Base\Classes\ProfileDomains::ofSites( $sites ) );

        // Echo what the browser actually sent, never the stored form.
        return $allowed === '' ? null : $origin;
    }

    /**
     * @return void
     */
    public function addCorsHeaders() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $HTTP_ORIGIN = $s->request->getServerParam('HTTP_ORIGIN');

        // Announce that the response body and its CORS headers depend on the
        // Origin, so a shared cache cannot serve one site's allowed-origin
        // header to a request from another. Sent whether or not the Origin is
        // allowed, because the refusal is origin-dependent too.
        header( 'Vary: Origin', false );

        // no Origin means this is not a cross-origin request.
        if ( ! $HTTP_ORIGIN ) {

            return;
        }

        $allowed = self::matchAllowedOrigin( $HTTP_ORIGIN, \OWA\Core\CoreAPI::getSitesList() );

        if ( ! $allowed ) {

            // Send nothing. The browser refuses the response, which is the
            // correct outcome for an origin this installation does not serve.
            return;
        }

        header( 'Access-Control-Allow-Origin: ' . $allowed );

        // needed to allow cookie content to become available to the DOM.
        header( 'Access-Control-Allow-Credentials: true' );
    }
}
