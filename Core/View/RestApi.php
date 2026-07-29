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
class RestApi extends \owa_view {
	
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
	   
	   // look for jsonp callback
        $callback = $this->get('jsonpCallback');

        // if not found look on the request scope.
        if ( ! $callback ) {
            $callback = \owa_coreAPI::getRequestParam('jsonpCallback');
        }

        if ( $callback ) {
            $this->body->set('callback', $callback);
            $type = 'jsonp';
        } else {
            
            $type = 'json';
        }

	   // set header if the request is from the API endpoint. Could be an internal request.
	   
	   if ( \owa_coreAPI::getSetting('base', 'request_mode') === 'rest_api') {
		   
			\owa_lib::setContentTypeHeader( $type );
			
			// set cahce-control header to avid downstream caching.
			header("Cache-Control: max-age=0");		
			
			// add CORS request headers
			$this->addCorsHeaders();
	   }

	   
		// Generate GUID for response
	    $request = \owa_coreAPI::getRequest();

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
	    
	    $this->body->set( 'response_data', $data );
    }
    
    function addCorsHeaders() {
	    
	    $s = \owa_coreAPI::serviceSingleton();
	    $HTTP_ORIGIN = $s->request->getServerParam('HTTP_ORIGIN');
	    
	    // check for ORGIN header and bail if not found.
        if ( ! isset( $HTTP_ORIGIN ) || $HTTP_ORIGIN == '') {
	       
            return;
        }

        // Loop through sites list and add cors headers if the ORGIN header is present on the request
        foreach ( \owa_coreAPI::getSitesList() as $allowedOrigin ) {
        	
        	if ( $allowedOrigin !== $HTTP_ORIGIN ) {
	        	
            	continue;
            }
			
			// send back the allowed orgin
            header( 'Access-Control-Allow-Origin: ' . $HTTP_ORIGIN );
            
            // needed to allow cookie content to become available to the DOM.
            header( "Access-Control-Allow-Credentials: true" );
            
            // stop the loop
            break;
        }
    }
}
