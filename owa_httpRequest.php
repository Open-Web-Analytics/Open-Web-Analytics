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
    var $anchor_info;

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
    function extract_anchor($link) {

        $matches = '';
        $regex = '/<a[^>]*href=\"%s\"[^>]*>(.*?)<\/a>/i';

        //$escaped_link = str_replace(array("/", "?"), array("\/", "\?"), $link);

        $pattern = trim(sprintf($regex, preg_quote($link, '/')));
        $search = preg_match($pattern, $this->getResponseBody(), $matches);
        //owa_coreAPI::debug('pattern: '.$pattern);
        //owa_coreAPI::debug('link: '.$link);


        if (empty($matches)) {
            if (substr($link, -1) === '/') {
                $link = substr($link, 0, -1);
                $pattern = trim(sprintf($regex, preg_quote($link, '/')));
                $search = preg_match($pattern, $this->getResponseBody(), $matches);
                //owa_coreAPI::debug('pattern: '.$pattern);
                //owa_coreAPI::debug('link: '.$link);
            }
        }

        owa_coreAPI::debug('ref search: '.$search);
        //owa_coreAPI::debug('ref matches: '.print_r($this->results, true));
        //owa_coreAPI::debug('ref matches: '.print_r($matches, true));
        if (isset($matches[0])) {
            $this->anchor_info =  array('anchor_tag' => $matches[0], 'anchor_text' => owa_lib::inputFilter($matches[0]));
            owa_coreAPI::debug('Anchor info: '.print_r($this->anchor_info, true));
        }
    }

    /**
     * Creates a text snippet of the portion of page where the
     * specific link is found.
     *
     * Takes fully qualified URL for the link to search for.
     *
     * @param string $link
     * @return string
     */
    function extract_anchor_snippet($link){

        // Search the page for a specific anchor
        $this->extract_anchor($link);

        if(!empty($this->anchor_info['anchor_tag'])) {

            // drop certain HTML entitities and their content
            $nohtml = $this->strip_selected_tags(
                    $this->getResponseBody(),
                    array('title',
                          'head',
                          'script',
                          'object',
                          'style',
                          'meta',
                          'link',
                          'rdf:'),
                    true);

            //owa_coreAPI::debug('Refering page content after certain html entities were dropped: '.$this->results);

            // calc len of the anchor text
            $atext_len = strlen($this->anchor_info['anchor_tag']);

            // find position within document of the anchor text
            $start = strpos($nohtml, $this->anchor_info['anchor_tag']);

            if ($start < $this->snip_len) {
                $part1_start_pos = 0;
                $part1_snip_len = $start;
            } else {
                $part1_start_pos = $start;
                $part1_snip_len = $this->snip_len;
            }

            $replace_items = array("\r\n", "\n\n", "\t", "\r", "\n");
            // Create first segment of snippet
            $first_part = substr($nohtml, 0, $part1_start_pos);
            $first_part = str_replace($replace_items, '', $first_part);
            $first_part = strip_tags(owa_lib::inputFilter($first_part));
            //$part1 = trim(substr($nohtml, $part1_start_pos, $part1_snip_len));
            $part1 = substr($first_part,-$part1_snip_len, $part1_snip_len);

            //$part1 = str_replace(array('\r\n', '\n\n', '\t', '\r', '\n'), '', $part1);
            //$part1 = owa_lib::inputFilter($part1);
            // Create second segment of snippet
            $part2 = trim(substr($nohtml, $start + $atext_len, $this->snip_len+300));
            $part2 = str_replace($replace_items, '', $part2);
            $part2 = substr(strip_tags(owa_lib::inputFilter($part2)),0, $this->snip_len);

            // Put humpty dumpy back together again and create actual snippet
            $snippet =  $this->snip_str.$part1.' <span class="snippet_anchor">'.owa_lib::inputFilter($this->anchor_info['anchor_tag']).'</span> '.$part2.$this->snip_str;

        } else {

            $snippet = '';

        }

        return $snippet;

    }

    function extract_title() {

        preg_match('/<title[^>]*>(.*?)<\/title>/', $this->getResponseBody(), $matches);

        $title = null;

        if ($matches && count($matches) > 0 && isset($matches[1])) {
            $title = $matches[1];
        }

        owa_coreAPI::debug("referrer title extract: ". print_r($title, true));

        return $title;
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
	        
	        $request = new Request('GET', $url  );
	        $this->response = $this->http->send( $request, [
		        
		        'allow_redirects' => true,
		        'exceptions' => false,
		        'headers' => [
					'User-Agent' => owa_coreAPI::getSetting('base', 'owa_user_agent')
					
				
					
				]
	        ]);
	        
	        owa_coreAPI::debug("HTTP STATUS CODE:" . $this->getResponseStatusCode() );
        }
        
        catch( \GuzzleHttp\Exception\RequestException | \GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\ClientException $e ) {
		     
		    $r = $e->getRequest();
		  	$res = null;
		  	
		  	if ( $e->hasResponse() ) {
			  	
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