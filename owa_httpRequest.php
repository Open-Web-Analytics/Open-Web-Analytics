<?php

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

class owa_http {

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

    /**
     * The length of text contained in the snippet
     *
     * @var string
     */
    var $snip_len = 100;

    /**
     * The string that is added to the beginning and
     * end of snippet text.
     *
     * @var string
     */
    var $snip_str = '...';

    /**
     * Anchor information for a particular link
     *
     * @var array
     */
    var $anchor_info = [];

    var $http;

    var $response;
    var $response_headers;
    var $response_code;

    var $request_headers;

    function __construct() {
	    
	    $this->http = new Client( [
		    
		    'timeout'  => 5.0,
		    'connect_timeout'  => 5.0
	    ] );

    }
    /**
     * Searches a fetched html document for the anchor of a specific url
     *
     * @param string $link
     */
    function extractAnchors() {
	    
	    $regex = '/<a\s[^>]*href\s*=\s*([\"\']??)(http|https[^\\1 >]*?)\\1[^>]*>s*(.*)<\/a>/simU';
	    
	    if( preg_match_all("$regex", $this->getResponseBody(), $matches, PREG_SET_ORDER ) ) {
		   
		    owa_coreAPI::debug( 'Found anchors: ' . print_r( $matches, true ) );
		    
		    return $matches;
		}
    }
    
    function extractAnchorText( $url ) {
	    
	    $anchors = $this->extractAnchors();
	    
	    $anchortext = '';
	    
	    if ( $anchors ) {
		    
		    foreach( $anchors as $match ) {
			    
		    	// match[0] = full matching <a> tag
		    	// $match[2] = link address
				// $match[3] = link text	
		        
		        //strip any HTML tags (i.e. img, span, etc)
		        if ( $match[3] ) {
			        
		        	$match[3] = trim( owa_sanitize::stripAllTags( $match[3] ) );
		        }
		        
		        // if anything is left as anchortext then use that
				if ( $match[3] && $url === $match[2] ) {
					
					$anchortext = $match[3];
	        		
					owa_coreAPI::debug('Anchor info: '.print_r($this->anchor_info, true));
					
					return owa_lib::inputFilter( $anchortext );
				}
			}
		}
    }

    function extract_title() {

        preg_match('/<title[^>]*>(.*?)<\/title>/', $this->getResponseBody(), $matches);

        $title = null;

        if ($matches && count($matches) > 0 && isset($matches[1])) {
            $title = $matches[1];
        }

        owa_coreAPI::debug("referrer title extract: ". print_r($title, true));

        return trim($title);
    }

    function strip_selected_tags($str, $tags = array(), $stripContent = false) {

       foreach ($tags as $k => $tag){
       
           if ($stripContent == true) {
                   $pattern = sprintf('#(<%s.*?>)(.*?)(<\/%s.*?>)#is', preg_quote($tag), preg_quote($tag));
               $str = preg_replace($pattern,"",$str);
           }
           $str = preg_replace($pattern, '${2}',$str);
       }
       
       return $str;
   }
   
   function getRequest($url, $arguments = '') {
		
		$this->response = '';
		
		owa_coreAPI::debug("GET: $url");
		
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
			        
					'User-Agent' => owa_coreAPI::getSetting('base', 'owa_user_agent')
				]
	        ]);
	        
	        owa_coreAPI::debug("HTTP STATUS CODE:" . $this->getResponseStatusCode() );
        }
        
        catch( \GuzzleHttp\Exception\RequestException | \GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\ClientException $e ) {
		     
		    $r = $e->getRequest();
		  	$res = null;
		  	
		  	if ( method_exists( $e, 'hasResponse' ) && $e->hasResponse() ) {
			  	
			  	$res = $e->getResponse();
		  	}
		  	
		  	owa_coreAPI::debug( print_r($r, true ) );
			owa_coreAPI::debug( print_r($res, true ) );
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
    }
}

?>