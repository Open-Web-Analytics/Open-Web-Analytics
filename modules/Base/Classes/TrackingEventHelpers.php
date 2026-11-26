<?php
namespace OWA\Module\Base\Classes;


/**
 * @phpstan-type Definition array<string, mixed>
 * @phpstan-type EventAttributes array<mixed>
 */
class TrackingEventHelpers {

    /**
     * Declared types whose values are trimmed before they reach the event.
     *
     * The text-ish ones. '' is here because a property may omit data_type, and
     * setDataType() treats that as a string. json is excluded: whitespace inside
     * a document is the encoder's business, and boolean/integer never carry any.
     */
    const TRIMMED_TYPES = [ 'string', 'url', '' ];

    /** OS families that only ship on a phone, and only on a desktop. */
    const MOBILE_OS = [ 'android', 'ios', 'windows phone', 'blackberry os',
                             'firefox os', 'kaios', 'harmonyos' ];

    const DESKTOP_OS = [ 'windows', 'mac os x', 'macos', 'linux', 'ubuntu',
                              'chrome os', 'fedora', 'debian', 'freebsd' ];

    /**
     * The label a v1 column carries when there is no value.
     *
     * Declared as default_value on tracking properties. It is applied when a
     * value is STORED, not when an event is built -- see setTrackerProperties()
     * and Entity::setProperties(). The reporting layer produces it for a NULL
     * dimension at render time as well.
     */
    const ABSENT_VALUE_LABEL = '(not set)';


    // incoming tracking event control flow:
    // 0. create event
    // 0. translate request property keys
    // 0. set all properties from request
    // 0. set environmental properties if not present
    // 0. queue or notify event

    // in event handler...
    // 0. register all filters callbacks for required, derived, and optional properties
    // 0. deconstruct custom variable properties
    // 0. filter existing event properties
    // 0. filter/set required event properties
    // 0. filter/set derived event properties
    // 0. handler specific logic

    /**
     * @return TrackingEventHelpers
     */
    public static function getInstance() {

        if ( ! self::$instance ) {

            self::$instance = new \OWA\Module\Base\Classes\TrackingEventHelpers();
        }

        return self::$instance;
    }

    /** @var TrackingEventHelpers|null getInstance() */
    private static $instance = null;

    public function __construct() {




    }


    /**
     * @var array<string, bool> guard against re-registering the same property
     * derivation filter. setTrackerProperties() runs on every logEvent() (3x
     * per event: environmental/regular/derived maps), but those maps are static
     * config, so a given (property, callback) filter must be attached only ONCE
     * per process. Re-attaching chains the derivation into itself: because
     * eventDispatch::filter() feeds each listener's output into the next, a
     * derived dimension id (generateDimensionId) would get setStringGuid()'d
     * once per copy, corrupting the fact row's FK columns on the 2nd+ event of
     * any process that logs more than one (queue/batch workers, CLI, tests).
     */
    private $registeredCallbacks = [];

    /** @var array<string, Definition>|null the parsed config, read once per process */
    private static $property_config;

    /** @var array<string, string> property name => its `description`, kept aside from the definitions */
    private static $property_descriptions = [];

    /** @var string[] the properties declared `internal`, which the wiki leaves out */
    private static $internal_properties = [];

    /**
     * Views of the registry, built once per process: it is read from a file
     * and never changes at runtime. Rebuilt per call, the v1 migration spent
     * 2.8 ms per event here, most of its time.
     *
     * @var array<string, mixed>
     */
    private static $memo = [];

    /**
     * The derived first-class event names, built once per process.
     *
     * @var list<string>|null
     */
    private static $event_names = null;

    /** @var array<string, EventAttributes>|null config/tracking_events.php, memoised by eventConfig(). */
    private static $event_config = null;

    /** The three answers to "how does this property's value get set". */
    const SET_BY = [ 'client', 'request', 'event' ];

    /** What an `events` list holds when the property rides every beacon. */
    const EVERY_EVENT = '*';

    /**
     * The registry, filtered to one kind of setter.
     *
     * Every entry says how its value gets set:
     *
     *   client   the tracker sends it, and `from` holds the transport keys. The
     *            only names a beacon may carry are these, which makes admission
     *            a property of the registry rather than of a list maintained
     *            beside it.
     *   request  the server reads it off the request, and `from` holds the
     *            $_SERVER keys in the precedence they are tried -- or `clock`.
     *   event    derived from other properties, and `from` names them.
     *
     * ONE `from`, THREE NAMESPACES, and `set_by` says which: a wire key, a
     * request key, or a property name. It is a SET because several of them
     * genuinely are: ip_address tries five $_SERVER keys in order, and an event
     * property can derive from more than one.
     *
     * ORDER IS PART OF THE CONTRACT. setTrackerProperties() applies properties
     * in the order they appear, and a callback reads whatever is already on the
     * event. TrackingPropertyOrderTest holds this.
     *
     * `column` is the raw column the value lands in, spelled out on every entry
     * that has one (columnFor()).
     *
     * @param  string $set_by  one of SET_BY
     * @return array<string, Definition>  name => definition
     */
    private static function propertyConfig( $set_by ) {

        if ( self::$property_config === null ) {

            $path = \OWA\Core\Env::modulesDir() . 'Base/config/tracking_properties.json';
            $config = json_decode( file_get_contents( $path ), true );

            if ( ! is_array( $config ) ) {

                throw new \RuntimeException(
                    'Could not read the tracking property config at ' . $path . ': '
                    . json_last_error_msg() );
            }

            /* 'note' documents the entry for whoever edits the file,
               'description' for the wiki, and 'internal' keeps a property out
               of the wiki; none is part of the definition the pipeline
               consumes. */
            foreach ( $config as $property => $definition ) {

                self::$property_descriptions[ $property ] = (string) ( $definition['description'] ?? '' );

                if ( ! empty( $definition['internal'] ) ) {

                    self::$internal_properties[] = $property;
                }

                unset( $config[ $property ]['note'], $config[ $property ]['description'], $config[ $property ]['internal'] );
            }

            self::$property_config = $config;
        }

        if ( ! in_array( $set_by, self::SET_BY, true ) ) {

            throw new \InvalidArgumentException(
                "There is no '$set_by' setter in the tracking property config." );
        }

        if ( isset( self::$memo['set_by'][ $set_by ] ) ) {

            return self::$memo['set_by'][ $set_by ];
        }

        $out = [];

        foreach ( self::$property_config as $property => $definition ) {

            if ( ( $definition['set_by'] ?? '' ) === $set_by ) {

                $out[ $property ] = $definition;
            }
        }

        return self::$memo['set_by'][ $set_by ] = $out;
    }

    /**
     * Every declaration, whatever sets it.
     *
     * @return array<string, Definition>
     */
    public static function allProperties() {

        if ( isset( self::$memo['all'] ) ) {

            return self::$memo['all'];
        }

        $out = [];

        foreach ( self::SET_BY as $set_by ) {

            $out = array_merge( $out, self::propertyConfig( $set_by ) );
        }

        return self::$memo['all'] = $out;
    }

    /**
     * The raw column a property lands in, or '' when it lands in none.
     *
     * SPELLED OUT ON EVERY ENTRY, never inferred from the property's name: many
     * properties have no column, and a name-based guess would answer columns that
     * do not exist.
     *
     * @param  string $property
     * @return string  '' when the property reaches no column
     */
    public static function columnFor( $property ) {

        $all = self::allProperties();

        return (string) ( $all[ $property ]['column'] ?? '' );
    }

    /**
     * What a property holds, in a sentence for an administrator, or '' when
     * the property declares no description.
     *
     * The `description` key of the property's entry. It is not part of the
     * definition the pipeline consumes, so propertyConfig() strips it from the
     * definitions and keeps it here; the generated wiki reads it.
     *
     * @param  string $property
     * @return string
     */
    public static function descriptionFor( $property ) {

        self::allProperties();

        return self::$property_descriptions[ $property ] ?? '';
    }

    /**
     * Whether the property's entry is `internal`: what spam detection reads off
     * the request, say. It works like any other property; the generated wiki
     * does not list it.
     *
     * @param  string $property
     * @return bool
     */
    public static function isInternal( $property ) {

        self::allProperties();

        return in_array( $property, self::$internal_properties, true );
    }

    /**
     * The declared data type of a property, or '' when it declares none.
     *
     * Read by the row builder to know that a `json` property arrives as a STRING
     * and has to be decoded before it is nested in the params document -- see
     * EventRawHandlers::params().
     *
     * @param  string $property
     * @return string
     */
    public static function dataTypeFor( $property ) {

        $all = self::allProperties();

        return isset( $all[ $property ]['data_type'] )
            ? (string) $all[ $property ]['data_type'] : '';
    }

    /**
     * The params key a property lands under, or '' when it is not a param.
     *
     * The other half of the destination. A property lands in a column, or in the
     * params document, or nowhere -- and the third case is a real answer: the
     * clock anchors, the marker flags and the landing URL are consumed during
     * ingest and stored by nothing.
     *
     * The KEY, not the property name, because they differ: ct_gateway is reached
     * as params.gateway, ct_line_items as params.items. That difference is what
     * the row builder's hand-written per-event list got wrong -- it named the
     * column spellings while the wire sent the ct_ ones, so every purchase stored
     * its currency and no amount.
     *
     * @param  string $property
     * @return string
     */
    public static function paramFor( $property ) {

        $all = self::allProperties();

        return (string) ( $all[ $property ]['param'] ?? '' );
    }

    /**
     * The params an event of this name carries, as property name => params key.
     *
     * Read from the registry, so the wire name, the params key and the event
     * scoping are one declaration.
     *
     * @param  string $event_name
     * @return array<string, string>  property name => params key
     */
    public static function paramsForEvent( $event_name ) {

        if ( isset( self::$memo['params'][ (string) $event_name ] ) ) {

            return self::$memo['params'][ (string) $event_name ];
        }

        $out = [];

        foreach ( self::propertiesForEvent( $event_name ) as $property ) {

            $key = self::paramFor( $property );

            if ( $key !== '' ) {

                $out[ $property ] = $key;
            }
        }

        return self::$memo['params'][ (string) $event_name ] = $out;
    }

    /**
     * The transport keys a property may arrive under. Empty when it cannot
     * arrive at all.
     *
     * A `client` property's `from` set is the wire; every other kind's names
     * something the request or the event supplies, and a beacon carrying one of
     * those names is asserting a value it has no business setting -- its own
     * city, its own region, its own campaign source. Having no client
     * declaration is the refusal; there is no denylist.
     *
     * @param  string $property
     * @return string[]
     */
    public static function wireKeysFor( $property ) {

        $all = self::allProperties();

        $definition = $all[ $property ] ?? [];

        return ( ( $definition['set_by'] ?? '' ) === 'client' )
            ? (array) ( $definition['from'] ?? [] )
            : [];
    }

