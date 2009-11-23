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

if (!class_exists('owa_observer')) {

	require_once(OWA_BASE_CLASSES_DIR. 'owa_observer.php');
}

/**
 * Event Dispatcher
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class eventQueue {
	
	/**
	 * Stores listeners
	 *
	 */
	var $listeners;
	
	/**
	 * Stores listener IDs by event type
	 *
	 */
	var $lstenersByEventType;
	
	/**
	 * PHP4 Constructor
	 *
	 */
	function eventQueue() {
 
		return eventQueue::__construct();	
	}
	
	/**
	 * Constructor
	 *
	 */
	function __construct() {
	
	}
	
	/**
	 * Attach
	 *
	 * Attaches observers by event type.
	 * Takes a valid user defined callback function for use by PHP's call_user_func_array
	 * 
	 * @param 	$event_name	string
	 * @param	$observer	mixed can be a function name or function array
	 * @return bool
	 */

	function attach($event_name, $observer) {
	
        $id = md5(microtime());
        
        // Register event names for this handler
		if(is_array($event_name)) {
			
			foreach ($event_name as $k => $name) {	
	
				$this->listenersByEventType[$name][] = $id;
			}
			
		} else {
		
			$this->listenersByEventType[$event_name][] = $id;	
		}
		
        $this->listeners[$id] = $observer;
               
        return true;
    }

	/**
	 * Notify
	 *
	 * Notifies all handlers of events in order that they were registered
	 * 
	 * @param 	$event_type	string
	 * @param	$event	array
	 * @return bool
	 */
	function notify($event) {
		
		owa_coreAPI::debug("Notifying listeners of ".$event->getEventType());
		//print_r($this->listenersByEventType[$event_type] );
		
		$list = $this->listenersByEventType[$event->getEventType()];
		if (!empty($list)) {
				foreach ($this->listenersByEventType[$event->getEventType()] as $k => $observer_id) {
				//print_r($this->listeners[$observer_id]);
				call_user_func_array($this->listeners[$observer_id], array($event));
			}
		}
		
		
	}
	
	/**
	 * Notify Untill
	 *
	 * Notifies all handlers of events in order that they were registered
	 * Stops notifying after first handler returns true
	 * 
	 * @param 	$event_type	string
	 * @param	$event	array
	 * @return bool
	 */

	function notifyUntill() {
		owa_coreAPI::debug("Notifying Until listener for $event_type answers");
	}
	
	/**
	 * Filter
	 *
	 * Filters event by handlers in order that they were registered
	 * 
	 * @param 	$event_type	string
	 * @param	$event	array
	 * @return $event	mixed
	 */
	function filter($event_type, $event) {
		owa_coreAPI::debug("Filtering $event_type");
		return;
	}
	
	/**
	 * Log
	 *
	 * Notifies handlers of tracking events
	 * Provides switch for async notification
	 * 
	 * @param	$event_params	array
	 * @param 	$event_type	string
	 */
	function log($event_params, $event_type = '') {
		//owa_coreAPI::debug("Notifying listeners of tracking event type: $event_type");
		
		if (!is_a($event_params,'owa_event')) {
			$event = owa_coreAPI::supportClassFactory('base', 'event');
			$event->setProperties($event_params);
			$event->setEventType($event_type);
		} else {
			$event = $event_params;
		}
		
		//switch for async event queuing
		if (owa_coreAPI::getSetting('base', 'async_db')) {
			$this->asyncNotify($event);
		} else {
			$this->notify($event);
		}	
	}
	
	/**
	 * Async Notify
	 *
	 * Adds event to async notiication queue for notification by another process.
	 * 
	 * @param	$event	array
	 * @return bool
	 */
	function asyncNotify($event) {
		owa_coreAPI::debug("Adding event of $event_type to async notification queue.");
		$q = $this->getAsyncEventQueue();
		return $q->log($event, $event->getEventType());
	}
	
	function getAsyncEventQueue() {
		
		static $q;
		
		if (!empty($q)) {
			
			require_once(OWA_PEARLOG_DIR . DIRECTORY_SEPARATOR . 'Log.php');
			require_once(OWA_PLUGIN_DIR . 'log/queue.php');
			require_once(OWA_PLUGIN_DIR . 'log/async_queue.php');
			
			$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
			$q = &Log::singleton('async_queue', $this->config['async_log_dir'].$this->config['async_log_file'], 'async_event_queue', $conf);
			$q->_lineFormat = '%1$s|*|%2$s|*|[%3$s]|*|%4$s|*|%5$s';
			// not sure why this is needed but it is.
			$q->_filename	= $this->config['async_log_dir'].$this->config['async_log_file'];
		}
		
		return $q;
		
	}

	/**
	 * Singleton
	 *
	 * @static 
	 * @return 	object
	 * @access 	public
	 */
	function &get_instance() {
	
		static $eq;
		
		if (empty($eq)) {
			$eq = new eventQueue();
		}
	
		return $eq;
	}

}

?>