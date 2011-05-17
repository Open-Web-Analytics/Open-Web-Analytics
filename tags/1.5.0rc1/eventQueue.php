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

if (!class_exists('owa_event') ) {
	require_once(OWA_BASE_CLASS_DIR.'event.php');
}

define('OWA_EHS_EVENT_HANDLED', 2);
define('OWA_EHS_EVENT_FAILED', 3);

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
	var $listeners = array();
	
	/**
	 * Stores listener IDs by event type
	 *
	 */
	var $listenersByEventType = array();
	
	/**
	 * Stores listener IDs by event type
	 *
	 */
	var $listenersByFilterType = array();
	
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
	 * Attach
	 *
	 * Attaches observers by filter type.
	 * Takes a valid user defined callback function for use by PHP's call_user_func_array
	 * 
	 * @param 	$filter_name	string
	 * @param	$observer	mixed can be a function name or function array
	 * @return bool
	 */

	function attachFilter($filter_name, $observer, $priority = 10) {
	
        $id = md5(microtime());
        
        $this->listenersByFilterType[$filter_name][$priority][] = $id;
		
        $this->listeners[$id] = $observer;
               
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
		
		$responses = array();
		owa_coreAPI::debug("Notifying listeners of ".$event->getEventType());
		//print_r($this->listenersByEventType[$event_type] );
		//print $event->getEventType();
		if (array_key_exists($event->getEventType(), $this->listenersByEventType)) {
			$list = $this->listenersByEventType[$event->getEventType()];
			//print_r($list);
			if (!empty($list)) {
				foreach ($this->listenersByEventType[$event->getEventType()] as $k => $observer_id) {
					//print_r($list);
					$class = get_class( $this->listeners[$observer_id][0] );
					$responses[ $class ] = call_user_func_array($this->listeners[$observer_id], array($event));
					//owa_coreAPI::debug(print_r($event, true));
					owa_coreAPI::debug(sprintf("%s event handled by %s.",$event->getEventType(), get_class($this->listeners[$observer_id][0])));
				}
			}
		} else {
			owa_coreAPI::debug("no listeners registered for this event type.");
		}	
		
		owa_coreAPI::debug('EHS: Responses - '.print_r($responses, true));
		
		if ( in_array( OWA_EHS_EVENT_FAILED, $responses, true ) ) {
			owa_coreAPI::debug("EHS: Event was not handled successfully by some handlers.");
			//$q = $this->getAsyncEventQueue(owa_coreAPI::getSetting('base', 'event_queue_type'));
			//$q->addToQueue($event);
			return OWA_EHS_EVENT_FAILED;
		} else {
			owa_coreAPI::debug("EHS: Event was handled successfully by all handlers.");
			return OWA_EHS_EVENT_HANDLED;
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
	 * @param 	$filter_name	string
	 * @param	$value	array
	 * @return $new_value	mixed
	 */
	function filter($filter_name, $value = '') {
		owa_coreAPI::debug("Filtering $filter_name");
		
		if (array_key_exists($filter_name, $this->listenersByFilterType)) {
			// sort the filter list by priority
			ksort($this->listenersByFilterType[$filter_name]);
			//get the function arguments
			$args = func_get_args();
			// outer priority loop
			foreach ($this->listenersByFilterType[$filter_name] as $priority) {
				// inner filter class/function loop
				foreach ($priority as $observer_id) {
					// pass args to filter
					owa_coreAPI::debug(sprintf("Filter: %s::%s. Value passed: %s", get_class($this->listeners[$observer_id][0]),$this->listeners[$observer_id][1], print_r($value, true)));
					$value = call_user_func_array($this->listeners[$observer_id], array_slice($args,1));
					owa_coreAPI::debug(sprintf("Filter: %s::%s. Value returned: %s", get_class($this->listeners[$observer_id][0]),$this->listeners[$observer_id][1], print_r($value, true)));
					// set filterred value as value in args for next filter
					$args[1] = $value;
					// debug whats going on
					owa_coreAPI::debug(sprintf("%s filtered by %s.", $filter_name, get_class($this->listeners[$observer_id][0])));
				}
			}
		}
		
		return $value;
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
		
		$this->asyncNotify($event);
			
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
		
		// check config to see if async mode is enabled, if not fall back to realtime notification
		if (owa_coreAPI::getSetting('base', 'queue_events')) {
			owa_coreAPI::debug(sprintf("Adding event of %s to async %s queue.", $event->getEventType(), owa_coreAPI::getSetting('base', 'event_queue_type')));
			// check to see first if OWA is not already acting as a remote event queue, 
			// then check to see if we are configured to use a remote or local event queue
			// then see if we have an endpoint
			if (!owa_coreAPI::getSetting('base', 'is_remote_event_queue') && 
			    owa_coreAPI::getSetting('base', 'use_remote_event_queue') &&
			    owa_coreAPI::getSetting('base', 'remote_event_queue_type') &&
			    owa_coreAPI::getSetting('base', 'remote_event_queue_endpoint')) {
			    // get a network queue
				$q = $this->getAsyncEventQueue(owa_coreAPI::getSetting('base', 'remote_event_queue_type'));
			// use a local event queue
			} else {
				// get a local event queue
				$q = $this->getAsyncEventQueue(owa_coreAPI::getSetting('base', 'event_queue_type'));
			}
			
			// if an event queue is returned then pass it the event
			if ($q) {
			
				return $q->addToQueue($event);
			// otherwise skip the queue and just notify the listeners immeadiately. 
			} else {
				return $this->notify($event);
			}
			
		// otherwise skip the queue and just notify the listeners immeadiately. 	
		} else {
			return $this->notify($event);
		}	
	}
	
	function getAsyncEventQueue($type) {
	
		static $q = array();
		
		if ( ! array_key_exists( $type, $q ) ) {
			
			switch( $type ) {
				
				case 'http':
					$q['http'] = owa_coreAPI::supportClassFactory( 'base', 'httpEventQueue' );
					break;
				case 'database':
					$q['database'] = owa_coreAPI::supportClassFactory( 'base', 'dbEventQueue' );
					break;
				case 'file':
					$q['file'] = owa_coreAPI::supportClassFactory( 'base', 'fileEventQueue' );
					break;
			}
		}		
		
		if ( array_key_exists( $type, $q ) ) {
			return $q[$type];
		} else {
			owa_coreAPI::debug('No event queue of that type exists.'); 
			return false;
		}
	}
	
	function eventFactory() {
		
		return owa_coreAPI::supportClassFactory('base', 'event');
	}
	
	function makeEvent($type = '') {
		
		$event = $this->eventFactory();
		
		if ($type) {
			$event->setEventType($type);
		}
		
		return $event;
	}
	
/*
	function processEventQueue($processing_queue_type = '') {
		
		// get the primary async queue
		
		// get an item from the queue
		
		// send to the notify method
		
		// check return status
		
		// mark item accordingly
	}
*/

	/**
	 * Singleton
	 *
	 * @static 
	 * @return 	object
	 * @access 	public
	 */
	public static function &get_instance() {
	
		static $eq;
		
		if (empty($eq)) {
			$eq = new eventQueue();
		}
	
		return $eq;
	}

}

?>