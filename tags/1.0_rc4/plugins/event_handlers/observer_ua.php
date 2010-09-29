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
class Log_observer_ua extends owa_observer {

	/**
	 * Browser type
	 *
	 * @var string
	 */
    var $browser_type;
	
	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Operating System
	 *
	 * @var unknown_type
	 */
	var $os;
	
	/**
	 * Constructor
	 *
	 * @param string $priority
	 * @param array $conf
	 * @return Log_observer_referer
	 * @access public
	 */
    function Log_observer_ua($priority, $conf) {
				
        // Call the base class constructor
        $this->owa_observer($priority);

        // Configure the observer to handle certain events types
		$this->_event_type = array('new_session', 'feed_request');
	
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
		$this->save();

		return;
    }
	
	
	/**
	 * Save user agent to the database
	 * 
	 * @access private
	 */
	function save() {
		
		$this->db->query(sprintf(
			"INSERT into %s (
				id, 
				ua, 
				browser_type)
			values 
				('%s', '%s', '%s')",
			$this->config['ns'].$this->config['ua_table'],
			$this->m['ua_id'],
			$this->db->prepare($this->m['ua']),
			$this->db->prepare($this->m['browser_type'])
			
			)
		);	
		
		return;
	}
	
}

?>