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

require_once(OWA_BASE_DIR.'/ini_db.php');
require_once(OWA_BASE_DIR.'/owa_httpRequest.php');

/**
 * Referer Traffic Source Event handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_referer extends owa_observer {

	/**
	 * Site name
	 *
	 * @var string
	 */
    var $site_name;
    
    /**
	 * Site
	 *
	 * @var string
	 */
    var $site;
	
	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Title of refering page
	 *
	 * @var unknown_type
	 */
	var $title;
	
	/**
	 * Search Engine Info
	 *
	 * @var object
	 */
	var $se_info;
	
	/**
	 * Keywords
	 *
	 * @var unknown_type
	 */
	var $query_terms;
	
	/**
	 * Is Search Engine Flag
	 *
	 * @var boolean
	 */
	var $is_searchengine;
	
	/**
	 * Web crawler instance
	 *
	 * @var object
	 */
	var $crawler;
	
	/**
	 * Snippet of page where the link that refered the user was found
	 *
	 * @var unknown_type
	 */
	var $snippet;
	
	/**
	 * Anchor text of the link that refered the user.
	 *
	 * @var unknown_type
	 */
	var $anchor_text;
	
	/**
	 * Constructor
	 *
	 * @param string $priority
	 * @param array $conf
	 * @return Log_observer_referer
	 * @access public
	 */
    function Log_observer_referer($priority, $conf) {
				
        // Call the base class constructor
        $this->owa_observer($priority);

        // Configure the observer
		$this->_event_type = array('new_session');
	
		$this->db = &owa_db::get_instance();
		
		return;
    }

    /**
     * Event Notification
     *
     * @param unknown_type $event
     */
    function notify($event) {
		
    	$this->m = $event['message'];
    	
    	if (!empty($this->m['referer'])):
			$this->process_referer();
		endif;
		
		return;
    }
	
    /**
     * Process the request for the referer
     *
     * @access private
     */
	function process_referer() {
			
			//	Look for match against Search engine groups
			$this->se_info = $this->get_se_info($this->m['referer']);
		
			$url = parse_url($this->m['referer']);
			
			$this->site = $url[host];
			
			//	Look for query_terms
			
			if (strstr($this->m['referer'], $this->m['site']) == false):
				$this->query_terms = strtolower($this->get_query_terms($this->m['referer']));
				
				if (!empty($this->query_terms)):
					$this->is_searchengine = true;
				endif;
			endif;
			
			// Save referer to DB
			$this->save();
			
			// Crawl and analyze refering page
			if ($this->config['fetch_refering_page_info'] == true):
				//But not if it's a search engine...
				if ($this->is_searchengine == false):
					
					$this->crawler = new owa_http;
					$this->crawler->fetch($this->m['referer']);
					$this->snippet = $this->crawler->extract_anchor_snippet($this->m['inbound_uri']);
					//$this->e->debug('Referering Snippet is: '. $this->snippet);
					$this->anchor_text = $this->crawler->anchor_info['anchor_text'];
					//$this->e->debug('Anchor text is: '. $this->anchor_text);
					$this->page_title = $this->crawler->extract_title();
					//write to DB
					$this->update();
				
				endif;
			
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
	function get_se_info($referer) {
	
		/*	Look for match against Search engine groups */
		$db = new ini_db($this->config['search_engines.ini'], $sections = true);
		
		$se_info = $db->fetch($referer);
		
		if (!empty($se_info['name'])):
			$this->is_searchengine = true;
		endif;
		
		return $se_info;
	
	}
	
	/**
	 * Parses query terms from referer
	 *
	 * @param string $referer
	 * @return string
	 * @access private
	 */
	function get_query_terms($referer) {
	
		/*	Look for query_terms */
		$db = new ini_db($this->config['query_strings.ini']);
		
		$match = $db->match($referer);
		return urldecode($match[1]);
	}

	/**
	 * Save row to the database
	 * 
	 * @access private
	 */
	function save() {
		
		$this->db->query(sprintf(
			"INSERT into %s (
				id, 
				url, 
				site,
				site_name, 
				query_terms, 
				page_title, 
				refering_anchortext, 
				snippet,
				is_searchengine) 
			VALUES 
				('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')",
			$this->config['ns'].$this->config['referers_table'],
			$this->m['referer_id'],
			$this->db->prepare($this->m['referer']),
			$this->db->prepare($this->site),
			trim($this->se_info->name, '\"'),
			$this->db->prepare($this->query_terms),
			$this->db->prepare($this->page_title),
			$this->db->prepare($this->anchor_text),
			$this->db->prepare($this->snippet),
			$this->is_searchengine
		
			)
		);	
		
		return;
	}
	
	function update() {
		
		$this->db->query(sprintf(
			"UPDATE %s 
			SET 
				page_title = '%s', 
				refering_anchortext = '%s', 
				snippet = '%s',
				site = '%s',
				is_searchengine = '%s'
			WHERE
				id = '%s'",
			$this->config['ns'].$this->config['referers_table'],
			$this->db->prepare($this->page_title),
			$this->db->prepare($this->anchor_text),
			$this->db->prepare($this->snippet),
			$this->db->prepare($this->site),
			$this->is_searchengine,
			$this->m['referer_id']		
			)
		);	
		
		return;
	}
	
}

?>
