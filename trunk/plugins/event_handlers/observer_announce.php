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

require_once (OWA_BASE_DIR.'/owa_settings_class.php');

/**
 * Email Announcement Event handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class Log_observer_announce extends owa_observer {

	/**
	 * Email that mail should go to
	 *
	 * @var string
	 */
    var $_to;
    
    /**
     * Subject of email
     *
     * @var string
     */
    var $_subject;
    
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
	 * Event Message
	 *
	 * @var object
	 */
	var $m;
    
	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @return 	Log_observer_announce
	 */
    function Log_observer_announce($priority, $conf) {
        
    	// Call the base class constructor.
        $this->Log_observer($priority);

        // Configure the observer to listen for event types
		$this->_event_type = array('new_session');

		// Fetch config
		$this->config = &owa_settings::get_settings();
		
		return;
    }
	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
    	$this->m = $event['message'];

    	switch ($event['event_type']) {
    		case "new_session":
    			$this->announce_session_update();
    			break;

    	}
		
		return;
    }
    
    /**
     * Announces Session update via email
     *
     */
    function announce_session_update() {
    	$this->_subject = 'OWA New Visit';
    	$this->_to = $this->config['notice_email'];
    	mail($this->_to, 
    		 $this->_subject,
    		 sprintf('
    		 Visitor: %s
    		 Email or Username: %s | %s
    		 Host: %s
    		 City/Country: %s, %s
    		 Entry page:%s (%s)', 
    		 			$this->m->properties['visitor_id'],
    		 			$this->m->properties['user_email'],
    		 			$this->m->properties['user_name'],
    		 			$this->m->properties['host'],
    		 			$this->m->properties['city'],
    		 			$this->m->properties['country'],
    		 			$this->m->properties['first_page_title'],
    		 			$this->m->properties['first_page_uri']
    		 )
    		 
    		 );
    	return;
    }
}

?>
