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

require_once(OWA_BASE_DIR ."/owa_db.php");
require_once(OWA_BASE_DIR ."/owa_settings_class.php");

/**
 * Request Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_request_logger extends owa_observer {

	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Event
	 *
	 * @var object
	 */
	var $m;

	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @access 	public
	 * @return 	Log_observer_request_logger
	 */
    function Log_observer_request_logger($priority, $conf) {
	
        // Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer.
		$this->_event_type = array('new_request', 'feed_request');
		
		return;
    }

    /**
     * Notify Handler
     *
     * @access 	public
     * @param 	object $event
     */
    function notify($event) {
		
		$this->m = $event['message'];
				
		$this->insert_request();
		$this->insert_document();
						
		return;
	}
	
	/**
	 * Log request to database
	 * 
	 * @access 	private
	 */
	function insert_request() {	
		
		// Setup databse acces object
		$this->db = &owa_db::get_instance();
	
		$request = array(
					'request_id',
					'visitor_id', 
					'session_id',
					'inbound_visitor_id', 
					'inbound_session_id',
					'inbound_first_hit_properties',
					'user_name',
					'user_email',
					'timestamp',
					'last_req',
					'year',
					'month',
					'day',
					'dayofweek',
					'dayofyear',
					'weekofyear',
					'hour',
					'minute',
					'second',
					'msec',
					'feed_subscription_id',
					'referer_id',
					'document_id',
					'site',
					'site_id',
					'ip_address',
					'host',
					'host_id',
					'os',
					'os_id',
					'ua_id',
					'is_new_visitor',
					'is_repeat_visitor',	
					'is_comment',
					'is_entry_page',
					'is_browser',
					'is_robot',
					'is_feedreader'
					);
					
			foreach ($request as $key => $value) {
			
				$sql_cols = $sql_cols.$value;
				$sql_values = $sql_values."'".$this->m[$this->db->prepare($value)]."'";
				
				if (!empty($request[$key+1])):
				
					$sql_cols = $sql_cols.", ";
					$sql_values = $sql_values.", ";
					
				endif;	
			}
						
			$this->db->query(
				sprintf(
					"INSERT into %s (%s) VALUES (%s)",
					$this->config['ns'].$this->config['requests_table'],
					$sql_cols,
					$sql_values
				)
			);	
				
		return;
	}
	
	/**
	 * Adds document data to documents table.
	 * 
	 * @access private
	 */
	function insert_document() {
	
		$this->db->query(
				sprintf(
					"INSERT into %s (id, url, page_title, page_type) VALUES ('%s', '%s', '%s', '%s')",
					$this->config['ns'].$this->config['documents_table'],
					$this->m['document_id'],
					$this->m['uri'],
					$this->m['page_title'],
					$this->m['page_type']
				)
			);	
		return;
	}
	
}

?>
