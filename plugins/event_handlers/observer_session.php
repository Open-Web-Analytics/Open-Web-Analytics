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

require_once(OWA_BASE_DIR ."/owa_session_class.php");

/**
 * Session Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_session extends owa_observer {

	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @access 	public
	 * @return 	Log_observer_session
	 */
    function Log_observer_session($priority, $conf) {

        // Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer to listen for particular events.
		$this->_event_type = array('new_request', 'page_request');
     	
		return;
    }

    /**
     * Notify Handler
     *
     * @param 	object $event
     * @access 	public
     */
    function notify($event) {
    	
    	$this->e->debug('new session being handled');
	
		$this->m = $event['message'];
	
		$this->eval_request();
					
		return;
	}
	
	/**
	 * Evaluates Request to see if new session is needed or just an update
	 * Session object will trigger a new or update session event.
	 * 
	 * @access 	private
	 */
	function eval_request() {
		
		$s = new owa_session;
		
		if ($this->m['is_entry_page'] == true):
			
			$s->process_new_session($this->m);
			
		else:	
			
			$s->update_current_session($this->m);
			
		endif;		
						
		return;	
	}
		
}




?>