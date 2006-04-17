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
 * Comment Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_comments extends owa_observer {
	
	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Event Messsage
	 *
	 * @var object
	 */
	var $m;
	
	/**
	 * Constructor
	 *
	 * @param string $priority
	 * @param array $conf
	 * @access 	public
	 * @return Log_observer_request_logger
	 */
    function Log_observer_comments($priority, $conf) {
	
        /* Call the base class constructor. */
        $this->Log_observer($priority);

        /* Configure the observer. */
		$this->_event_type = array('new_comment');
		
		return;
    }

    /**
     * Notify method called by event queue
     *
     * @param array $event
     * @access 	public
     */
    function notify($event) {
	
		$this->m = $event['message'];
		
		$this->config = &owa_settings::get_settings();
				
		$this->update_session();
						
		return;
	}
	
	/**
	 * Update session with comment
	 * 
	 * @access 	private
	 */
	function update_session() {	
	
		$this->db = &owa_db::get_instance();
	
		$result = $this->db->query(
     		 sprintf(
				"UPDATE
					%s
				 SET 
					num_comments = num_comments + 1
				 WHERE
					session_id = '%s'",
					$this->config['ns'].$this->config['sessions_table'],
					$this->m->properties['inbound_session_id']
      		)
    	);
		
		return;
	}
	
}

?>
