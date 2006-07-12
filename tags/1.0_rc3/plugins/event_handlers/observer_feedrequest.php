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

/**
 * Feed Request Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_feedrequest extends owa_observer {

	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;

	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @access 	public
	 * @return 	Log_observer_feedRequest
	 */
    function Log_observer_feedrequest($priority, $conf) {
	
        // Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer.
		$this->_event_type = array('feed_request');
		
		// Setup databse acces object
		$this->db = &owa_db::get_instance();
		
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
				
		$this->save_request();
						
		return;
	}
	
	/**
	 * Log request to database
	 * 
	 * @access 	private
	 */
	function save_request() {	
		
	
		return $this->db->query(sprintf("
					INSERT INTO
						%s
					(
						request_id,
						visitor_id, 
						session_id,
						document_id,
						subscription_id,
						site_id,
						host_id,
						os_id,
						ua_id,
						feed_reader_guid,
						timestamp,
						year,
						month,
						day,
						dayofweek,
						dayofyear,
						weekofyear,
						hour,
						minute,
						second,
						msec,
						feed_format,
						site,
						ip_address,
						host,
						os
						
					)
					VALUES (
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%s',
						'%s',
						'%d',
						'%s',
						'%s',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%s',
						'%s',
						'%s',
						'%s',
						'%s'	
					)",
						$this->config['ns'].$this->config['feed_requests_table'],
						$this->m['request_id'],
						$this->m['visitor_id'],
						$this->m['session_id'],
						$this->m['document_id'],
						$this->m['subscription_id'],
						$this->m['site_id'],
						$this->m['host_id'],
						$this->m['os_id'],
						$this->m['ua_id'],
						$this->m['feed_reader_guid'],
						$this->m['timestamp'],
						$this->m['year'],
						$this->m['month'],
						$this->m['day'],
						$this->m['dayofweek'],
						$this->m['dayofyear'],
						$this->m['weekofyear'],
						$this->m['hour'],
						$this->m['minute'],
						$this->m['second'],
						$this->m['msec'],
						$this->db->prepare($this->m['feed_format']),
						$this->db->prepare($this->m['site']),
						$this->db->prepare($this->m['ip_address']),
						$this->db->prepare($this->m['host']),
						$this->db->prepare($this->m['os'])
					));
					
	}
	
}

?>
