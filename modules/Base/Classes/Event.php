<?php
namespace OWA\Module\Base\Classes;

/**
 * Abstract OWA Event Class
 */

class Event {

    /**
     * Event Properties: name => what the tracker or OWA set, any type
     *
     * @var array<string, mixed>
     */
    public $properties = [];

    /** @var string */
    public $eventType;

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
    public $dispatchName = '';

    /**
     * Time since last request.
     *
     * Used to tell if a new session should be created.
     *
     * @var integer $time_since_lastreq
     */
    public $time_since_lastreq;

    /**
     * Event guid
     *
     * @var string
     */
    public $guid;

    /**
     * Creation Timestamp in UNIX EPOC UTC
     *
     * @var int
     */
    public $timestamp;

    /** @var string handled, unhandled or failed */
    public $status;

    const handled = 'handled';
    const unhandled = 'unhandled';
    /** A handler returned OWA_EHS_EVENT_FAILED: not ingested, worth retrying. */
    const failed = 'failed';

    /**
     * Constructor
     * @access public
     */
    public function __construct() {

        // Set GUID for event
        $this->guid = $this->set_guid();
        $this->timestamp = time();
        //needed?
        $this->set('guid', $this->guid);
        $this->set('timestamp', $this->timestamp );
        $this->status = self::unhandled;
    }

    /**
     * @return void
     */
    public function setStatusAsHandled() {

        $this->status = self::handled;
    }

    /**
     * @return void
     */
    public function setStatusAsFailed() {

        $this->status = self::failed;
    }

    /**
     * @return int
     */
    public function getTimestamp() {

        return $this->timestamp;
    }

    /**
     * @param  string $name
     * @param  mixed  $value any type the property takes
     * @return void
     */
    public function set($name, $value) {

        $this->properties[$name] = $value;
    }

    /**
     * @param  string $name
     * @return mixed the property, any type; false when it is not set
     */
    public function get($name) {

        if(array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        } else {
            return false;
        }
    }

    /**
     * removes a property
     *
     * @param  string $name
     * @return void
     */
    public function delete( $name ) {

        if (array_key_exists( $name, $this->properties ) ) {

            unset( $this->properties[ $name ] );
        }
    }

    /**
     * Sets time related event properties
     *
     * @param  int|null $timestamp
     * @return void
     */
    public function setTime($timestamp = null) {

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

    /**
     * @param  string $domain
     * @return void
     */
    public function setCookieDomain($domain) {

        $this->properties['cookie_domain'] = $domain;
    }

    /**
     * Applies calling application specific properties to request
     *
     * @access     private
     * @param     array<string, mixed>|null $properties
     * @return    void
     */
    public function setProperties($properties = null) {

        if(!empty($properties)) {

            if (empty($this->properties)) {
                $this->properties = $properties;
            } else {
                $this->properties = array_merge($this->properties, $properties);
            }
        }
    }

    /**
     * Exports Event Class variables
     *
     * @return     array<string, mixed> member name => its value
     */
     public function export() {

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
    private static $remote_settable = [
        'eventType',
        'properties',
        'guid',
        'timestamp',
    ];

    /**
     * Loads Event class variables from an array.
     *
     * @param  array<string, mixed> $vars member name => value
     * @return void
     */
     public function loadFromArray ( $vars ) {

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
            // Lib::randomId()'s digits and the time is unix seconds.
            // Anything else is not an event this install made.
            if ( ( $name === 'guid' || $name === 'timestamp' )
                 && ! ( is_int( $value ) || is_string( $value ) && ctype_digit( $value ) ) ) {
                continue;
            }

            $this->$name = $value;
        }
    }

    /**
     * @param  array<string, mixed> $properties
     * @return void
     */
    public function replaceProperties($properties) {

        $this->properties = $properties;
    }

    /**
     * Create guid from process id
     *
     * @return    string
     * @access     private
     */
    public function set_guid() {

        return \OWA\Core\Lib::randomId();
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties() {

        return $this->properties;
    }

    /**
     * What this event IS. Read from the class member and nowhere else.
     *
     * NOT FROM THE PROPERTY BAG. EventRawHandlers::announce() copies a beacon's
     * properties onto a fresh notice, so a base.new_session notice carries an
     * event_type property reading "page_view"; read from the bag, it would be
     * dispatched as a page view. The same holds for every internal event that
     * inherits properties from one that came off the wire.
     *
     * So an event's type comes from its MEMBER, set at construction (makeEvent),
     * at the endpoint (log.php) or by logEvent(). The property is what the beacon
     * SENT, which is what the column records -- data, not identity.
     *
     * @return string
     */
    public function getEventType() {

        return ! empty( $this->eventType ) ? $this->eventType : 'unknown_event_type';
    }

    /**
     * @param  string $value
     * @return void
     */
    public function setEventType($value) {
        $this->eventType = $value;
    }

    /**
     * Route this event under a dispatch key. Called by logEvent().
     *
     * @param  string $value
     * @return void
     */
    public function setDispatchName( $value ) {

        $this->dispatchName = (string) $value;
    }

    /**
     * The dispatch key, falling back to the event type.
     *
     * An event OWA raises internally never had one set and its name has always
     * been its routing key, so the fallback is the existing behaviour rather than
     * a new default.
     *
     * @return string
     */
    public function getDispatchName() {

        return $this->dispatchName !== '' ? $this->dispatchName : $this->getEventType();
    }

    /**
     * Did this event arrive from a tracker? True once logEvent() has named it.
     *
     * @return bool
     */
    public function isTrackingEvent() {

        return $this->dispatchName !== '';
    }

    /**
     * @param  string $value
     * @return void
     */
    public function setSiteId($value) {

        $this->set('siteId', $value);
        $this->set('site_id', $value);
    }

    /**
     * @return mixed siteId, else site_id, as set; false when neither is
     */
    public function getSiteId() {

        if ( $this->get('siteId') ) {
            return $this->get('siteId');
        } else {
            return $this->get('site_id');
        }


    }

    /**
     * @return string
     */
    public function getGuid() {

        return $this->guid;
    }

    /**
     * @return string
     */
    public function getStatus() {

        return $this->status;
    }

}