    /**
     * The properties an event of this name may carry.
     *
     * Three namespaces nearly align here: the wire, the raw row, and the dimension
     * vocabulary. This says which properties belong to which event, so a goal
     * condition written against a property no row of that event carries can be
     * refused at save time.
     *
     * `events` in the config is that statement: `["*"]` is every event, a list is
     * only those events. file_name is a property of file_download and of nothing
     * else.
     *
     * @param  string $event_name  a v2 event name
     * @return string[]            property names, sorted
     */
    public static function propertiesForEvent( $event_name ) {

        $event_name = (string) $event_name;

        if ( $event_name === '' ) {

            throw new \InvalidArgumentException(
                'An event name is required; there is no vocabulary without one.' );
        }

        if ( isset( self::$memo['events'][ $event_name ] ) ) {

            return self::$memo['events'][ $event_name ];
        }

        $out = [];

        foreach ( self::SET_BY as $scope ) {

            foreach ( self::propertyConfig( $scope ) as $name => $definition ) {

                /*
                 * `["*"]` is every event, a list is only those, and an empty list is none.
                 *
                 * An absent key is tolerated and also means every event, so a module shipping
                 * its own registry is not broken by the rule; nothing in core relies on it, and
                 * the format test refuses an entry that omits the key.
                 */
                if ( ! array_key_exists( 'events', $definition ) ) {

                    $out[ $name ] = true;

                    continue;
                }

                $events = (array) $definition['events'];

                if ( ! in_array( self::EVERY_EVENT, $events, true )
                     && ! in_array( $event_name, $events, true ) ) {

                    continue;
                }

                /*
                 * NOT COPIED TO A MATERIALIZED EVENT. Its values come from the
                 * event that carried the flag, and a property that describes
                 * that event itself -- engagement time accrued, whether it met a
                 * goal -- says nothing about the materialized one. Copied, the
                 * carrier's engagement time would be summed three times.
                 */
                if ( array_key_exists( 'materialize', $definition )
                     && ! $definition['materialize']
                     && self::isMaterialized( $event_name ) ) {

                    continue;
                }

                $out[ $name ] = true;
            }
        }

        $out = array_keys( $out );

        sort( $out );

        return self::$memo['events'][ $event_name ] = $out;
    }

    /**
     * Every first-class event name, from config/tracking_events.php.
     *
     * DECLARED, NOT DERIVED from the property registry: a declared event may carry
     * no property of its own (user_engagement carries only the common set).
     *
     * eventNamesFromProperties() computes the union of the registry's `events`
     * lists as a CHECK: a test asserts the registry implies nothing that is not
     * declared.
     *
     * @return string[]  sorted
     */
    public static function eventNames() {

        /*
         * MEMOISED, because this is on the beacon path twice: logEvent()'s gate and
         * ProcessEvent::isTrackingEvent() both ask. propertyConfig() memoises the file
         * read, so the source is fixed once loaded and this adds no staleness.
         */
        if ( self::$event_names !== null ) {

            return self::$event_names;
        }

        $out = array_keys( self::eventConfig() );

        sort( $out );

        self::$event_names = $out;

        return $out;
    }

    /**
     * The first-class events a goal event definition may be triggered by.
     *
     * Every one, except those config/tracking_events.php declares
     * `goal_trigger` => false: an event that reports on the site rather than
     * on what a visitor did, such as `exception`.
     *
     * @return string[]  sorted
     */
    public static function goalTriggerEventNames() {

        // Memoised for marking, which asks per beacon; the config is fixed once read.
        if ( self::$goal_trigger_names !== null ) {

            return self::$goal_trigger_names;
        }

        $out = [];

        foreach ( self::eventConfig() as $name => $declaration ) {

            if ( ( $declaration['goal_trigger'] ?? true ) !== false ) {

                $out[] = (string) $name;
            }
        }

        sort( $out );

        return self::$goal_trigger_names = $out;
    }

    /**
     * What a first-class event is, in the locale in use: its `description` in
     * config/tracking_events.php, which the strings catalogue carries. '' for
     * an event that declares none, and for a site's own.
     *
     * @param  string $name
     * @return string
     */
    public static function eventDescription( $name ) {

        $description = (string) ( self::eventConfig()[ (string) $name ]['description'] ?? '' );

        return $description !== '' ? \OWA\Core\Strings::translate( $description ) : '';
    }

    /** @var string[]|null goalTriggerEventNames(), once computed */
    private static $goal_trigger_names = null;

    /**
     * The first-class events that are MATERIALIZED at ingest rather than sent.
     *
     * @return string[]  sorted
     */
    public static function materializedEventNames() {

        $out = [];

        foreach ( self::eventConfig() as $name => $attributes ) {

            if ( ! empty( $attributes['materialized'] ) ) {

                $out[] = $name;
            }
        }

        sort( $out );

        return $out;
    }

    /**
     * Is this event name materialized at ingest rather than sent?
     *
     * @param  string $event_name
     * @return bool
     */
    public static function isMaterialized( $event_name ) {

        return in_array( (string) $event_name, self::materializedEventNames(), true );
    }

    /**
     * config/tracking_events.php, read once: event name => attributes.
     *
     * @return array<string, EventAttributes>
     */
    private static function eventConfig() {

        if ( self::$event_config !== null ) {

            return self::$event_config;
        }

        $path = \OWA\Core\Env::modulesDir() . 'Base/config/tracking_events.php';

        $declared = file_exists( $path ) ? include $path : null;

        if ( ! is_array( $declared ) || ! $declared ) {

            throw new \RuntimeException(
                'Could not read the first-class event list at ' . $path
                . '. Every gate on what a tracker may send reads it, so an empty '
                . 'list would refuse every first-class event rather than fail here.' );
        }

        $out = [];

        foreach ( $declared as $name => $attributes ) {

            if ( ! is_string( $name ) || ! is_array( $attributes ) ) {

                throw new \RuntimeException( sprintf(
                    'config/tracking_events.php must map each event name to an array '
                    . 'of attributes; entry %s does not.', var_export( $name, true ) ) );
            }

            $out[ $name ] = $attributes;
        }

        self::$event_config = $out;

        return $out;
    }

    /**
     * A materialized event, built from the event that carried its flag.
     *
     * A materialized event never arrives on a beacon, so every value it holds is
     * the carrier's: the ones the registry declares for THIS name, which
     * propertiesForEvent() answers -- `*` properties and the ones naming it, less
     * any declared `"materialize": false`. Nothing is re-derived; the callbacks
     * already ran on the carrier, and re-running them would parse the agent and
     * look up the address again for the same request.
     *
     * The carrier's own measurements (a click's target, a scroll's depth, a
     * purchase's revenue) are not declared for a materialized name, so they are
     * not copied.
     *
     * @param  object $carrier  the event that carried the flag, properties resolved
     * @param  string $name     a materialized event name
     * @return object
     */
    public static function materialize( $carrier, $name ) {

        $event = new \OWA\Module\Base\Classes\Event;
        $event->setEventType( $name );

        $carried = $carrier->getProperties();

        foreach ( self::materializedProperties( $name ) as $property ) {

            if ( array_key_exists( $property, $carried ) ) {

                $event->set( $property, $carried[ $property ] );
            }
        }

        $event->set( 'event_type', $name );

        return $event;
    }

    /**
     * The properties a materialized event of this name copies from its carrier.
     *
     * propertiesForEvent() less any declared `"materialize": false`, whatever
     * the name: a goal event (Classes\GoalMarking) has a name the site
     * chose, which isMaterialized() does not know, and copying the carrier's
     * engagement time to it would count that time twice in the session.
     *
     * @param  string $name
     * @return string[]
     */
    public static function materializedProperties( $name ) {

        $all = self::allProperties();

        return array_values( array_filter( self::propertiesForEvent( $name ),
            static function ( $property ) use ( $all ) {

                return ! ( isset( $all[ $property ] )
                    && array_key_exists( 'materialize', $all[ $property ] )
                    && ! $all[ $property ]['materialize'] );
            } ) );
    }

    /**
     * What the property registry IMPLIES the event names are.
     *
     * The union of every non-`*` entry in the `events` lists. The other half of
     * a check, not a source: a name here that is not declared in config/tracking_events.php means
     * a property is scoped to an event nothing else knows about, which is either a
     * typo or an event somebody forgot to declare.
     *
     * The reverse does NOT hold and must not be asserted. A declared event may
     * carry no property of its own -- user_engagement carries only the common set
     * -- and that is precisely the case the declared list exists to cover.
     *
     * @return string[]  sorted
     */
    public static function eventNamesFromProperties() {

        $out = [];

        foreach ( self::SET_BY as $scope ) {

            foreach ( self::propertyConfig( $scope ) as $definition ) {

                if ( ! array_key_exists( 'events', $definition ) ) {

                    continue;
                }

                foreach ( (array) $definition['events'] as $name ) {

                    // The star says "every event", so it names none of them.
                    if ( $name === self::EVERY_EVENT ) {

                        continue;
                    }

                    $out[ $name ] = true;
                }
            }
        }

        $out = array_keys( $out );

        sort( $out );

        return $out;
    }

    /**
     * Properties the server reads off the HTTP request.
     *
     * The request is the source: the user agent, the host, the address it came
     * from, and the moment it arrived. A tracking request cannot set these --
     * it would be choosing what it is observed to be.
     *
     * @return array<string, Definition>
     */
    public static function requestProperties() {

        return self::propertyConfig( 'request' );

    }

    /**
     * Properties the tracker sends.
     *
     * The client is the source, so a request may set them.
     *
     * @return array<string, Definition>
     */
    public static function clientProperties() {

        return self::propertyConfig( 'client' );

    }

    /**
     * Properties the server computes from other properties.
     *
     * Date parts from the timestamp, geolocation from the address, browser from
     * the user agent. A request cannot set these either: a supplied value would
     * replace a derivation.
     *
     * @return array<string, Definition>
     */
    public static function serverProperties() {

        return self::propertyConfig( 'event' );

    }

    /**
     * The properties a tracking request is allowed to set.
     *
     * A request reaches log.php carrying arbitrary owa_* parameters, so what a
     * request may set has to be decided here, deliberately, rather than by
     * whether a parameter's name happens to match a column.
     *
     * Membership is DECLARED, not inferred from which map a property lives in:
     * `regular` is client-set by definition, and a derived property opts in with
     * 'client_settable' => true rather than the endpoint guessing.
     *
     * @return array<mixed> property name => its definition
     */
    public static function clientSettableProperties() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $allowed = (array) $service->getMap( 'tracking_properties_regular' );

        foreach ( [ 'tracking_properties_derived', 'tracking_properties_environmental' ] as $map ) {

            foreach ( (array) $service->getMap( $map ) as $name => $property ) {

                if ( ! empty( $property['client_settable'] ) ) {

                    $allowed[ $name ] = $property;
                }
            }
        }

