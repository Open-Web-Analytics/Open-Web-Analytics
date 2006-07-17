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

require_once(OWA_BASE_DIR ."/owa_host.php");
require_once(OWA_BASE_DIR ."/owa_db.php");

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
class Log_observer_host extends owa_observer {

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
	 * @return 	Log_observer_request_logger
	 */
    function Log_observer_host($priority, $conf) {
	
        // Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer.
		$this->_event_type = array('new_session');
		
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
				
		$h = new owa_host;
				
		$h->properties['ip_address'] = $this->m['ip_address'];
		$h->properties['host'] = $this->m['host'];
		$h->properties['full_host'] = $this->m['full_host'];
		$h->properties['host_id'] = $this->m['host_id'];
		$h->save();
			
		return;
	}
	
}

?>
