<?php
namespace OWA\Module\Base\Classes;


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



define('OWA_EHS_EVENT_HANDLED', 2);
define('OWA_EHS_EVENT_FAILED', 3);

/**
 * Event Dispatch
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class EventDispatch {

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

    var $queues    = array();


    /**
     * Singleton
     *
     * @static
     * @return     object
     * @access     public
     */
    public static function &get_instance() {

        static $ed;

        if ( ! $ed ) {
            $ed = new \OWA\Module\Base\Classes\EventDispatch();
        }

        return $ed;
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
     * @param     $event_name    string
     * @param    $observer    mixed can be a function name or function array
     * @return bool
     */

    function attach($event_name, $observer) {

        $id = \OWA\Core\Lib::generateRandomUid();
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
     * @param     $filter_name    string
     * @param    $observer    mixed can be a function name or function array
     * @return bool
     */

    function attachFilter($filter_name, $observer, $priority = 10) {

        // Do not attach the same observer to a filter twice. filter() chains
        // each listener's output into the next listener's input, so a duplicate
        // observer would run its transform more than once on the same value
        // (e.g. an id derivation getting hashed repeatedly). Registration of the
        // tracking-property filters happens once per logEvent(), so without this
        // guard a process that logs multiple events accumulates duplicates.
        if ( isset( $this->listenersByFilterType[$filter_name] ) ) {

            foreach ( $this->listenersByFilterType[$filter_name] as $existing_ids ) {

                foreach ( $existing_ids as $existing_id ) {

                    if ( $this->listeners[$existing_id] === $observer ) {

                        return;
                    }
                }
            }
        }

        $id = \OWA\Core\Lib::generateRandomUid();

        $this->listenersByFilterType[$filter_name][$priority][] = $id;

        $this->listeners[$id] = $observer;

    }

    /**
     * Notify
     *
     * Notifies all handlers of events in order that they were registered
     *
     * @param     $event_type    string
     * @param    $event    array
     * @return bool
     */
    /**
     * The name of whatever a listener will run.
     *
     * A listener may be [$object, 'method'], ['ClassName', 'method'] or a plain
     * function name. Only the first shape has a class to ask for, so the other
     * two have to be read rather than reflected on -- which is what notify()
     * was not doing.
     *
     * @param mixed $listener
     * @return string
     */
    private static function listenerName( $listener ) {

        if ( ! is_array( $listener ) ) {

            return is_string( $listener ) ? $listener : '(closure)';
        }

        $target = $listener[0] ?? '';

        return is_object( $target ) ? get_class( $target ) : (string) $target;
    }

    function notify($event) {

        $responses = array();
        \OWA\Core\CoreAPI::debug("Notifying listeners of ".$event->getEventType());
        //print_r($this->listenersByEventType[$event_type] );
        //print $event->getEventType();
        if (array_key_exists($event->getEventType(), $this->listenersByEventType)) {
            $list = $this->listenersByEventType[$event->getEventType()];
            //print_r($list);
            if (!empty($list)) {
                foreach ($this->listenersByEventType[$event->getEventType()] as $k => $observer_id) {

                    /*
                     * A listener is a callable, and the object half of one may
                     * be a class NAME as well as an instance -- filter() has
                     * always allowed both and says so. notify() did not: it
                     * called get_class() on it unconditionally, which is a
                     * TypeError the moment any handler is registered
                     * statically, and takes the whole event dispatch down with
                     * it rather than that one handler.
                     *
                     * Surfaced as tests/DimensionIngestionTest failing in the
                     * isolation sweep, intermittently, because whether such a
                     * handler is registered depends on which modules and
                     * settings the run happens to have.
                     */
                    $listener = $this->listeners[ $observer_id ];

                    $class = self::listenerName( $listener );

                    $responses[ $class ] = call_user_func_array( $listener, array( $event ) );

                    \OWA\Core\CoreAPI::debug( sprintf( "%s event handled by %s.",
                        $event->getEventType(), $class ) );
                }
            }
        } else {
            \OWA\Core\CoreAPI::debug("no listeners registered for this event type.");
        }

        \OWA\Core\CoreAPI::debug('EHS: Responses - '.print_r($responses, true));

        if ( in_array( OWA_EHS_EVENT_FAILED, $responses, true ) ) {
            \OWA\Core\CoreAPI::debug("EHS: Event was not handled successfully by some handlers.");
            $q = $this->getEventQueue( 'processing' );
            $q->sendMessage( $event );
            return OWA_EHS_EVENT_FAILED;
        } else {
            $event->setStatusAsHandled();
            \OWA\Core\CoreAPI::debug("EHS: Event was handled successfully by all handlers.");
            return OWA_EHS_EVENT_HANDLED;
        }

    }

    /**
     * Notify Untill
     *
     * Notifies all handlers of events in order that they were registered
     * Stops notifying after first handler returns true
     *
     * @param     $event_type    string
     * @param    $event    array
     * @return bool
     */

    function notifyUntill() {
        \OWA\Core\CoreAPI::debug("Notifying Until listener for $event_type answers");
    }

    /**
     * Filter
     *
     * Filters event by handlers in order that they were registered
     *
     * @param     $filter_name    string
     * @param    $value    array
     * @return $new_value    mixed
     */
    function filter($filter_name, $value = '') {
        \OWA\Core\CoreAPI::debug("Filtering $filter_name");

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

                    if (is_array($this->listeners[$observer_id])) {

                        $class = self::listenerName( $this->listeners[$observer_id] );

                        $method = $this->listeners[$observer_id][1];
                        $filter_method = $class . '::' . $method;
                    } else {
                        $filter_method = $this->listeners[$observer_id];
                    }



                    \OWA\Core\CoreAPI::debug(sprintf("Filter: %s. Value passed: %s", $filter_method, print_r($value, true)));
                    $value = call_user_func_array($this->listeners[$observer_id], array_slice($args,1));
                    \OWA\Core\CoreAPI::debug(sprintf("Filter: %s. Value returned: %s", $filter_method, print_r($value, true)));
                    // set filterred value as value in args for next filter
                    $args[1] = $value;
                    // debug whats going on
                    \OWA\Core\CoreAPI::debug(sprintf("%s filtered by %s.", $filter_name, $filter_method));
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
     * @param    $event_params    array
     * @param     $event_type    string
     * @depricated
     */
    function log($event_params, $event_type = '') {
        //owa_coreAPI::debug("Notifying listeners of tracking event type: $event_type");

        if (!is_a($event_params, \OWA\Module\Base\Classes\Event::class)) {
            $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
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
     * @param    $event    array
     * @return bool
     * @depricated
     */
    function asyncNotify( $event ) {

        return $this->notify( $event );
    }

    function getEventQueue( $name ) {

        return \OWA\Core\CoreAPI::getEventQueue( $name );
    }

    function eventFactory() {

        return \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
    }

    function makeEvent($type = '') {

        $event = $this->eventFactory();

        if ( $type ) {
            $event->setEventType($type);
        }

        return $event;
    }
}

?>