        return $allowed;
    }

    /**
     * Properties only the server may set: derived and environmental, less
     * anything that declared itself client-settable.
     *
     * One definition, used both to filter the wire and to decide what
     * ProcessEvent may re-apply over a derivation. Two definitions would drift,
     * and the symptom of drift is a value silently landing in the wrong column.
     *
     * @return array<mixed> property name => definition
     */
    public static function serverOwnedProperties() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        return array_diff_key(
            (array) $service->getMap( 'tracking_properties_derived' )
                + (array) $service->getMap( 'tracking_properties_environmental' ),
            self::clientSettableProperties() );
    }

    /**
     * The prefixes a site's own values arrive under.
     *
     * Scope and type live in the NAME, so these four are the whole custom
     * surface -- and because they are a namespace rather than a list, a gate
     * can admit them without knowing a single one of a site's keys. That is
     * what makes an allowlist possible here at all.
     */
    const CUSTOM_PREFIXES = [ 'eps_', 'epn_', 'vps_', 'vpn_' ];

    /** A custom name must survive becoming a JSON key; the tracker's rule. */
    const CUSTOM_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    /**
     * Keep only what a request is ALLOWED to set.
     *
     * An allowlist, so the gate cannot fail open for a name nobody thought about.
     * Two things are admitted and nothing else:
     *
     *   - a name some event declares, which the property registry states
     *   - a custom value under one of the four prefixes, whose name is legal
     *
     * A site's own keys are therefore unrestricted: they have to say which bag
     * they are in, which the tracker already does.
     *
     * @param  array<mixed> $params
     * @return array<string, mixed>  the admitted subset
     */
    public static function admitRequestParams( array $params ) {

        $allowed = self::clientSettableProperties();

        /*
         * Plus the names the beacon compat layer renames on the way in. They are
         * legitimate inbound names for an older generation, and most are not
         * registered properties at all -- they exist only as the FROM side of a
         * rename, so nothing else can vouch for them.
         */
        foreach ( \OWA\Module\Base\Classes\Beacon\Compat::bridgedNames() as $bridged ) {

            $allowed[ $bridged ] = true;
        }

        $kept = [];

        foreach ( $params as $name => $value ) {

            $name = (string) $name;

            if ( array_key_exists( $name, $allowed ) ) {

                $kept[ $name ] = $value;

                continue;
            }

            foreach ( self::CUSTOM_PREFIXES as $prefix ) {

                if ( strpos( $name, $prefix ) !== 0 ) {

                    continue;
                }

                if ( preg_match( self::CUSTOM_NAME_PATTERN,
                        substr( $name, strlen( $prefix ) ) ) ) {

                    $kept[ $name ] = $value;
                }

                continue 2;
            }
        }

        return $kept;
    }

    /**
     * The internal properties holding an older beacon's page URL and referrer
     * as PHP's decoding left them, before decodeRequestParams() decodes every
     * value a second time (Beacon\Compat::decodeOlderBeacon()). A current
     * beacon is decoded once and has none. Request-set and stored by nothing
     * (tracking_properties.json).
     */
    const ARRIVED = [
        'page_location' => 'arrived_page_location',
        'HTTP_REFERER'  => 'arrived_referer',
    ];

    /**
     * The page URL and referrer of a beacon's params as they are now, by
     * their ARRIVED names. Beacon\Compat::decodeOlderBeacon() takes them before
     * an older beacon's second decode, and log.php adds them after admission,
     * so a beacon cannot name them.
     *
     * @param  array<mixed> $params  the request's params, decoded once by PHP
     * @return array<string, string>
     */
    public static function arrivedUrls( array $params ) {

        $out     = [];
        $renames = \OWA\Module\Base\Classes\Beacon\Compat::renames();

        foreach ( self::ARRIVED as $property => $arrived ) {

            // The wire key first, then an older tracker's spelling of it.
            $keys = array_merge( self::wireKeysFor( $property ), [ $property ],
                array_keys( $renames, $property, true ) );

            foreach ( $keys as $key ) {

                if ( isset( $params[ $key ] ) && is_string( $params[ $key ] ) && $params[ $key ] !== '' ) {

                    $out[ $arrived ] = $params[ $key ];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The URL a beacon carried for page_location or HTTP_REFERER, as it
     * arrived (ARRIVED), when the second decode cuts it; null otherwise.
     *
     * The second decode turns an encoded ? or # in the path, and an encoded &
     * or # in the query, into the characters that end them: the path, the
     * query and the pairs in it then split in the wrong place. A URL holding
     * none of those reads the same either way, so its readings are taken from
     * the stored URL. A current beacon, decoded once, an event built in
     * process, and one queued before the copies existed have none.
     *
     * @param  object $event
     * @param  string $property  page_location or HTTP_REFERER
     * @return string|null
     */
    public static function arrivedUrlIfCut( $event, $property ) {

        $url = $event->get( self::ARRIVED[ $property ] );

        if ( ! is_string( $url ) || $url === '' ) {

            return null;
        }

        $parts = parse_url( $url );

        if ( $parts === false ) {

            return null;
        }

        $cut = preg_match( '/%(3F|23)/i', (string) ( $parts['path'] ?? '' ) )
            || preg_match( '/%(26|23)/i', (string) ( $parts['query'] ?? '' ) );

        return $cut ? $url : null;
    }

    /**
     * A URL property of a current beacon in its stored form
     * (V2Event::readableUrl()): the path decoded once, the query and fragment
     * as the page had them. The first callback of every data_type url
     * property the client sets.
     *
     * Only for a beacon decoded once (Beacon\Compat::decodesOnce()). An older
     * beacon's values were decoded a second time already, and an event with no
     * format version is left as it was built.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function readableUrl( $value, $event ) {

        if ( ! is_string( $value ) || $value === ''
             || ! \OWA\Module\Base\Classes\Beacon\Compat::decodesOnce( $event->get( 'beacon_version' ) ) ) {

            return $value;
        }

        return \OWA\Module\Base\Classes\Sanitize::cleanTrackingText(
            \OWA\Module\Base\Classes\V2Event::readableUrl( $value ) );
    }

    /**
     * arrivedPageLocation() and arrivedReferer(): the copy log.php set, kept as it is.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function arrivedPageLocation( $value, $event ) {

        return is_string( $value ) && $value !== '' ? $value : null;
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function arrivedReferer( $value, $event ) {

        return is_string( $value ) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed> $items    property name => definition
     * @param  int          $priority
     * @return void
     */
    public function registerCallbacks( $items, $priority = 0 ) {

        foreach ($items as $name => $item ) {

            if ( isset( $item['callbacks'] ) && ! empty($item['callbacks'] ) ) {

                $callbacks = is_array( $item['callbacks'] )
                    ? $item['callbacks']
                    : [ $item['callbacks'] ];

                foreach ( $callbacks as $callback ) {

                    // Attach each property filter at most once per process.
                    $key = $name . '|' . ( is_array( $callback )
                        ? implode( '::', $callback )
                        : (string) $callback );

                    if ( isset( $this->registeredCallbacks[ $key ] ) ) {
                        continue;
                    }

                    $this->registeredCallbacks[ $key ] = true;

                    \OWA\Core\CoreAPI::registerFilter( $name, $callback, $priority );
                }
            }
        }
    }

    /**
     * @param  Event        $event
     * @param  array<mixed> $properties property name => definition
     * @return void
     */
    public function setTrackerProperties( $event, $properties ) {

        /*
         * OLD SPELLINGS FIRST, once, before anything below reads a value.
         *
         * Classes\Beacon\Compat asks whether the key is THERE, not whether its value
         * is truthy, so 0, "0" and false are carried like any other value.
         */
        \OWA\Module\Base\Classes\Beacon\Compat::apply( $event );

        $this->registerCallbacks( $properties, 0 );

        $eq = \OWA\Core\CoreAPI::getEventDispatch();

        foreach ( $properties as $name => $property ) {

            // Already normalised by Compat::apply() above, so the canonical
            // name is the only one this has to know about.
            $value = $event->get( $name );


            // sanitize properties by datatype
            $data_type = '';

            if ( isset( $property['data_type'] ) && $property['data_type'] ) {

                $data_type = $property['data_type'];
            }

            $value = $this->setDataType( $value, $data_type );

            $required = false;

            if ( isset( $property['required'] ) ) {

                $required = $property['required'];
            }

            // filter value
            $value = $eq->filter( $name, $value, $event );

            /*
             * Re-apply the declared type AFTER filtering.
             *
             * setDataType() above runs on the value as it arrived, and a callback's return
             * value goes to the database untouched -- so a derivation that falls off the
             * end (returning null) would write NULL into a column whose declared type is
             * boolean.
             *
             * Only when a value actually came back null: a callback that deliberately
             * returns 0, '' or false keeps what it returned.
             */
            if ( $data_type && $value === null ) {

                $value = $this->setDataType( $value, $data_type );
            }

            /*
             * Trim once, here, after the callbacks.
             *
             * Leading and trailing whitespace is never meaningful in a tracking value.
             * Sanitize::cleanInput(), which every explicitly-defined string property flows
             * through, does not trim (removeHiddenSpaces() only swaps a non-breaking
             * space), so without this the properties with their own definitions would keep
             * it, and ' Google ' and 'Google' would be different values.
             *
             * After the callback rather than inside setDataType(), because a callback's
             * return value goes on to the event untouched -- trimming only the raw input
             * would leave every resolved value unhandled.
             *
             * Deliberately NOT lowercasing. That is identity normalisation: a page title, a
             * city name and a source domain are all displayed as recorded. Properties that
             * lowercase do it in their own callbacks.
             */
            if ( is_string( $value ) && in_array( $data_type, self::TRIMMED_TYPES, true ) ) {

                $value = trim( $value );
            }

            //set default value
            if ( $required && ! $value && $value !== 0 && $value !== "0") {

                /*
                 * array_key_exists, not isset() && truthiness: `false` and `0` are the
                 * defaults a boolean or a counter wants.
                 */
                /*
                 * The "(not set)" default is a STORAGE convention, not an event one, so it is
                 * not applied here: absence stays absent on the event. The v1 columns still
                 * receive it: Entity::setProperties() substitutes it when an empty value
                 * reaches a column whose property declares it.
                 *
                 * Every other default applies here, because it is a real value rather than a
                 * stand-in for the lack of one.
                 */
                if ( array_key_exists( 'default_value', $property )
                     && $property['default_value'] !== self::ABSENT_VALUE_LABEL ) {

                    $value = $property['default_value'];
                }
            }

            // set value on the event
            if ( $required || $value || $value === 0 || $value === "0" ) {

                $event->set( $name,  $value );
            }
        }
    }

    /**
     * @param  mixed  $var
     * @param  string $type a data_type: integer, string, url, json, boolean
     * @return mixed
     */
    public static function setDataType( $var, $type = 'string' ) {

        switch( $type ) {

            case "integer":

                /*
                 * Guarded, because this is reached from an unauthenticated tracking beacon.
                 * PHP 8 raises "TypeError: Unsupported operand types: string + int" for a
                 * non-numeric string, and during a queue drain that is a poison pill.
                 *
                 * is_numeric() rather than a cast, so valid input keeps its value: (int) would
                 * truncate "1.9" to 1, where + 0 yields 1.9.
                 *
                 * ABSENT IS NOT ZERO. Event::get() returns false for a property the event does
                 * not carry, and in v2 0 is a real reading: a beacon carrying no scroll_depth is
                 * not reporting a scroll to the top. So absence stays absent and GARBAGE
                 * becomes 0. A null reaching a non-nullable numeric column is written as 0 by
                 * Entity::writeValue(), where a nullable column can opt out.
                 */
                if ( $var === null || $var === false || $var === '' ) {

                    $var = null;

                } else {

                    $var = is_numeric( $var ) ? $var + 0 : 0;
                }

                break;
            case "string":

                $var = \OWA\Module\Base\Classes\Sanitize::cleanTrackingText( $var );
                break;
            case "url":

                $var = \OWA\Module\Base\Classes\Sanitize::cleanTrackingText( $var );
                break;
            case "json":

                $var = \OWA\Module\Base\Classes\Sanitize::cleanJson( $var );
                break;
            case "boolean":

                // A beacon's values are strings, and boolval() reads "false" as true.
                if ( is_string( $var ) ) {

                    $var = ! in_array( strtolower( trim( $var ) ), [ '', '0', 'false', 'off', 'no' ], true );

                } else {

                    $var = boolval( $var );
                }

                break;
            default:

                $var = \OWA\Module\Base\Classes\Sanitize::cleanTrackingText( $var );
        }

        return $var;
    }


    /**
     * @param  Event $event
     * @return void
     */
    public function translateCustomVariables( $event ) {

        $maxCustomVars = \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );

        for ($i = 1; $i <= $maxCustomVars; $i++) {

            $cvar = $event->get( 'cv'.$i );

            if ( $cvar ) {
                //split the string
                $pieces = explode( '=' , trim( $cvar ) );
                if ( isset( $pieces[1] ) ) {
                    $event->set( 'cv'.$i.'_name', $pieces[0] );
                    $event->set( 'cv'.$i.'_value', $pieces[1] );
                }

                $event->delete( 'cv'.$i );
            }
        }
    }

    /**
     * @param  mixed $ua the value already on the event
     * @return mixed
     */
    public static function userAgentDefault( $ua = '') {
        
        if (! $ua ) {
            
            $ua = \OWA\Core\CoreAPI::getServerParam('HTTP_USER_AGENT');
        }
        return $ua;
    }

    /*
     * THE BEACON REQUEST'S OWN HEADERS, for spam scoring (Classes\Spam).
     *
     * Read here, at the edge, because a queued beacon is scored by the drain,
     * where $_SERVER is not the beacon's. Each rides the event in the queue
     * envelope like the user agent does, and none of them reaches a column.
     *
     * request_referer is the Referer header of the request to log.php, which a
     * browser sets to the page that sent the beacon. It is not HTTP_REFERER,
     * which the tracker sends (p_r) and which is the page's own referrer.
     */

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestSecFetchMode( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_SEC_FETCH_MODE' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestSecFetchSite( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_SEC_FETCH_SITE' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestSecFetchDest( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_SEC_FETCH_DEST' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestSecChUa( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_SEC_CH_UA' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestSecChUaPlatform( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_SEC_CH_UA_PLATFORM' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestSecChUaMobile( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_SEC_CH_UA_MOBILE' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestAcceptEncoding( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_ACCEPT_ENCODING' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestAcceptLanguage( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_ACCEPT_LANGUAGE' );
    }

    /**
     * @param  mixed $value
     * @return mixed the value already on the event, else the header
     */
    public static function requestReferer( $value = '' ) {

        return self::requestHeader( $value, 'HTTP_REFERER' );
    }

    /*
     * The page URL and its referrer AS THE BEACON CARRIED THEM, for the
     * query_string spam rules, copied in the edge stamp before the property
     * stage. Read with carried(): the property stage rewrites a current
     * beacon's page_location and HTTP_REFERER later (readableUrl()), so for
     * one of those the copy comes from the key the beacon sent.
     */

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function receivedPageLocation( $value, $event ) {

        return self::received( $value, self::carried( $event, 'page_location' ) );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function receivedReferer( $value, $event ) {

        return self::received( $value, self::carried( $event, 'HTTP_REFERER' ) );
    }

    /**
     * A client property's value under the key the beacon sent it by: its wire
     * key, else an older tracker's name for it (Beacon\Compat::renames()).
     * The property itself only for an event that is not a current beacon,
     * whose URL readableUrl() leaves as it is. Null when there is none.
     *
     * @param  Event  $event
     * @param  string $property
     * @return mixed
     */
    private static function carried( $event, $property ) {

        $properties = (array) $event->getProperties();
        $keys       = array_merge( self::wireKeysFor( $property ),
            array_keys( \OWA\Module\Base\Classes\Beacon\Compat::renames(), $property, true ) );

        if ( ! \OWA\Module\Base\Classes\Beacon\Compat::decodesOnce( $properties['beacon_version'] ?? null ) ) {

            $keys[] = $property;
        }

        foreach ( $keys as $key ) {

            if ( array_key_exists( $key, $properties ) ) {

                return $properties[ $key ];
            }
        }

        return null;
    }

    /**
     * The value already on the event, else the copy. '' when neither.
     *
     * @param  mixed $value
     * @param  mixed $copy
     * @return mixed
     */
    private static function received( $value, $copy ) {

        if ( $value !== null && $value !== false && $value !== '' ) {

            return $value;
        }

        return is_string( $copy ) ? $copy : '';
    }

    /**
     * A header of the request, or the value already on the event. '' when neither.
     *
     * @param  mixed  $value
     * @param  string $server_key
     * @return mixed
     */
    private static function requestHeader( $value, $server_key ) {

        if ( $value !== null && $value !== false && $value !== '' ) {

            return $value;
        }

        $header = \OWA\Core\CoreAPI::getServerParam( $server_key );

        return is_string( $header ) ? $header : '';
    }

    /**
     * @return string
     */
    public static function languageDefault() {

        return substr( \OWA\Core\CoreAPI::getServerParam( 'HTTP_ACCEPT_LANGUAGE' ), 0, 5 );
    }

    /**
     * The visitor's address from what a request carried: the first public
     * address of a proxy chain ("client, proxy, ..."), or the address itself
     * when it is public; '' when there is none.
     *
     * The v1 migration uses it too: 1.x sometimes stored the whole chain,
     * which is longer than the column and is not an address.
     *
     * @param string $ip
     * @return string
     */
    public static function chooseIp( $ip ) {

        foreach ( explode( ',', (string) $ip ) as $candidate ) {

            $candidate = trim( $candidate );

            if ( \OWA\Core\Lib::isNotPrivateIp( $candidate ) ) {

                return $candidate;
            }
        }

        return '';
    }

    /**
     * The address this request came from, as ip_address is chosen and before
     * anonymize_ips masks it: the first address, from the right of
     * X-Forwarded-For and REMOTE_ADDR, that no trusted proxy added
     * (Core\ClientAddress); '' when there is none.
     *
     * @return string
     */
    public static function requestIp() {

        return \OWA\Core\ClientAddress::fromRequest();
    }

    /**
     * Registered as a filter, so the dispatcher hands it ( $value, $event ).
     * $event is read because anonymisation is a per-Profile decision; $value is
     * ignored, as the address is always recomputed from the request.
     *
     * @param  mixed      $value
     * @param  Event|null $event
     * @return string
     */
    public static function ipAddressDefault( $value = '', $event = null ) {

        $chosen_ip = self::requestIp();

        // Anonymize IP if needed.
        $site_id = $event ? $event->get('site_id') : '';

        if ( $chosen_ip && \OWA\Core\CoreAPI::getSetting(
                 'base', 'anonymize_ips', 'profile', $site_id ) ) {
            
            $chosen_ip = \OWA\Core\Lib::anonymizeIp( $chosen_ip );
            \OWA\Core\CoreAPI::debug("IP address was anonymized.");
        }

        return $chosen_ip;
    }

    /**
     * Edge receipt in microseconds, for owa_event_raw.ts.
     *
     * An ENVIRONMENTAL property, so it is stamped in CoreAPI::logEvent() before
     * the event is queued and a request naming `ts` cannot replace it. That
     * placement is the whole point: the value has to be the instant the beacon
     * ARRIVED, not the instant a queue drain got round to it, or a redelivered
     * beacon would derive a different event id and fail to collapse.
     *
     * Separate from `microtime`, which is the historical string form of the
     * same clock and feeds owa_request.msec -- a column that holds 0 or 1 and
     * nothing else, and is deliberately not being repaired in 1.x.
     *
     * @return int
     */
    public static function edgeTimestampMicroseconds() {

        return \OWA\Core\CoreAPI::getRequestTimestampMicroseconds();
    }




    /**
     * @param  mixed $yyyymmdd
     * @param  Event $event
     * @return string
     */
    public static function deriveYyyymmdd( $yyyymmdd, $event ) {

        // Never return an empty yyyymmdd.
        //
        // This column is NOT NULL, it is the partition key, and every date-range
        // report filters on it -- so a row written without one is invisible to
        // reporting and lands in the catch-all partition.
        //
        // date() with a null timestamp yields 1970, which looks like real data.
        // Falling back to now is honest: the server assigns event time anyway when the
        // client does not supply one.
        //
        // FROM `ts`, the edge stamp in MICROseconds and the only server clock reading.
        $ts = (int) $event->get( 'ts' );

        $timestamp = $ts > 0 ? intdiv( $ts, 1000000 ) : time();

        return date("Ymd", $timestamp );

    }









    
    
    /**
     *  Use this function to parse out the url and query array element from
     *  a url.
     *
     * @param  string $url
     * @return array<string, mixed>|false parse_url()'s parts, and query_params when there is a query
     */
    public static function parse_url( $url ) {

        $url = parse_url($url);

        if ( isset( $url['query'] ) ) {
            $var = $url['query'];

            $var  = html_entity_decode($var);
            $var  = explode('&', $var);
            $arr  = [];

              foreach( $var as $val ) {

                if ( strpos($val, '=') ) {
                    $x = explode('=', $val);

                    if ( isset( $x[1] ) ) {
                        $arr[$x[0]] = urldecode($x[1]);
                    }
                } else {
                    $arr[$val] = '';
                }
               }
              unset($val, $x, $var);

              $url['query_params'] = $arr;

        }

          return $url;
    }

    
    
    /**
     * @param  mixed $host
     * @return true|void
     */
    public static function isSearchEngine( $host ) {
        
        if ( ! $host ) {
            
            return;
        }

        $searchEngine = [];
        
        $organicSearchEngines = self::getSearchEngineList();

        foreach ( $organicSearchEngines as $engine ) {
            
            $domain = $engine['domain'];

            if ( stripos( $host, $domain ) !== false ) {
                
                \OWA\Core\CoreAPI::debug( 'Found search engine: '. $domain);
                
                return true;
            }
        }
    }
    
    
    /**
     * @return mixed searchengines.php, through the tracking.search_engine_registry filter
     */
    public static function getSearchEngineList() {
        
        return \OWA\Core\CoreAPI::loadConf( 'searchengines.php', 'tracking.search_engine_registry' );
    }
    
    /**
     * A canonical URL that is safe to store and to render.
     *
     * This method decodes HTML entities so that parse_url() sees the real URL,
     * and its return value goes to the event untouched -- setTrackerProperties()
     * only re-applies the declared type when a callback answers null. So
     * whatever survives the decode is what reaches the column, and a value that
     * arrived encoded comes back out raw.
     *
     * The answer is not to escape it. A URL is not HTML, and escaping it for one
     * output context breaks it for the others -- it also has to survive as an
     * href, in a CSV, and in a JSON response. What a URL HAS is a grammar, and
     * the characters below cannot legally appear raw in one: a browser
     * percent-encodes them before the request is ever made. So percent-encoding
     * them is not a mangling, it is the URL written correctly, and the value
     * stays inert in every context rather than in one.
     *
     * Existing percent-escapes are left alone -- '%' is not in the replacement
     * set -- so %3C stays %3C instead of becoming %253C.
     *
     * The scheme is checked separately, because 'javascript:' and 'data:' carry
     * no dangerous characters at all and no amount of encoding addresses them.
     * A rejected URL is recorded as absent rather than stored, which is the
     * honest record: we did not observe a page we can represent.
     */
    const STORABLE_URL_SCHEMES = [ 'http', 'https' ];

    /**
     * @param  mixed $url
     * @return mixed '' for a scheme that is not stored; null and '' as they came
     */
    public static function makeUrlStorageSafe( $url ) {

        if ( $url === null || $url === '' ) {

            return $url;
        }

        $url = (string) $url;

        /*
         * Control characters first, and before the scheme is read. Browsers
         * strip tab, newline and carriage return from inside a scheme, so
         * "java    script:" is javascript: to a browser while parse_url() sees
         * something else entirely -- the check and the consumer have to agree
         * about what the string is.
         */
        $url = preg_replace( '/[\x00-\x1F\x7F]/', '', $url );

        /*
         * The scheme is read off the string, not via parse_url().
         *
         * parse_url() applies the grammar, so anything it considers malformed
         * yields NO scheme -- and a check that only fires when a scheme parses
         * is skipped by exactly the inputs worth checking. Sanitising upstream
         * turns "java\tscript:" into "java_script:", which parse_url() reports
         * no scheme for at all; the guard then passes it through.
         *
         * Everything before the first ':' is the claimed scheme, provided no
         * '/' comes first -- that condition is what keeps a relative URL, or a
         * path containing a colon, from being read as one.
         */
        $colon = strpos( $url, ':' );
        $slash = strpos( $url, '/' );

        if ( $colon !== false && ( $slash === false || $colon < $slash ) ) {

            $scheme = strtolower( substr( $url, 0, $colon ) );

            if ( ! in_array( $scheme, self::STORABLE_URL_SCHEMES, true ) ) {

                \OWA\Core\CoreAPI::debug(
                    'Not recording a URL with the scheme: ' . $scheme );

                return '';
            }
        }

        return str_replace(
            [ '<',   '>',   '"',   "'",   '`',   ' ' ],
            [ '%3C', '%3E', '%22', '%27', '%60', '%20' ],
            $url );
    }

    /**
     * @param  string|null $string
     * @param  Event       $event
     * @return string|null
     */
    public static function utfEncodeProperty( $string, $event ) {
    if(is_null($string)){
            return $string;
        }

        return \OWA\Core\Lib::utf8Encode( trim( $string ) );
    }


    /**
     * THE ONE PARSE, asked for by agent.
     *
     * Seven properties are readings of the user agent, and each is set by its own
     * callback -- independently, from the registry, like every other property.
     * That is only affordable because the parser is a singleton memoised per
     * AGENT: one request parses once however many of the seven ask, and a process
     * walking several events parses once per distinct agent instead of once,
     * wrongly. The agent comes off the EVENT rather than $_SERVER, which is the
     * difference between a live beacon and one drained from the queue later.
     *
     * @param  object $event
     * @return object
     */
    private static function browscapFor( $event ) {

        return \OWA\Core\CoreAPI::serviceSingleton()->getBrowscap(
            $event->get( 'HTTP_USER_AGENT' ) );
    }

    /**
     * @param  mixed $browser_type
     * @param  Event $event
     * @return string
     */
    public static function resolveBrowserType( $browser_type, $event ) {

        return self::browscapFor( $event )->getUaFamily();
    }

    /**
     * @param  mixed $version
     * @param  Event $event
     * @return string
     */
    public static function resolveBrowserVersion( $version, $event ) {

        return self::browscapFor( $event )->getUaVersion();
    }

    /**
     * @param  mixed $os
     * @param  Event $event
     * @return string
     */
    public static function resolveOs ( $os, $event ) {

        return self::browscapFor( $event )->getOsFamily();
    }

    /**
     * @param  mixed $version
     * @param  Event $event
     * @return string
     */
    public static function resolveOsVersion( $version, $event ) {

        return self::browscapFor( $event )->getOsVersion();
    }

    /**
     * Desktop, mobile or tablet -- DERIVED, because ua-parser has no such field.
     *
     * Its device rules answer brand, model and family, and 'Other' is its word
     * for "no rule matched" -- which is an answer about a desktop browser and an
     * absence about a phone. The OS family is what tells those apart, so the rule
     * reads it and falls through to NULL rather than guessing desktop: a wrong
     * 'desktop' is indistinguishable from a real one.
     *
     * @param  mixed $type
     * @param  Event $event
     * @return string|null
     */
    public static function resolveDeviceType( $type, $event ) {

        $bcap = self::browscapFor( $event );

        $family = strtolower( (string) $bcap->getDeviceFamily() );

        if ( $family === 'ipad' || strpos( $family, 'tablet' ) !== false ) {

            return 'tablet';
        }

        $os = strtolower( (string) $bcap->getOsFamily() );

        if ( in_array( $os, self::MOBILE_OS, true ) ) {

            return 'mobile';
        }

        if ( in_array( $os, self::DESKTOP_OS, true ) ) {

            return 'desktop';
        }

        return null;
    }

    /**
     * 'Other' is the parser saying it has no rule, not a brand.
     *
     * @param  mixed $brand
     * @param  Event $event
     * @return string|null
     */
    public static function resolveDeviceBrand( $brand, $event ) {

        $value = (string) self::browscapFor( $event )->getDeviceBrand();

        return strtolower( $value ) === 'other' ? null : $value;
    }

    /**
     * As the brand: 'Other' is an absence wearing a value.
     *
     * @param  mixed $model
     * @param  Event $event
     * @return string|null
     */
    public static function resolveDeviceModel( $model, $event ) {

        $value = (string) self::browscapFor( $event )->getDeviceModel();

        return strtolower( $value ) === 'other' ? null : $value;
    }


    /*
     * GEO IS DERIVED FROM THE OBSERVED IP AND CANNOT BE SUPPLIED.
     *
     * None of the four declares client_settable, so admitRequestParams() refuses
     * them at log.php. Supplying geo would need client_settable set, which is a
     * decision about trust.
     */
    /**
     * The location of the event's IP address.
     *
     * @param  Event $event
     * @return \OWA\Module\Base\Classes\Geolocation
     */
    private static function location( $event ) {

        return \OWA\Core\CoreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
    }

    /**
     * @param  mixed $country
     * @param  Event $event
     * @return string|null|void
     */
    public static function resolveCountry ( $country, $event ) {

        return self::location( $event )->getCountry();
    }

    /**
     * @param  mixed $city
     * @param  Event $event
     * @return string|null|void
     */
    public static function resolveCity ( $city, $event ) {

        return self::location( $event )->getCity();
    }



    /**
     * @param  mixed $country_code
     * @param  Event $event
     * @return string|null|void
     */
    public static function resolveCountryCode ( $country_code, $event ) {

        return self::location( $event )->getCountryCode();
    }

    /**
     * @param  mixed $state
     * @param  Event $event
     * @return string|null|void
     */
    public static function resolveState ( $state, $event ) {

        return self::location( $event )->getState();
    }

    /**
     * @param  string|null $string
     * @param  Event       $event
     * @return string|null
     */
    public static function lowercaseString ( $string, $event ) {
    if(is_null($string)){
            return($string);
        }

        return strtolower( trim( $string ) );
    }


    /**
     * screen_resolution as WIDTHxHEIGHT, or nothing.
     *
     * The tracker sends what window.screen reports, rounded. Anything else --
     * a hand-built beacon, a zero side, a size no screen has -- is dropped
     * rather than stored as a value to group by. Deletes rather than returning
     * null, for the reason gateUserId() gives.
     *
     * @param  string|null $value
     * @param  object      $event
     * @return string|null
     */
    public static function screenResolution( $value, $event ) {

        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

        if ( preg_match( '/^[1-9][0-9]{0,4}x[1-9][0-9]{0,4}$/', $value ) ) {

            return $value;
        }

        $event->delete( 'screen_resolution' );

        return null;
    }

    /**
     * user_id, unless the install has turned visitor PII off.
     *
     * DELETES RATHER THAN RETURNING NULL, which is the lesson the two callbacks
     * above taught on their way out: setTrackerProperties() declines to write a
     * null back, so whatever the beacon put on the event is still there for the
     * row builder to read. A gate that returns nothing leaves the value in place.
     *
     * @param  string|null $user_id
     * @param  object      $event
     * @return string|null
     */
    public static function gateUserId( $user_id, $event ) {

        if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {

            $event->delete( 'user_id' );

            return null;
        }

        return $user_id;
    }

    /**
     * The properties log_visitor_pii gates: the site's id for the person, and
     * the display name and email address OWA's own vocabulary carries as user
     * properties (setUserName(), and a 1.x tracker's user_name and
     * email_address, which conf/beacon_compat.php renames onto these).
     */
    const VISITOR_PII_PROPERTIES = [ 'user_id', 'vps_user_name', 'vps_user_email' ];

    /**
     * Remove VISITOR_PII_PROPERTIES from an event when log_visitor_pii is off.
     *
     * AT THE EDGE (CoreAPI::logEvent()), before the event is queued, so
     * neither the intake nor its dead letters ever hold what the setting
     * refuses. Asked again at the property stage, for an event queued before
     * the setting changed, and by the 1.x migration.
     *
     * @param  object $event
     * @return void
     */
    public static function gateVisitorPii( $event ) {

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {

            return;
        }

        $names = self::VISITOR_PII_PROPERTIES;

        // The older spellings too: a rename copies the value and leaves the original.
        foreach ( \OWA\Module\Base\Classes\Beacon\Compat::renames() as $from => $to ) {

            if ( in_array( $to, self::VISITOR_PII_PROPERTIES, true ) ) {

                $names[] = $from;
            }
        }

        foreach ( $names as $name ) {

            $event->delete( $name );
        }
    }

    /**
     * The campaign parameters a site owner writes on a landing URL, and the
     * wire property each one becomes.
     *
     * SUFFIXES, not whole names: the public parameter is the `ns` setting plus
     * the suffix, because `ns` is what keeps OWA's names off a tracked page's
     * own query string. Changing `ns` changes every campaign URL in the wild,
     * which is exactly why the list is built from it rather than hard-coded.
     *
     * Note owa_search_terms becomes tagged_TERMS, not tagged_search_terms --
     * the one place the two halves do not share a stem.
     *
     * NO ad_type. 1.x read owa_ad_type, and v2 has no property, column or
     * dimension to keep it in, so it is not a role: a campaignKeys value naming
     * it is ignored, and droppedQueryParams() still takes owa_ad_type out of
     * page_query.
     */
    const CAMPAIGN_KEYS = [
        'source'       => 'tagged_source',
        'medium'       => 'tagged_medium',
        'campaign'     => 'tagged_campaign',
        'search_terms' => 'tagged_terms',
        'ad'           => 'tagged_ad',
    ];

    /**
     * Campaign roles 1.x read that v2 does not, by suffix: still removed from
     * page_query, so a link in the wild carrying one stays one page.
     */
    const RETIRED_CAMPAIGN_KEYS = [ 'ad_type' ];

    /**
     * The utm_* parameters, read alongside OWA's own when the Property's
     * campaignUtmParams setting is on. utm_term is the search terms and
     * utm_content tells one ad or link from another, which is OWA's ad.
     */
    const UTM_KEYS = [
        'utm_source'   => 'tagged_source',
        'utm_medium'   => 'tagged_medium',
        'utm_campaign' => 'tagged_campaign',
        'utm_term'     => 'tagged_terms',
        'utm_content'  => 'tagged_ad',
    ];

    /**
     * Click ids an ad network appends to the landing URL, and the source and
     * medium each one stands for. Read only when the URL carries no source,
     * medium or campaign of its own, so a tagged link keeps its tags.
     */
    const CLICK_IDS = [
        'gclid'   => [ 'google', 'cpc' ],
        'gbraid'  => [ 'google', 'cpc' ],
        'wbraid'  => [ 'google', 'cpc' ],
        'msclkid' => [ 'bing', 'cpc' ],
    ];

    /** @var array<string, array<string, mixed>> Parsed landing URLs, keyed by site and URL. */
    private static $landingTags = [];

    /** @var array<string, array<string, string>> Resolved campaign key maps, keyed by site. */
    private static $campaignKeys = [];

    /**
     * One campaign tag off the URL THE SESSION LANDED ON.
     *
     * PARSED FROM page_location, not from a landing_url the tracker re-sent. On
     * the session-starting beacon they are the same string -- the tracker set
     * landing_url to getCurrentUrl() and page_location comes from the same call --
     * and this only ever runs on that beacon, so reading the URL the event already
     * carries takes a session-scoped field off the wire for the life of every
     * session. Session source is fixed by the session's FIRST EVENT, so no
     * cookie needs to hold a URL.
     *
     * THE EVIDENCE, NOT THE READING. This reads page_location as it arrived,
     * before filterStoredUrls() removes the query_string_filters parameters from
     * it; page_query has the Profile's dropped parameters removed, so a site
     * filtering owa_source out of its query strings would lose the very tag this
     * reads.
     *
     * ONLY ON THE LANDING BEACON. is_new_session_start is a property of the
     * BEACON, and a landing page view expands into three rows -- page_view,
     * session_start, first_visit -- so all three keep a copy and the pass reads
     * whichever it finds first.
     *
     * @param  object $event
     * @param  string $name  a tagged_* property name
     * @return string|null
     */
    public static function taggedValue( $event, $name ) {

        if ( ! $event->get( 'is_new_session_start' ) ) {

            return null;
        }

        // As the beacon carried it, where the second decode cut it (arrivedUrlIfCut()).
        $arrived = self::arrivedUrlIfCut( $event, 'page_location' );
        $landing = $arrived ?? $event->get( 'page_location' );

        if ( ! $landing || ! is_string( $landing ) ) {

            return null;
        }

        /*
         * MEMOISED PER SITE AS WELL AS PER URL. The key map is a Property
         * setting, so the same URL parses differently for two Properties -- one
         * reading owa_source and one utm_source -- and a memo keyed on the URL
         * alone would hand the first site's answer to the second.
         */
        $site_id = (string) $event->get( 'site_id' );
        $memo    = $site_id . '|' . ( $arrived !== null ? 'arrived|' : '' ) . $landing;

        if ( ! isset( self::$landingTags[ $memo ] ) ) {

            $tags = self::parseLandingTags( $landing, self::campaignKeysFor( $site_id ) );

            // The cleaning page_location had (setDataType()), applied to the decoded values.
            self::$landingTags[ $memo ] = $arrived !== null
                ? array_map( [ \OWA\Module\Base\Classes\Sanitize::class, 'cleanTrackingText' ], $tags )
                : $tags;
        }

        return isset( self::$landingTags[ $memo ][ $name ] )
            ? self::$landingTags[ $memo ][ $name ]
            : null;
    }

    /*
     * The five tags, each set by its own callback off the one memoised parse.
     *
     * Independently and from the registry, like every other property: the row
     * builder is handed a formed event and persists it, rather than computing
     * five values inside a helper of its own.
     */
    /** @var array<string, array<string, mixed>> Prior touches read this process, keyed by site, visitor and session. */
    private static $priorTouches = [];

    /**
     * Drop the memos of stored state: the campaign key maps (scoped settings),
     * the landing tags parsed with them, and the prior touches. The others are
     * of config files, read once per process. CoreAPI::settingCacheFlush()
     * calls this.
     *
     * @return void
     */
    public static function forget() {

        self::$campaignKeys = [];
        self::$landingTags  = [];
        self::$priorTouches = [];
    }

    /**
     * forget(), and the memos of config files and the instance as well. The
     * instance records which filters it has attached to the event dispatcher,
     * so it goes with the dispatcher: CoreAPI::forgetSingletons() calls this.
     *
     * @return void
     */
    public static function forgetAll() {

        self::forget();

        self::$instance              = null;
        self::$property_config       = null;
        self::$property_descriptions = [];
        self::$internal_properties   = [];
        self::$memo                  = [];
        self::$event_names           = null;
        self::$event_config          = null;
        self::$goal_trigger_names    = null;
    }

    /**
     * The visitor's last non-direct touch before this session, from the visitor
     * store, as the evidence it was recorded as.
     *
     * ON THE LANDING BEACON OF A RETURNING VISITOR'S SESSION ONLY -- the one
     * beacon whose rows the cube reads a session's values from. A first visit
     * has no earlier touch, and a later beacon of the session would only repeat
     * the answer.
     *
     * STRICTLY BEFORE THE SESSION. The store holds the LATEST touch, and a queue
     * drain can deliver this beacon after a later session's touch was written.
     * That touch is not this session's to inherit, and the one it displaced is
     * gone, so nothing is stamped rather than something wrong.
     *
     * One read per session, memoised: six properties ask.
     *
     * @param object $event
     * @return array<string, mixed> the last_touch_* values, or an empty array
     */
    public static function priorTouch( $event ) {

        if ( ! $event->get( 'is_new_session_start' )
             || (int) $event->get( 'num_prior_sessions' ) < 1
             || ! $event->get( 'visitor_id' ) ) {

            return [];
        }

        $memo = $event->get( 'site_id' ) . '|' . $event->get( 'visitor_id' ) . '|' . $event->get( 'session_id' );

        if ( isset( self::$priorTouches[ $memo ] ) ) {

            return self::$priorTouches[ $memo ];
        }

        $touch = [];
        $store = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );
        $store->load( $event->get( 'visitor_id' ), 'visitor_id' );

        $ts = (int) $store->get( 'last_touch_ts' );

        if ( $store->wasPersisted() && $ts > 0 && $ts < (int) $event->get( 'ts' ) ) {

            foreach ( [ 'source', 'medium', 'campaign', 'ad', 'referer_host', 'ts' ] as $part ) {

                $value = $store->get( 'last_touch_' . $part );
                $touch[ $part ] = ( $value === false || $value === '' ) ? null : $value;
            }
        }

        return self::$priorTouches[ $memo ] = $touch;
    }

    /**
     * One part of priorTouch(), or null.
     *
     * @param  Event  $event
     * @param  string $part
     * @return mixed
     */
    private static function priorTouchPart( $event, $part ) {

        $touch = self::priorTouch( $event );

        return isset( $touch[ $part ] ) ? $touch[ $part ] : null;
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function resolvePriorTouchSource( $value, $event ) {

        return self::priorTouchPart( $event, 'source' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function resolvePriorTouchMedium( $value, $event ) {

        return self::priorTouchPart( $event, 'medium' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function resolvePriorTouchCampaign( $value, $event ) {

        return self::priorTouchPart( $event, 'campaign' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function resolvePriorTouchAd( $value, $event ) {

        return self::priorTouchPart( $event, 'ad' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function resolvePriorTouchRefererHost( $value, $event ) {

        return self::priorTouchPart( $event, 'referer_host' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return mixed
     */
    public static function resolvePriorTouchTs( $value, $event ) {

        return self::priorTouchPart( $event, 'ts' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function resolveTaggedSource( $value, $event ) {

        return self::taggedValue( $event, 'tagged_source' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function resolveTaggedMedium( $value, $event ) {

        return self::taggedValue( $event, 'tagged_medium' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function resolveTaggedCampaign( $value, $event ) {

        return self::taggedValue( $event, 'tagged_campaign' );
    }

    /**
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function resolveTaggedAd( $value, $event ) {

        return self::taggedValue( $event, 'tagged_ad' );
    }

    /**
     * tagged_terms is the one whose halves do not share a stem -- owa_search_terms
     * on the URL, tagged_terms on the wire, tagged_search_terms as the column.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function resolveTaggedTerms( $value, $event ) {

        return self::taggedValue( $event, 'tagged_terms' );
    }

    /**
     * The URL parameter each campaign role arrives under, for this site.
     *
     * EMPTY SETTING MEANS ns-PREFIXED, which is what OWA has always done and
     * what honours a custom `ns`. A site that sets it names the parameters
     * explicitly instead -- utm_source, utm_medium, utm_campaign, utm_term,
     * utm_content -- so a Property whose links already carry utm_* keeps them.
     * campaignUtmParams (on by default) reads utm_* beside the ns-prefixed
     * names without either being named here.
     *
     * Read at PROFILE scope so the chain walks Profile -> Property -> Install:
     * the install default covers one convention everywhere, and a Property or
     * a Profile overrides it on its own screen.
     *
     * Only the roles CAMPAIGN_KEYS names are read, so a setting cannot invent a
     * tag the pipeline has nowhere to put. A role the setting omits keeps its
     * ns-prefixed name rather than being dropped, so a partial override is
     * partial rather than destructive.
     *
     * @param  string $site_id
     * @return array<string, string>  public parameter name => tagged_* property
     */
    private static function campaignKeysFor( $site_id ) {

        if ( isset( self::$campaignKeys[ $site_id ] ) ) {

            return self::$campaignKeys[ $site_id ];
        }

        $configured = \OWA\Core\CoreAPI::getSetting(
            'base', 'campaignKeys', 'profile', $site_id );

        $configured = is_array( $configured ) ? $configured : [];

        $ns  = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );
        $map = [];

        /*
         * utm_* FIRST. A later key overwrites an earlier one in the parse, so
         * on a URL carrying both conventions OWA's own parameter wins.
         */
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'campaignUtmParams', 'profile', $site_id ) ) {

            $map = self::UTM_KEYS;
        }

        foreach ( self::CAMPAIGN_KEYS as $role => $property ) {

            $public = isset( $configured[ $role ] ) && is_string( $configured[ $role ] )
                   && trim( $configured[ $role ] ) !== ''
                ? trim( $configured[ $role ] )
                : $ns . $role;

            $map[ $public ] = $property;
        }

        return self::$campaignKeys[ $site_id ] = $map;
    }

    /**
     * Pull the campaign parameters out of one landing URL.
     *
     * @param  string $landing
     * @param  array<string, string> $keys  public parameter name => tagged_* property, from campaignKeysFor()
     * @return array<string, string>  wire property name => value
     */
    private static function parseLandingTags( $landing, array $keys ) {

        $uri = self::parse_url( $landing );

        if ( empty( $uri['query'] ) ) {

            return [];
        }

        $params = [];

        parse_str( $uri['query'], $params );

        $tags = [];

        foreach ( $keys as $public => $property ) {

            // Absent and empty are the same claim: the URL said nothing. An
            // empty owa_campaign= must not read as a campaign named ''.
            if ( ! isset( $params[ $public ] ) || ! is_string( $params[ $public ] ) ) {

                continue;
            }

            $value = trim( $params[ $public ] );

            if ( $value === '' ) {

                continue;
            }

            $tags[ $property ] = $value;
        }

        if ( ! isset( $tags['tagged_source'] ) && ! isset( $tags['tagged_medium'] )
             && ! isset( $tags['tagged_campaign'] ) ) {

            foreach ( self::CLICK_IDS as $param => $claim ) {

                if ( isset( $params[ $param ] ) && is_string( $params[ $param ] )
                     && trim( $params[ $param ] ) !== '' ) {

                    $tags['tagged_source'] = $claim[0];
                    $tags['tagged_medium'] = $claim[1];

                    break;
                }
            }
        }

        return $tags;
    }





    /*
     * ---- URL READINGS -----------------------------------------------------
     *
     * Six columns are readings of a URL the beacon sent, and each is set by its
     * own callback off the property it reads -- page_location for the page's
     * three, HTTP_REFERER for the referrer's two, target_url for the click
     * target's host.
     *
     * NO MEMO, unlike the user agent's seven and the campaign tags' five. Those
     * share a parse worth keeping: browscap loads a rule set, and the tag parse
     * walks a settings-derived key map. parse_url is cheap enough that a memo would
     * cost about what it saved.
     *
     * THE THREE READINGS OF page_location ARE INDEPENDENT. path, query and host
     * are disjoint substrings and no callback here reads another's output, so
     * their order in the registry is free.
     */

    /**
     * The page's path, canonicalised.
     *
     * THE READING IS CANONICALISED; THE EVIDENCE IS NOT. page_location is stored
     * as it arrived, less only the query_string_filters parameters, because it
     * is what a corrected parse gets re-applied to. This is what reports group by, and a reading that varies
     * where the page does not is a broken report -- /store, /store/ and
     * /store/index.html are one page.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function derivePagePath( $value, $event ) {

        $arrived = self::arrivedUrlIfCut( $event, 'page_location' );

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl(
            $arrived ?? $event->get( 'page_location' ) );

        // Split as it arrived, then decoded once: an encoded ? or # is part of the path.
        $path = $arrived !== null && $parts['path'] !== null
            ? \OWA\Module\Base\Classes\Sanitize::cleanTrackingText( rawurldecode( $parts['path'] ) )
            : $parts['path'];

        return \OWA\Module\Base\Classes\V2Event::canonicalPath(
            $path, self::directoryIndexPages() );
    }

    /**
     * The install's directory index file names (base.directory_index_pages),
     * as V2Event::indexPageNames() lists them.
     *
     * The one reading of the setting. Ingest canonicalises page_path with it,
     * and every reader that compares a path with page_path (the funnel, any
     * module's) canonicalises with it too, so a path the row stored is the
     * path they look for.
     * Install-wide: which file a web server serves for a directory is not a
     * property of one Profile.
     *
     * @return list<string>
     */
    public static function directoryIndexPages() {

        return \OWA\Module\Base\Classes\V2Event::indexPageNames(
            \OWA\Core\CoreAPI::getSetting( 'base', 'directory_index_pages' ) );
    }

    /**
     * The page's query string, with the Profile's dropped parameters removed.
     *
     * owa_state and the campaign keys are OWA's own plumbing and have no business
     * appearing in somebody's page report. The tags are still recoverable: they
     * are parsed out of page_location, which keeps them.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function derivePageQuery( $value, $event ) {

        $arrived = self::arrivedUrlIfCut( $event, 'page_location' );

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl(
            $arrived ?? $event->get( 'page_location' ) );

        $query = \OWA\Module\Base\Classes\V2Event::filterQuery(
            $parts['query'], self::droppedQueryParams( $event->getSiteId() ) );

        return $arrived !== null ? self::decodedQuery( $query ) : $query;
    }

    /**
     * A query string as it arrived, decoded once pair by pair
     * (V2Event::decodeQuery()), with the cleaning page_location had.
     *
     * @param  string|null $query
     * @return string|null
     */
    private static function decodedQuery( $query ) {

        if ( $query === null || $query === '' ) {

            return $query;
        }

        return \OWA\Module\Base\Classes\Sanitize::cleanTrackingText(
            \OWA\Module\Base\Classes\V2Event::decodeQuery( $query ) );
    }

    /**
     * The page's own host, not this server's (HTTP_HOST).
     *
     * Falls back to whatever the property already held. Nothing on the v2 path
     * sets it: host is set_by event,
     * so admitRequestParams() refuses a beacon that names it. The fallback is
     * kept because a caller building an event in process can still set one, and
     * the column is declared required.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return mixed the page's host, else the value
     */
    public static function deriveHost( $value, $event ) {

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl(
            $event->get( 'page_location' ) );

        return $parts['host'] ?: $value;
    }

    /**
     * The referring site's host, and what the cube pass classifies source and
     * medium from.
     *
     * EMPTY WHEN THE REFERRER IS THE SITE ITSELF, by the rule a click uses
     * (isSiteHost()): the page's own host, the cookie domain and its
     * subdomains. A session that starts from one of the site's pages -- it
     * expired between two page views, or restarted on an idle tab -- then reads
     * as direct and inherits the visitor's last touch, rather than being a
     * referral from the site to itself that displaces that touch. referer_url
     * keeps the URL.
     *
     * The referrer is deliberately left alone beyond its host and query: it is
     * somebody else's URL, and collapsing it with THIS install's directory index pages
     * would be a category error.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function deriveRefererHost( $value, $event ) {

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl(
            $event->get( 'HTTP_REFERER' ) );

        if ( $parts['host'] === null ) {

            return null;
        }

        // page_location, not the derived host: the registry walks in key
        // order, so another derived property may not be set yet.
        $page = \OWA\Module\Base\Classes\V2Event::parseUrl( $event->get( 'page_location' ) );

        return self::isSiteHost( $parts['host'], $page['host'],
            self::cookieDomain( (string) $event->getSiteId() ) ) ? null : $parts['host'];
    }

    /**
     * The tracker_cookie_domain a site's tracker is given, empty for the
     * default (the page's host without www.).
     *
     * @param  string $site_id
     * @return string
     */
    public static function cookieDomain( $site_id ) {

        return $site_id === '' ? '' : trim( (string)
            \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_cookie_domain', 'profile', $site_id ) );
    }

    /**
     * The referrer's query string, as it arrived -- see deriveRefererHost().
     * filterStoredUrls() then removes the query_string_filters parameters.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function deriveRefererQuery( $value, $event ) {

        $arrived = self::arrivedUrlIfCut( $event, 'HTTP_REFERER' );

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl(
            $arrived ?? $event->get( 'HTTP_REFERER' ) );

        return $arrived !== null ? self::decodedQuery( $parts['query'] ) : $parts['query'];
    }

    /**
     * The click target's host, so an outbound click is a comparison against host
     * rather than a string test.
     *
     * Reads target_url AFTER makeUrlCanonical() has filtered it: target_url is a
     * client property and this is a derived one, so the scopes settle the order.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function deriveTargetHost( $value, $event ) {

        $parts = \OWA\Module\Base\Classes\V2Event::parseUrl(
            $event->get( 'target_url' ) );

        return $parts['host'];
    }

    /**
     * Whether a click to $target_host left the site, by the tracker's rule
     * (OWATracker.isOutboundUrl()), for data that arrives without the tracker
     * having decided -- v1's clicks, migrated.
     *
     * THE COOKIE DOMAIN IS THE SITE. The page's own host is never outbound, and
     * neither is the cookie domain or any subdomain of it. The cookie domain is
     * the tracker_cookie_domain setting when one is set, else the page's host
     * without www. -- as the tracker's setCookieDomain() resolves it with nothing passed -- so
     * from www.example.com a link to example.com or shop.example.com stays.
     *
     * @param  string|null $target_host
     * @param  string|null $page_host
     * @param  string|null $cookie_domain  tracker_cookie_domain; empty for the default
     * @return bool
     */
    public static function isOutboundHost( $target_host, $page_host, $cookie_domain = '' ) {

        // Nothing to compare: no target, or no page and no configured domain.
        if ( trim( (string) $target_host ) === ''
             || self::siteDomain( $page_host, $cookie_domain ) === '' ) {

            return false;
        }

        return ! self::isSiteHost( $target_host, $page_host, $cookie_domain );
    }

    /**
     * Whether $host is the site's own: the page's host, the cookie domain, or
     * any subdomain of it -- the rule isOutboundHost() and deriveRefererHost()
     * share. False when there is no host, or nothing to compare it against.
     *
     * A trailing dot is the same host (www.example.com. is www.example.com).
     *
     * @param  string|null $host
     * @param  string|null $page_host
     * @param  string|null $cookie_domain  tracker_cookie_domain; empty for the default
     * @return bool
     */
    public static function isSiteHost( $host, $page_host, $cookie_domain = '' ) {

        $host = rtrim( strtolower( trim( (string) $host ) ), '.' );
        $page = rtrim( strtolower( trim( (string) $page_host ) ), '.' );

        if ( $host === '' ) {

            return false;
        }

        if ( $host === $page ) {

            return true;
        }

        $domain = self::siteDomain( $page, $cookie_domain );

        return $domain !== ''
            && ( $host === $domain || substr( $host, - ( strlen( $domain ) + 1 ) ) === '.' . $domain );
    }

    /**
     * The domain that is the site: tracker_cookie_domain when set, else the
     * page's host without www. -- as the tracker's setCookieDomain() resolves it with nothing
     * passed.
     *
     * @param  string|null $page_host
     * @param  string|null $cookie_domain
     * @return string empty when there is neither
     */
    private static function siteDomain( $page_host, $cookie_domain ) {

        $domain = strtolower( trim( trim( (string) $cookie_domain ), '.' ) );

        if ( $domain !== '' ) {

            return $domain;
        }

        $page = rtrim( strtolower( trim( (string) $page_host ) ), '.' );

        return strncmp( $page, 'www.', 4 ) === 0 ? substr( $page, 4 ) : $page;
    }

    /**
     * anchor_x: thousandths of the clicked element's width, or nothing.
     *
     * Bounded because the column is a SMALLINT and the value comes off the
     * wire: a beacon carrying 99999 would otherwise be refused by strict mode,
     * and the whole click with it. Deletes rather than returning null, for the
     * reason gateUserId() gives.
     *
     * @param mixed  $value
     * @param object $event
     * @return int|null
     */
    public static function anchorX( $value, $event ) {

        return self::anchorPoint( 'anchor_x', $value, $event );
    }

    /**
     * anchor_y: as anchorX(), down the element's height.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return int|null
     */
    public static function anchorY( $value, $event ) {

        return self::anchorPoint( 'anchor_y', $value, $event );
    }

    /**
     * @param  string $name
     * @param  mixed  $value
     * @param  Event  $event
     * @return int|null
     */
    private static function anchorPoint( $name, $value, $event ) {

        if ( is_numeric( $value ) && $value >= 0 && $value <= 1000 ) {

            return (int) $value;
        }

        $event->delete( $name );

        return null;
    }

    /**
     * element_anchor: the clicked element's 53-bit hash (ElementAnchor.js), kept
     * only with both of its points.
     *
     * Reads anchor_x and anchor_y as their own callbacks left them, which is
     * why the registry declares it after them. A row with an anchor is one the
     * heatmap can place; a hash without its point could not be, and a click
     * with none is drawn by click_x and click_y or not at all. Deletes rather
     * than returning null, for the reason gateUserId() gives.
     *
     * @param mixed  $value
     * @param object $event
     * @return int|null
     */
    public static function elementAnchor( $value, $event ) {

        if ( is_numeric( $event->get( 'anchor_x' ) ) && is_numeric( $event->get( 'anchor_y' ) )
          && is_numeric( $value ) && $value >= 0 && $value <= 9007199254740991 ) {

            return (int) $value;
        }

        $event->delete( 'element_anchor' );

        return null;
    }

    /**
     * A money property as the minor units its column stores.
     *
     * ONE CALLBACK FOR ALL THREE -- ct_total, ct_tax, ct_shipping -- because it
     * converts its own value and reads nothing else. event_raw.revenue, tax and
     * shipping are BIGINTs of minor units with the currency beside them, because
     * a float column sums to something nobody can reconcile.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return int|null
     */
    public static function toMinorUnits( $value, $event ) {

        return Currency::toMinorUnits( $value, self::purchaseCurrency( $event ) );
    }

    /**
     * The currency a purchase's amounts are in: the one it sent, else its
     * Property's (the currencyISO3 setting, which inherits from the install).
     *
     * Read here rather than off the resolved `currency` property so the money
     * callbacks do not depend on where currency sits in the registry.
     *
     * @param  object $event
     * @return string an ISO 4217 code
     */
    public static function purchaseCurrency( $event ) {

        $sent = Currency::normalize( $event->get( 'currency' ) );

        if ( $sent !== '' ) {

            return $sent;
        }

        $site = (string) $event->getSiteId();

        $configured = Currency::normalize( $site !== ''
            ? \OWA\Core\CoreAPI::getSiteSetting( $site, 'currencyISO3' )
            : \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3' ) );

        return $configured !== '' ? $configured : 'USD';
    }

    /**
     * What a refund with no value refunds, in minor units: its items' price
     * times quantity, else -- the transaction id alone -- the whole purchase's
     * revenue. NULL when there is neither.
     *
     * @param  object $event
     * @return int|null
     */
    public static function refundAmount( $event ) {

        $items = $event->get( 'ct_line_items' );

        if ( is_string( $items ) ) {

            $items = json_decode( $items, true );
        }

        if ( is_array( $items ) && $items ) {

            $major = 0;

            foreach ( $items as $item ) {

                if ( is_array( $item ) && is_numeric( $item['price'] ?? null ) ) {

                    $major += (float) $item['price']
                        * ( is_numeric( $item['quantity'] ?? null ) ? (float) $item['quantity'] : 1 );
                }
            }

            return Currency::toMinorUnits( $major, self::purchaseCurrency( $event ) );
        }

        $transaction = trim( (string) $event->get( 'ct_order_id' ) );
        $site        = (string) $event->getSiteId();

        if ( $transaction === '' || $site === '' ) {

            return null;
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $refused = $db->queryErrorCount();

        $row = $db->get_row( sprintf(
            "SELECT revenue FROM %s WHERE site_id = ? AND transaction_id = ? AND event_type = 'purchase' LIMIT 1",
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ), [ $site, $transaction ] );

        $revenue = is_array( $row ) ? ( $row['revenue'] ?? null ) : ( is_object( $row ) ? ( $row->revenue ?? null ) : null );

        /*
         * Stored without an amount, the refund leaves net revenue overstated for
         * good: said so, by transaction. The purchase may not be ingested yet
         * (the queue keeps no order), or the lookup was refused.
         */
        if ( ! is_numeric( $revenue ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'v2 ingest: refund of transaction %s on %s carries no amount, and %s; its revenue is stored as empty.',
                $transaction, $site, $db->queryErrorCount() !== $refused
                    ? 'the purchase could not be looked up' : 'no purchase with that transaction is stored' ) );

            return null;
        }

        return (int) $revenue;
    }

    /**
     * The `currency` property: on a purchase, always a code -- the one sent, else
     * the Property's -- so revenue never sits beside a NULL currency. On any other
     * event a well-formed code is kept and anything else dropped.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return string|null
     */
    public static function resolveCurrency( $value, $event ) {

        $name = (string) $event->getEventType();

        if ( $name === 'purchase' || $name === 'refund' ) {

            return self::purchaseCurrency( $event );
        }

        $code = Currency::normalize( $value );

        return $code !== '' ? $code : null;
    }

    /**
     * Revenue EXCLUDING tax and shipping, in minor units: the value when the
     * purchase sent one, else the order total less both (all converted by the
     * time this runs -- see the registry order). NULL when neither was sent.
     *
     * Tax and shipping have columns of their own, so a total that kept them
     * counted them twice wherever the three were summed.
     *
     * @param  mixed $value
     * @param  Event $event
     * @return int|null
     */
    public static function deriveRevenue( $value, $event ) {

        // trackPurchase() and trackRefund() send the value itself, tax and
        // shipping excluded.
        $sent = $event->get( 'ct_value' );

        if ( $sent !== null && $sent !== false && $sent !== '' && is_numeric( $sent ) ) {

            return (int) $sent;
        }

        if ( (string) $event->getEventType() === 'refund' ) {

            return self::refundAmount( $event );
        }

        $total = $event->get( 'ct_total' );

        if ( $total === null || $total === false || $total === '' || ! is_numeric( $total ) ) {

            return null;
        }

        $tax      = $event->get( 'ct_tax' );
        $shipping = $event->get( 'ct_shipping' );

        return (int) $total
            - ( is_numeric( $tax ) ? (int) $tax : 0 )
            - ( is_numeric( $shipping ) ? (int) $shipping : 0 );
    }

    /**
     * The query parameters that come out of a stored page_query, for one site.
     *
     * OWA's own control parameters, every parameter the site reads as a campaign
     * tag (campaignKeysFor(): the ns-prefixed names, a Property's campaignKeys,
     * and utm_* while campaignUtmParams is on), the ns-prefixed
     * RETIRED_CAMPAIGN_KEYS, plus the site's query_string_filters at both
     * install and Profile scope.
     *
     * THE CAMPAIGN TAGS ARE ONE LIST, utm_* included: they leave the page path's
     * query, so a tagged visit is the same page as an untagged one in every page
     * report, and stay in page_location. Attribution parses page_location
     * (filterStoredUrls() removes only the query_string_filters names, and after
     * the tags have been read).
     *
     * The list is per site and settings change, so what a report shows depends on
     * the list as it was when the row was written. Making a corrected list
     * re-apply to history means filtering in the build instead, which is the
     * argument the cube exists for and is deferred rather than dismissed
     * (2.28.2).
     *
     * @param  string $site_id
     * @return string[]
     */
    public static function droppedQueryParams( $site_id ) {

        $ns = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );

        $drop = array_merge( array_keys( self::campaignKeysFor( (string) $site_id ) ), [
            $ns . 'overlay',
            $ns . 'state',
            $ns . (string) \OWA\Core\CoreAPI::getSetting( 'base', 'feed_subscription_param' ),
        ] );

        foreach ( self::RETIRED_CAMPAIGN_KEYS as $suffix ) {

            $drop[] = $ns . $suffix;
        }

        return array_merge( $drop, self::queryStringFilters( $site_id ) );
    }

    /**
     * The parameter names query_string_filters lists, at install and Profile
     * scope.
     *
     * @param  string $site_id
     * @return string[]
     */
    public static function queryStringFilters( $site_id ) {

        $names = [];

        foreach ( [
            \OWA\Core\CoreAPI::getSetting( 'base', 'query_string_filters' ),
            \OWA\Core\CoreAPI::getSetting( 'base', 'query_string_filters', 'profile', (string) $site_id ),
        ] as $configured ) {

            if ( ! $configured ) {

                continue;
            }

            foreach ( explode( ',', (string) $configured ) as $name ) {

                $name = trim( $name );

                if ( $name !== '' ) {

                    $names[] = $name;
                }
            }
        }

        return array_values( array_unique( $names ) );
    }

    /**
     * Remove the query_string_filters parameters from the URLs an event
     * stores whole: page_location, HTTP_REFERER (referer_url) and
     * referer_query.
     *
     * RUN AFTER EVERY DERIVED PROPERTY (ProcessEvent), because those read the
     * URLs first: the campaign tags, page_path, page_query, host and the
     * referrer's host and query are taken from what arrived, so a filtered
     * name that is also a campaign key is still attributed. What is stored,
     * and what landing_page_location and every other copy of page_location
     * are made from, is the filtered URL. A URL naming none of the filtered
     * parameters is stored exactly as it arrived.
     *
     * Only the configured names: OWA's own parameters and the campaign tags
     * stay in page_location, which is the evidence they are read from.
     *
     * @param object $event
     * @return void
     */
    public static function filterStoredUrls( $event ) {

        $drop = self::queryStringFilters( (string) $event->getSiteId() );

        if ( ! $drop ) {

            return;
        }

        foreach ( [ 'page_location', 'HTTP_REFERER' ] as $name ) {

            $url = $event->get( $name );

            if ( is_string( $url ) && $url !== '' ) {

                $filtered = \OWA\Module\Base\Classes\V2Event::removeUrlParams( $url, $drop );

                if ( $filtered !== $url ) {

                    $event->set( $name, $filtered );
                }
            }
        }

        $query = $event->get( 'referer_query' );

        if ( is_string( $query ) && $query !== '' ) {

            $filtered = \OWA\Module\Base\Classes\V2Event::removeQueryParams( $query, $drop );

            if ( $filtered === null ) {

                $event->delete( 'referer_query' );

            } elseif ( $filtered !== $query ) {

                $event->set( 'referer_query', $filtered );
            }
        }
    }

    /**
     * Filter function Strips a URL of certain defined session or tracking params
     *
     * The host is kept as it arrived, whether or not it is the Profile's domain.
     *
     * @param  string|null $url
     * @param  Event       $event
     * @return string|null
     */
    public static function makeUrlCanonical( $url, $event ) {
    if(is_null($url)){
        return $url;
    }

        $site_id = $event->getSiteId();

        if ( ! $site_id ) {
            \OWA\Core\CoreAPI::debug('no site_id passed to make makeUrlCanonical. Returning URL as is.');
            return $url;
        }

        $url = html_entity_decode( $url );
        // remove port, pass, user, and fragment
        $url = \OWA\Core\Lib::unparseUrl( parse_url( $url ), [ 'port', 'user', 'pass', 'fragment' ] );

        \OWA\Core\CoreAPI::debug('makeUrlCanonical using site_id: '.$site_id);

        // The list page_query and referer_query are filtered by: the campaign
        // tags (utm_* among them where read), OWA's own and the filters.
        $filters = self::droppedQueryParams( (string) $site_id );

        /*
         * Pair by pair, as page_query is (V2Event::filterQuery). The regex this
         * replaces removed a filtered parameter and everything after it, matched
         * a name inside a longer one ("id" in product_id), and read an empty
         * name -- a trailing comma in the list -- as every parameter.
         */
        $at = strpos( $url, '?' );

        if ( $at !== false ) {

            $query = \OWA\Module\Base\Classes\V2Event::filterQuery( substr( $url, $at + 1 ), $filters );
            $url   = substr( $url, 0, $at ) . ( $query === null ? '' : '?' . $query );
        }


        //check for dangling '?'. this might occure if all params are stripped.

        // returns last character of string
        $test = substr($url, -1);

        // if dangling '?' is found clean up the url by removing it.
        if ($test == '?') {
            $url = substr($url, 0, -1);
        }

        /*
         * A directory index page, as page_path drops one. Only on a URL with no
         * query left: its last segment is then the path's, where with a query
         * it would be part of a parameter's value.
         */
        if ( strpos( $url, '?' ) === false ) {

            $url = \OWA\Module\Base\Classes\V2Event::dropIndexPage( $url, self::directoryIndexPages() );
        }

        // check and remove trailing slash
        if (substr($url, -1) === '/') {

            $url = substr($url, 0, -1);
        }

        return self::makeUrlStorageSafe( $url );

    }

}
