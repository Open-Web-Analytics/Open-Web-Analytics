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

if(!class_exists('Snoopy')) {
	require_once(OWA_INCLUDE_DIR.'/Snoopy.class.php');
}

require_once(OWA_HTTPCLIENT_DIR.'http.php');

/**
 * Wrapper for Snoopy http request class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
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
	
	var $crawler;
	
	var $testcrawler;
	
	var $http;
	
	var $response;
	var $response_headers;
	var $response_code;
	
	var $request_headers;
		
	function __construct() {
	
		$c = owa_coreAPI::configSingleton();
		$this->config = $c->fetch('base');
		$this->e = owa_coreAPI::errorSingleton();
		$this->crawler = new Snoopy;
		// do not allow snoopy to follow links
		$this->crawler->maxredirs = 5;
		$this->crawler->agent = owa_coreAPI::getSetting('base', 'owa_user_agent');
		//$this->crawler->agent = "Firefox";
		//owa_coreAPI::debug('hello from owa_http constructor');
		return;
	
	}
	
	function fetch($uri) {
		//owa_coreAPI::debug('hello from owa_http fetch');
		return $this->crawler->fetch($uri);
	}
	
	function testFetch($url) {
	
		$http= new http_class;
		owa_coreAPI::debug('hello owa_http testfetch method');
		/* Connection timeout */
		$http->timeout=0;
		/* Data transfer timeout */
		$http->data_timeout=0;
		/* Output debugging information about the progress of the connection */
		$http->debug=1;
		$http->user_agent = owa_coreAPI::getSetting('base', 'owa_user_agent');
		$http->follow_redirect=1;
		$http->redirection_limit=5;
		$http->exclude_address="";
		$http->prefer_curl=0;
		$arguments = array();
		$error=$http->GetRequestArguments($url,$arguments);
		$error=$http->Open($arguments);
		
		//for(;;)
		//		{
					$error=$http->ReadReplyBody($body,50000);
					if($error!="" || strlen($body)==0)
					owa_coreAPI::debug(HtmlSpecialChars($body));
		//		}
	
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
		$search = preg_match($pattern, $this->response, $matches);
		//$this->e->debug('pattern: '.$pattern);
		//$this->e->debug('link: '.$link);
		
		
		if (empty($matches)) {
			if (substr($link, -1) === '/') {
				$link = substr($link, 0, -1);
				$pattern = trim(sprintf($regex, preg_quote($link, '/')));
				$search = preg_match($pattern, $this->response, $matches);
				//$this->e->debug('pattern: '.$pattern);
				//$this->e->debug('link: '.$link);
			}
		}
		
		$this->e->debug('ref search: '.$search);
		//$this->e->debug('ref matches: '.print_r($this->results, true));
		//$this->e->debug('ref matches: '.print_r($matches, true));
		if (isset($matches[0])) {
			$this->anchor_info =  array('anchor_tag' => $matches[0], 'anchor_text' => owa_lib::inputFilter($matches[0]));
			$this->e->debug('Anchor info: '.print_r($this->anchor_info, true));
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
					$this->response, 
					array('title', 
						  'head', 
						  'script', 
						  'object', 
						  'style', 
						  'meta', 
						  'link', 
						  'rdf:'), 
					true);
			
			//$this->e->debug('Refering page content after certain html entities were dropped: '.$this->results);
		
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
		
		preg_match('~(</head>|<body>|(<title>\s*(.*?)\s*</title>))~i', $this->response, $m);
		
		$this->e->debug("referer title extract: ". print_r($m, true));
		
       	return $m[3];
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
   
   function SetupHTTP()
	{
		if(!IsSet($this->http))
		{
			$this->http = new http_class;
			$this->http->follow_redirect = 1;
			$this->http->debug = 0;
			$this->http->debug_response_body = 0;
			$this->http->html_debug = 1;
			$this->http->user_agent =  owa_coreAPI::getSetting('base', 'owa_user_agent');
			$this->http->timeout = 3;
			$this->http->data_timeout = 3;
		}
	}

	function OpenRequest($arguments, &$headers)
	{
		if(strlen($this->error=$this->http->Open($arguments)))
			return(0);
		if(strlen($this->error=$this->http->SendRequest($arguments))
		|| strlen($this->error=$this->http->ReadReplyHeaders($headers)))
		{
			$this->http->Close();
			return(0);
		}
		if($this->http->response_status!=200)
		{
			$this->error = 'the HTTP request returned the status '.$this->http->response_status;
			$this->http->Close();
			return(0);
		}
		return(1);
	}

	function GetRequestResponse(&$response)
	{
		for($response = ''; ; )
		{
			if(strlen($this->error=$this->http->ReadReplyBody($body, 500000)))
			{
				$this->http->Close();
				return(0);
			}
			if(strlen($body)==0)
				break;
			$response .= $body;
			
		}
		$this->http->Close();
		owa_coreAPI::debug('http response code: '.$this->http->response_status);
		return($response);
	}

	function getRequest($url, $arguments = '', $response = '') {
	
		$this->SetupHTTP();
		
		$this->http->GetRequestArguments($url, $arguments);
		$arguments['RequestMethod']='GET';		
		if(!$this->OpenRequest($arguments, $headers)) {
				return(0);
		}
		$this->response = $this->GetRequestResponse($response);
		return($this->response);
	}
	
}


?>