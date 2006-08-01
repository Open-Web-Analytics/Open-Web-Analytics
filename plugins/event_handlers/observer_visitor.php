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

/**
 * User Agent Event handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_visitor extends owa_observer {

	
	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;
		
	/**
	 * Constructor
	 *
	 * @param string $priority
	 * @param array $conf
	 * @return Log_observer_referer
	 * @access public
	 */
    function Log_observer_visitor($priority, $conf) {
				
        // Call the base class constructor
        $this->owa_observer($priority);

        // Configure the observer to handle certain events types
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
    	
    	switch ($event['message']['is_new_visitor']) {
    		
    		case true:
    			$this->logNewVisitor();
    			break;
    		case false:
    			$this->updateVisitor();
    			break;
    		
    	}
		
		return;
    }
	
	
	/**
	 * Logs new visitor to Database
	 * 
	 * @access private
	 */
	function logNewVisitor() {
		
		$this->db->query(sprintf(
			"INSERT into %s (
				visitor_id,
				user_name,
				user_email,
				first_session_id,
				first_session_year,
				first_session_month,
				first_session_day,
				first_session_dayofyear,
				first_session_timestamp 
				)
			values 
				('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')",
			$this->config['ns'].$this->config['visitors_table'],
			$this->m['visitor_id'],
			$this->db->prepare($this->m['user_name']),
			$this->db->prepare($this->m['user_email']),
			$this->m['session_id'],
			$this->m['year'],
			$this->m['month'],
			$this->m['day'],
			$this->m['dayofyear'],
			$this->m['timestamp']
			)
		);	
		
		return;
	}
	
	/**
	 * Updates visitor record in Database
	 * 
	 * @access private
	 */
	function updateVisitor() {
		
		$this->db->query(sprintf(
			"UPDATE 
				%s 
			SET
				user_name = '%s',
				user_email = '%s',
				last_session_id = '%d',
				last_session_year = '%d',
				last_session_month = '%d',
				last_session_day = '%d',
				last_session_dayofyear = '%d' 
			WHERE
				visitor_id = '%d'",
			$this->config['ns'].$this->config['visitors_table'],
			$this->db->prepare($this->m['user_name']),
			$this->db->prepare($this->m['user_email']),
			$this->m['session_id'],
			$this->m['year'],
			$this->m['month'],
			$this->m['day'],
			$this->m['dayofyear'],
			$this->m['visitor_id']
			)
		);	
		
		return;
	}
	
}

?>
