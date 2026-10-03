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

    /**
     * The next observer id.
     *
     * A COUNTER, not a random value. These ids key $this->listeners, which
     * attach() and attachFilter() share, and ids came from
     * Lib::generateRandomUid() -- time() . mt_rand(0,999999) . pid. Within one
     * process the time and the pid are fixed, so two registrations differed
     * only by a six-digit random: with a couple of hundred registrations in a
     * request the birthday bound puts a collision at a percent or two, every
     * run, at random.
     *
     * A collision OVERWRITES: $this->listeners[$id] = $observer drops whichever
     * was registered first while its id stays in listenersByEventType or
     * listenersByFilterType. Two things follow, and the quiet one is worse.
     *
     * The loud one: a filter callback reached through notify() is called with
     * one argument, because that is what a listener takes -- observed as
     * "ArgumentCountError: Too few arguments to ...lowercaseString(), 1 passed
     * and exactly 2 expected" in the isolation sweep, intermittently. The
     * comment in notify() about DimensionIngestionTest failing "intermittently,
     * because whether such a handler is registered depends on which modules and
     * settings the run happens to have" is the same collision seen from a
     * different angle.
     *
     * The quiet one: whichever observer lost the collision never runs again,
     * and nothing says so. An event handler silently stops handling.
     *
     * A counter is unique by construction, which is all an id has ever needed
     * to be here -- these never leave the process.
     */
    private $next_observer_id = 0;

    function attach($event_name, $observer) {

        $id = ++$this->next_observer_id;
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
     * @return void
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

        $id = ++$this->next_observer_id;

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
     * NAMESPACE SUBSCRIPTION. A listener may register for `foo.*` and hear every
     * event dispatched under `foo.`.
     *
     * WHY THIS EXISTS. Two different things were being called the event type: the
     * NAME a tracker sets, which is data -- it is stored in
     * owa_event_raw.event_type and is the eventName dimension -- and the key this
     * dispatcher routes on, which is plumbing shared with OWA's internal events.
     * They were the same string, and that was fine while every event name was
     * known at registration time.
     *
     * v2 broke that: a site can send an event with any legal name
     * (trackCustomEvent), so a handler that must see every tracking event cannot
     * list them, and a flat map keyed by exact name cannot express it. The first
     * attempt at this was a magic key plus a conditional in notify() asking
     * "is this a tracking event" -- which put knowledge of tracking into the
     * dispatcher and left the two meanings conflated.
     *
     * Tracking events are dispatched under `tracking.` instead -- logEvent() sets
     * the key on the event as it arrives (Event::setDispatchName) -- so "every
     * tracking event" is `tracking.*` and this mechanism knows nothing about
     * tracking, only about prefixes. v1's names were already namespaced, which is
     * where the convention comes from.
     */
    const NAMESPACE_WILDCARD = '.*';

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

    /**
     * Every listener id for a dispatch name: the exact registrations, then each
     * namespace the name falls under.
     *
     * Walks the dotted segments from the outside in, so `tracking.acme.signup`
     * is heard by `tracking.*` and by `tracking.acme.*`. De-duplicated, so a handler
     * registered both by name and by namespace runs once.
     *
     * @param  string $dispatch_name
     * @return array  observer ids, in registration order
     */
    function listenersFor( $dispatch_name ) {

        $dispatch_name = (string) $dispatch_name;

        $ids = (array) ( $this->listenersByEventType[ $dispatch_name ] ?? array() );

        $segments = explode( '.', $dispatch_name );

        // Drop the last segment: a name is not its own namespace.
        array_pop( $segments );

        $prefix = '';

        foreach ( $segments as $segment ) {

            $prefix .= $segment;

            $ids = array_merge( $ids, (array) (
                $this->listenersByEventType[ $prefix . self::NAMESPACE_WILDCARD ] ?? array() ) );

            $prefix .= '.';
        }

        return array_values( array_unique( $ids ) );
    }

    function notify($event) {

        $responses = array();
        \OWA\Core\CoreAPI::debug("Notifying listeners of ".$event->getEventType());
        //print_r($this->listenersByEventType[$event_type] );
        //print $event->getEventType();
        /*
         * The listeners for this dispatch name, plus any registered for a
         * namespace it falls under. A custom event's name belongs to the site, so
         * nothing can have registered for it by name; `tracking.*` is how a
         * handler says it wants them all.
         */
        $list = $this->listenersFor( $event->getDispatchName() );

        if ( $list ) {
            if (!empty($list)) {
                foreach ($list as $k => $observer_id) {

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

        \OWA\Core\CoreAPI::debug( 'EHS: Responses - ' . json_encode( $responses ) );

        if ( in_array( OWA_EHS_EVENT_FAILED, $responses, true ) ) {
            \OWA\Core\CoreAPI::debug("EHS: Event was not handled successfully by some handlers.");
            /*
             * Marked, not re-queued (PLAN 2.30.6). Whoever raised the event
             * decides what a failure means: a tracking event goes back to the
             * tracker-ingest intake to be retried (Classes\TrackerIngest).
             */
            $event->setStatusAsFailed();
            return OWA_EHS_EVENT_FAILED;
        } else {
            $event->setStatusAsHandled();
            \OWA\Core\CoreAPI::debug("EHS: Event was handled successfully by all handlers.");
            return OWA_EHS_EVENT_HANDLED;
        }

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



                    /*
                     * One line naming the filter and the callback, and never the
                     * value. The value is often a whole registry map, and
                     * print_r() of it ran on every install at every log level:
                     * the string is built before debug() can discard it.
                     */
                    \OWA\Core\CoreAPI::debug(sprintf("Filter %s: %s", $filter_name, $filter_method));
                    $value = call_user_func_array($this->listeners[$observer_id], array_slice($args,1));
                    // set filterred value as value in args for next filter
                    $args[1] = $value;
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

        $this->notify($event);

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