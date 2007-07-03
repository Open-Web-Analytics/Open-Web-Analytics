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
class owa_requestHandlers extends owa_observer {

	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @access 	public
	 * @return 	Log_observer_request_logger
	 */
    function owa_requestHandlers() {
	
        // Call the base class constructor.
        
        $this->owa_observer();
		
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
    	$this->handleEvent('base.logPageRequest');
    	
    	/*switch ($event['event_type']) {
	    	case "base.page_request":
	    		$this->handleEvent('base.logPageRequest');
	    	break;
	    	case "base.first_page_request":
	    		$this->handleEvent('base.logPageRequest');
	    	break;
    	
    	}*/
    
		return;
	}
	
}

?>
