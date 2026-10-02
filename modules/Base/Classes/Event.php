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

/**
 * Abstract OWA Event Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Event {

    /**
     * Event Properties
     *
     * @var array
     */
    var $properties = array();

    /**
     * State
     *
     * @var string
     */
    //var $state;

    var $eventType;

    /**
     * The key the listener system routes this event on -- NOT its name.
     *
     * `event_type` is what the tracker set and what a report groups by: it is
     * stored in owa_event_raw.event_type and read by the eventName dimension. The
     * dispatch key is plumbing, shared with OWA's internal events
     * (install_complete, base.set_password). They were the same string, which
     * worked while every event name was known at registration time -- and v2 ended
     * that, because a site can send an event with any legal name and a handler
     * wanting them all has nothing to enumerate.
     *
     * SET ONCE, ON THE WAY IN, by CoreAPI::logEvent(): the only entry point that
     * knows a tracker sent this. Nothing downstream derives it. Two earlier
     * attempts did derive it and both were wrong -- inferring "is this a tracking
     * event" from the NAME matches install_complete, which replaced the install
     * handler with ingest; and deriving it from a flattened name turned a dotted
     * type into a different dispatch key and routed it past its own handler.
     *
     * A CLASS VAR, deliberately not in $properties: the property bag is the wire
     * surface, and OWA's routing state does not belong in it. Survives the queue
     * the way eventType does, the event being serialised whole.
     *
     * @var string
     */
    var $dispatchName = '';

    /**
     * Time since last request.
     *
     * Used to tell if a new session should be created.
     *
     * @var integer $time_since_lastreq
     */
    var $time_since_lastreq;

    /**
     * Event guid
     *
     * @var string
     */
    var $guid;

    /**
     * Creation Timestamp in UNIX EPOC UTC
     *
     * @var int
     */
    var $timestamp;

    var $status;

    const handled = 'handled';
    const unhandled = 'unhandled';
    /** A handler returned OWA_EHS_EVENT_FAILED: not ingested, worth retrying. */
    const failed = 'failed';

    /**
     * Constructor
     * @access public
     */
    function __construct() {

        // Set GUID for event
        $this->guid = $this->set_guid();
        $this->timestamp = time();
        //needed?
        $this->set('guid', $this->guid);
        $this->set('timestamp', $this->timestamp );
        $this->status = self::unhandled;
    }

    function setStatusAsHandled() {

        $this->status = self::handled;
    }

    function setStatusAsFailed() {

        $this->status = self::failed;
    }

    function getTimestamp() {

        return $this->timestamp;
    }

    function set($name, $value) {

        $this->properties[$name] = $value;
    }

    function get($name) {

        if(array_key_exists($name, $this->properties)) {
            //print_r($this->properties[$name]);
            return $this->properties[$name];
        } else {
            return false;
        }
    }

    /**
     * removes a property
     */
    function delete( $name ) {

        if (array_key_exists( $name, $this->properties ) ) {

            unset( $this->properties[ $name ] );
        }
    }

    /**
     * Sets time related event properties
     *
     * @param integer $timestamp
     */
    function setTime($timestamp = null) {

        if ( $timestamp ) {
            $this->set('timestamp', $timestamp);
        } else {
            $timestamp = $this->getTimestamp();
        }

        $this->set('timestamp', $timestamp);
        $this->set('year', date("Y", $timestamp));
        $this->set('month', date("Ym", $timestamp));
        $this->set('day', date("d", $timestamp));
        $this->set('yyyymmdd', date("Ymd", $timestamp));
        $this->set('dayofweek', date("D", $timestamp));
        $this->set('dayofyear', date("z", $timestamp));
        $this->set('weekofyear', date("W", $timestamp));
        $this->set('hour', date("G", $timestamp));
        $this->set('minute', date("i", $timestamp));
        $this->set('second', date("s", $timestamp));

        //epoc time
        list($msec, $sec) = explode(" ", microtime());
        $this->set('sec', $sec);
        $this->set('msec', $msec);

    }

    function setCookieDomain($domain) {

        $this->properties['cookie_domain'] = $domain;
    }

    /**
     * Applies calling application specific properties to request
     *
     * @access     private
     * @param     array $properties
     */
    function setProperties($properties = null) {

        if(!empty($properties)) {

            if (empty($this->properties)) {
                $this->properties = $properties;
            } else {
                $this->properties = array_merge($this->properties, $properties);
            }
        }
    }

    /**
     * Adds new properties to the eventt without overwriting values
     * for properties that are already set.
     *
     * @param     array $properties
     */
    function setNewProperties( $properties = array() ) {

        $this->properties = array_merge($properties, $this->properties);

    }

    /**
     * Exports Event Class variables
     *
     * @return     array
     */
     function export() {

         return get_object_vars( $this );
     }

    /**
     * What loadFromArray() may set, and nothing else.
     *
     * Its caller is the tracker-ingest drain rebuilding an event from a queued
     * envelope (TrackerIngest::event()), and a queue's contents are only as
     * trustworthy as whatever can write to it. So it sets the event's name,
     * properties, guid and time -- the last two validated below -- and none
     * of the object's other state.
     *
     * @var string[]
     */
    private static $remote_settable = array(
        'eventType',
        'properties',
        'guid',
        'timestamp',
    );

    /**
     * Loads Event class variables from an array.
     *
     * @param  array $vars
     */
     function loadFromArray ( $vars ) {

        if ( ! is_array( $vars ) ) {
            return;
        }

         $has = get_object_vars( $this );

        foreach ($has as $name => $oldValue ) {

            if ( ! in_array( $name, self::$remote_settable, true ) ) {
                continue;
            }

            if ( ! isset( $vars[ $name ] ) ) {
                continue;
            }

            $value = $vars[ $name ];

            // guid and timestamp are numeric by construction: the guid is
            // Lib::generateRandomUid()'s digits and the time is unix seconds.
            // Anything else is not an event this install made.
            if ( ( $name === 'guid' || $name === 'timestamp' )
                 && ! ( is_int( $value ) || is_string( $value ) && ctype_digit( $value ) ) ) {
                continue;
            }

            $this->$name = $value;
        }
    }

    function replaceProperties($properties) {

        $this->properties = $properties;
    }

    /**
     * Create guid from process id
     *
     * @return    integer
     * @access     private
     */
    function set_guid() {

        return \OWA\Core\Lib::generateRandomUid();
    }

    /**
     * Create guid from string
     *
     * @param     string $string
     * @return     integer
     * @access     private
     */
    function set_string_guid($string) {

        return crc32(strtolower($string));

    }

    /**
     * Attempts to make a unique ID out of http request variables.
     * This should only be used when storing state in a cookie is impossible.
     *
     * @return integer
     */
    function setEnvGUID() {

        return crc32( $this->get('ua') . $this->get('ip_address') );

    }

    function getProperties() {

        return $this->properties;
    }

    /**
     * What this event IS. Read from the class member and nowhere else.
     *
     * IT USED TO FALL BACK TO THE PROPERTY BAG. For a TRACKING event that looked
     * harmless -- the beacon really does carry event_type, the endpoint admits it,
     * and owa_event_raw.event_type stores it -- so the bag legitimately holds it
     * and the registry still declares it.
     *
     * The fallback was wrong for everything ELSE. EventRawHandlers::announce()
     * copies a beacon's properties onto a fresh notice, so a base.new_session
     * notice carries an event_type property reading "page_view"; it routed
     * correctly only because this method happened to prefer the member. Any read
     * that took the bag first dispatched a session announcement as a page view.
     * The same shape applies to every internal event that inherits properties from
     * one that came off the wire.
     *
     * So an event's type comes from its MEMBER, set at construction (makeEvent),
     * at the endpoint (log.php) or by logEvent(). The property is what the beacon
     * SENT, which is what the column records -- data, not identity.
     *
     * @return string
     */
    function getEventType() {

        return ! empty( $this->eventType ) ? $this->eventType : 'unknown_event_type';
    }

    function setEventType($value) {
        $this->eventType = $value;
    }

    /** Route this event under a dispatch key. Called by logEvent(). */
    function setDispatchName( $value ) {

        $this->dispatchName = (string) $value;
    }

    /**
     * The dispatch key, falling back to the event type.
     *
     * An event OWA raises internally never had one set and its name has always
     * been its routing key, so the fallback is the existing behaviour rather than
     * a new default.
     */
    function getDispatchName() {

        return $this->dispatchName !== '' ? $this->dispatchName : $this->getEventType();
    }

    /** Did this event arrive from a tracker? True once logEvent() has named it. */
    function isTrackingEvent() {

        return $this->dispatchName !== '';
    }

    function cleanProperties() {

        return $this->setProperties(\OWA\Core\Lib::inputFilter($this->getProperties()));
    }

    function setPageTitle($value) {

        $this->set('page_title', $value);
    }

    function setSiteId($value) {

        $this->set('siteId', $value);
        $this->set('site_id', $value);
    }

    function getSiteId() {

        if ( $this->get('siteId') ) {
            return $this->get('siteId');
        } else {
            return $this->get('site_id');
        }


    }

    function setPageType($value) {

        $this->set('page_type', $value);
    }

    function getGuid() {

        return $this->guid;
    }

    function getSiteSpecificGuid($site_id) {

        return \OWA\Core\Lib::generateRandomUid();
    }

    function getStatus() {

        return $this->status;
    }

}

?>