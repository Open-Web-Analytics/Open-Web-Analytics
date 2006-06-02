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
     * Message Object
     *
     * @var unknown_type
     */
	var $obj;
	
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
	 * Refering Site Info
	 *
	 * @var object
	 */
	var $referer_info;
	
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
		
    	$this->obj = $event['message'];
		$this->process_referer();

		return;
    }
	
    /**
     * Process the request for the referer
     *
     * @access private
     */
	function process_referer() {
			
			//	Look for match against Search engine groups
			$this->referer_info = $this->get_referer_info($this->obj->properties['referer']);
		
			//	Look for query_terms
			
			if (strstr($this->obj->properties['referer'], $this->obj->properties['site']) == false):
				$this->query_terms = strtolower($this->get_query_terms($this->obj->properties['referer']));
				
				if (!empty($this->query_terms)):
					$this->is_searchengine = true;
				endif;
			endif;
			
			$this->save();
			
			if ($this->config['fetch_refering_page_info'] = true):
				
				$this->crawler = new owa_http;
				$this->crawler->fetch($this->obj->properties['referer']);
				$this->snippet = $this->crawler->extract_anchor_snippet($this->obj->properties['inbound_uri']);
				//$this->e->debug('Referering Snippet is: '. $this->snippet);
				$this->anchor_text = $this->crawler->anchor_info['anchor_text'];
				//$this->e->debug('Anchor text is: '. $this->anchor_text);
				$this->page_title = $this->crawler->extract_title();
				//write to DB
				$this->update();
			
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
	function get_referer_info($referer) {
	
		/*	Look for match against Search engine groups */
		$db = new ini_db($this->obj->config['search_engines.ini'], $sections = true);
		return $db->fetch($referer);
	
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
		$db = new ini_db($this->obj->config['query_strings.ini']);
		
		return urldecode($db->match($referer));
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
				site_name, 
				query_terms, 
				page_title, 
				refering_anchortext, 
				snippet,
				is_searchengine) 
			VALUES 
				('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')",
			$this->config['ns'].$this->config['referers_table'],
			$this->obj->properties['referer_id'],
			$this->db->prepare($this->obj->properties['referer']),
			trim($this->referer_info->name, '\"'),
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
				snippet = '%s'
			WHERE
				id = '%s'",
			$this->config['ns'].$this->config['referers_table'],
			$this->db->prepare($this->page_title),
			$this->db->prepare($this->anchor_text),
			$this->db->prepare($this->snippet),
			$this->obj->properties['referer_id']		
			)
		);	
		
		return;
	}
	
}

?>
