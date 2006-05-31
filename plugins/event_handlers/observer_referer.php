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
			//get anchortext?
			
			//get title of page
			$this->page_title = $this->get_url_title($this->obj->properties['referer']);
			
			//write to DB
			$this->save();
		
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
	 * Fetches the title of the referering web page
	 *
	 * @param string $url
	 * @param integer $timeout
	 * @return string
	 */
	function get_url_title($url, $timeout = 2) {
	
		$url = parse_url($url);

		if(!in_array($url['scheme'],array('','http')))
			return;

		$fp = @fsockopen ($url['host'], ($url['port'] > 0 ? $url['port'] : 80), &$errno, &$errstr, $timeout);
			
		if (!$fp):
       		$this->e->err('$errstr ($errno)');
			return;

   
  		else:
			fputs ($fp, "GET ".$url['path'].($url['query'] ? '?'.$url['query'] : '')." HTTP/1.0\r\nHost: ".$url['host']."\r\n\r\n");
			$d = '';

			while (!feof($fp)) {
				$d .= fgets ($fp,2048);

				if(preg_match('~(</head>|<body>|(<title>\s*(.*?)\s*</title>))~i', $d, $m))
                break;
       		}
  	    		
			fclose ($fp);

       		return $m[3];
   		endif;
   		
   		return;
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
				is_searchengine) 
			VALUES 
				('%s', '%s', '%s', '%s', '%s', '%s', '%s')",
			$this->config['ns'].$this->config['referers_table'],
			$this->obj->properties['referer_id'],
			$this->db->prepare($this->obj->properties['referer']),
			trim($this->referer_info->name, '\"'),
			$this->db->prepare($this->query_terms),
			$this->db->prepare($this->page_title),
			$this->db->prepare($this->refering_anchortext),
			$this->is_searchengine
		
			)
		);	
		
		return;
	}
	
}

?>
