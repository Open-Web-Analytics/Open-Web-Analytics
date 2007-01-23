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
 * OWA user password reset Event handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class Log_observer_password_reset extends owa_observer {
    
	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @return 	Log_observer_announce
	 */
    function Log_observer_password_reset($priority, $conf) {
        
    	// Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer to listen for event types
		$this->_event_type = array('base.set_password', 'base.reset_password', 'base.new_user_account');
		
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
    		case "base.reset_password":
    			$this->handleEvent('base.usersResetPassword');
    			break;
    		case "base.set_password":
    			$this->handleEvent('base.usersSetPassword');
    			break;
    		case "base.new_user_account":
    			$this->handleEvent('base.usersNewAccount');
    			break;	
    	}
		
		return;
    }
    
}

?>
