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

require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_lib.php');
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_controller.php');
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_coreAPI.php');
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'ini_db.php');
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_httpRequest.php');


/**
 * Log Referer Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_logRefererController extends owa_controller {
	
	function owa_logRefererController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
	
		// Make entity
		$r = owa_coreAPI::entityFactory('base.referer');
		
		// set referer url
		$r->set('url', $this->params['HTTP_REFERER']);
		
		// check for search engine
		if ($this->lookupSearchEngine($this->params['HTTP_REFERER']) == true):
			$r->set('is_searchengine', true);
		endif;
		
		// Set site
		$url = parse_url($this->params['HTTP_REFERER']);
		$r->set('site', $url['host']);
		
		//	Look for query_terms
		if (strstr($this->params['HTTP_REFERER'], $this->params['HTTP_HOST']) == false):
			$qt = $this->extractSearchTerms($this->params['HTTP_REFERER']);
			if (!empty($qt)):
				$r->set('query_terms', strtolower($qt));
				$r->set('is_searchengine', true);
			endif;
		endif;
		
		// Set id
		$r->set('id', owa_lib::setStringGuid($this->params['HTTP_REFERER']));
		
		// Persist to database
		$r->create();
		
		// Crawl and analyze refering page
			if ($this->config['fetch_refering_page_info'] == true):
					
				$crawler = new owa_http;
				$crawler->fetch($this->params['HTTP_REFERER']);
				
				//Extract anchortext and page snippet but not if it's a search engine...
				if ($r->is_searchengine->value == false):
					$r->set('snippet', $crawler->extract_anchor_snippet($this->params['inbound_page_url']));
					//$this->e->debug('Referering Snippet is: '. $this->snippet);
					$r->set('refering_anchortext', $crawler->anchor_info['anchor_text']);
					//$this->e->debug('Anchor text is: '. $this->anchor_text);
				endif;
					
				//Extract Title
				$r->set('page_title', $crawler->extract_title());
					
				//write to DB
				$r->update();
				
			endif;
		
			
		return;
			
	}
	
	/**
	 * Lookup info about referring domain 
	 *
	 * @param string $referer
	 * @return object
	 * @access private
	 */
	function lookupSearchEngine($referer) {
	
		/*	Look for match against Search engine groups */
		$db = new ini_db($this->config['search_engines.ini'], $sections = true);
		
		$se_info = $db->fetch($referer);
		
		if (!empty($se_info->name)):
			return true;
		else:
			return false;
		endif;
			
	}
	

	/**
	 * Parses query terms from referer
	 *
	 * @param string $referer
	 * @return string
	 * @access private
	 */
	function extractSearchTerms($referer) {
	
		/*	Look for query_terms */
		$db = new ini_db($this->config['query_strings.ini']);
		
		$match = $db->match($referer);
		
		if (!empty($match[1])):
		
			return urldecode($match[1]);
		
		endif;
		
		return;
	}
	
	
}

?>