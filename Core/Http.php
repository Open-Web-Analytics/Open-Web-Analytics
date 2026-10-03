<?php
namespace OWA\Core;


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

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;

/**
 * Wrapper for Snoopy http request class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Http {

    /**
     * Configuration
     *
     * @var array
     */
    var $config;

    /**
     * Error handler
     *
     * @var object
     */
    var $e;

    var $http;

    var $response;
    function __construct() {
	    
	    $this->http = new Client( [
		    
		    'timeout'  => 5.0,
		    'connect_timeout'  => 5.0
	    ] );

    }
   function getRequest($url, $arguments = '') {
		
		$this->response = '';
		
		\OWA\Core\CoreAPI::debug("GET: $url");
		
        try {
	        
	        $request = new Request('GET', trim( $url )  );
	        $this->response = $this->http->send( $request, [
		        
		        'allow_redirects' => [
				    'max'             => 5,
				    'strict'          => false,
				    'referer'         => false,
				    'protocols'       => ['http', 'https'],
				    'track_redirects' => false
				],
		        'headers' => [
			        
					'User-Agent' => \OWA\Core\CoreAPI::getSetting('base', 'owa_user_agent')
				]
	        ]);
	        
	        \OWA\Core\CoreAPI::debug("HTTP STATUS CODE:" . $this->getResponseStatusCode() );
        }
        
        catch( \GuzzleHttp\Exception\RequestException | \GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\ClientException $e ) {
		     
		    $r = $e->getRequest();
		  	$res = null;
		  	
		  	if ( method_exists( $e, 'hasResponse' ) && $e->hasResponse() ) {
			  	
			  	$res = $e->getResponse();
		  	}
		  	
		  	\OWA\Core\CoreAPI::debug( 'HTTP request:', $r );
			\OWA\Core\CoreAPI::debug( 'HTTP response:', $res );
	    }
	    

        if ( $this->response ) {
	        
	        return $this->getResponseBody();
        }
    }
    
   function getResponseStatusCode() {
	    
	    if ( $this->response ) {
		 
		    return $this->response->getStatusCode();
		}
    }
    
   function getResponseBody() {

	      if ( $this->response ) {

		    return $this->response->getBody();
		}

		// Always return a string. Callers feed this straight into preg_match(),
		// preg_match_all() and trim(), which raise a PHP 8.x deprecation (and a
		// fatal TypeError under PHP 9) when handed null.
		return '';
    }
}

?>