<?php
namespace OWA\Core\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * Rest API view
 *
 * This view assembles the response to REST API requests
 */
class RestApi extends \OWA\Core\View {
	
	function __construct() {
	 	
	 	parent::__construct();
	 	
	 	// load templates
        $this->t->set_template('wrapper_blank.php');
        
        $this->body->set_template('restApiResponse.php');
    }
    
    /**
	 * Used to set values of the response that we do not want the
	 * abstract view to worry about or have ot deal with.
	 *
	 */
    function pre() {

        // The response is JSON. It was optionally JSONP -- the same body wrapped
        // in a caller-named function so it could be loaded with a <script> tag,
        // which is a same-origin-policy bypass. The two overlays that needed it
        // now fetch over CORS, so the wrapper only widened who could read the
        // response.
        $type = 'json';

	   // set header if the request is from the API endpoint. Could be an internal request.
	   
	   if ( \OWA\Core\CoreAPI::getSetting('base', 'request_mode') === 'rest_api') {
		   
			\OWA\Core\Lib::setContentTypeHeader( $type );
			
			// set cahce-control header to avid downstream caching.
			header("Cache-Control: max-age=0");		
			
			// add CORS request headers
			$this->addCorsHeaders();
	   }

	   
		// Generate GUID for response
	    $request = \OWA\Core\CoreAPI::getRequest();

        $this->body->set('request_id', $request->guid );
	    	    
	    $error = array();
	    
	    // set error msgs
        if ( array_key_exists( 'error_msg', $this->data ) ) {
	        
            $error[] = $this->data['error_msg'];
        }
        
        if ( array_key_exists( 'validation_errors', $this->data ) ) {
	        
            $error[] = $this->data['validation_errors'];
        }
        
        $http_response = array(
	        
	        'status_code'	=> http_response_code()
        );
        
        $this->body->set('http_response', $http_response);
        $this->body->set('data', '');
        $this->body->set('error', $error);
    }
    
    /**
	 * Sets the data payload of the response
	 */
    function setResponseData( $data ) {

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

		    return $data->getPublicProperties();
	    }

	    if ( is_array( $data ) ) {

		    $out = array();

		    foreach ( $data as $k => $v ) {

			    $out[ $k ] = self::toResponseData( $v, $depth + 1 );
		    }

		    return $out;
	    }

	    return $data;
    }
    
    /**
     * Decides whether an Origin belongs to one of this installation's sites.
     *
     * Matching is on **host**, not on the stored string. Sites are commonly
     * stored with an `http://` scheme while being served over https -- both
     * production installs are -- so a browser sends an `https://` Origin and
     * comparing the stored value verbatim would refuse exactly the requests
     * this is for. Hosts are compared in full and case-insensitively; a
     * prefix or suffix match is what lets `evil-example.com` pass as
     * `example.com`, and a subdomain is a separate origin, not a child.
     *
     * Pure: no request, no database, no headers. See tests/CorsOriginMatchTest.
     *
     * @param    string    $origin    the request's Origin header
     * @param    array    $sites    site rows as getSitesList() returns them
     * @return    string|null    the Origin to echo back, or null to refuse
     */
    public static function matchAllowedOrigin( $origin, array $sites ) {

        if ( ! is_string( $origin ) || $origin === '' ) {

            return null;
        }

        $originHost = parse_url( $origin, PHP_URL_HOST );
        $scheme     = parse_url( $origin, PHP_URL_SCHEME );

        // An Origin is a scheme and a host. Anything else -- 'null', a bare
        // path, a javascript: URL -- is refused rather than coerced.
        if ( ! $originHost || ! $scheme || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {

            return null;
        }

        foreach ( $sites as $site ) {

            $domain = is_array( $site ) ? ( $site['domain'] ?? '' ) : '';

            if ( ! is_string( $domain ) || $domain === '' ) {

                continue;
            }

            /*
             * Only WEB Profiles grant an origin.
             *
             * An app Profile is identified by a bundle id, not a host, and has
             * no domain -- so it contributes nothing here and is skipped before
             * its identifier can be parsed as one. Falsy stream_type is web:
             * every Profile that predates the column is a website.
             */
            $streamType = is_array( $site ) ? ( $site['stream_type'] ?? '' ) : '';

            if ( $streamType && $streamType !== 'web' ) {

                continue;
            }

            /*
             * And only LIVE ones. An archived Profile has stopped observing, so
             * its origin should stop being allowed with it.
             */
            if ( ! empty( $site['archived_date'] ) ) {

                continue;
            }

            // A stored domain may carry a scheme or not. Without one parse_url
            // reads the whole value as a path, so give it something to parse --
            // and a value with no host at all ('owa-test-site' exists on a real
            // install) still yields nothing and is skipped.
            $candidate = strpos( $domain, '//' ) === false ? 'http://' . $domain : $domain;
            $siteHost  = parse_url( $candidate, PHP_URL_HOST );

            if ( ! $siteHost ) {

                continue;
            }

            if ( strcasecmp( $siteHost, $originHost ) === 0 ) {

                // Echo what the browser actually sent, never the stored form.
                return $origin;
            }
        }

        return null;
    }

    function addCorsHeaders() {

	    $s = \OWA\Core\CoreAPI::serviceSingleton();
	    $HTTP_ORIGIN = $s->request->getServerParam('HTTP_ORIGIN');

        // Announce that the response body and its CORS headers depend on the
        // Origin, so a shared cache cannot serve one site's allowed-origin
        // header to a request from another. Sent whether or not the Origin is
        // allowed, because the refusal is origin-dependent too. Varnish sits in
        // front of this installation, which makes it a live concern rather than
        // a formality.
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
