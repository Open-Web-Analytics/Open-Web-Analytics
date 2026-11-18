<?php
namespace OWA\Module\Base\Classes\Beacon;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Normalises an incoming beacon into the CURRENT format, once, before anything
 * reads it.
 *
 * WHY ONE PLACE. Every bridge between an older tracker's beacon and the
 * current one behaves alike and can be enumerated: the index
 * (conf/beacon_compat.php) lists them; this applies it.
 *
 * PRESENCE, NOT TRUTHINESS. A falsy test cannot tell "absent" from "present
 * and false", so a flag legitimately sent as false would be read as never
 * sent. Asking whether the key is THERE needs no carve-outs: 0, "0", '' and
 * false are all values a beacon may legitimately send.
 *
 * WHAT IT IS NOT. Not a contract and not a gate. It never decides whether a
 * beacon is acceptable -- that is the identity guard in
 * EventRawHandlers::row(), which knows nothing of versions or renames. This
 * only ever ADDS the current spelling of a value that is already present, and
 * never overwrites one the beacon already carries under its current name.
 */
class Compat {

    /** @var array<string, string>|null the indexed renames, old => new */
    private static $renames = null;

    /** @var array<string, string>|null old event name => current event name */
    private static $event_names = null;

    /**
     * The event names an older tracker sent, and what each is now.
     *
     * The ONE place v1 event names live. Everything after Compat::apply() --
     * the admission gate, the dispatch name, the processor router, the handlers
     * -- sees only current names.
     *
     * @return array<string, string> old name => current name
     */
    public static function eventNames() {

        if ( self::$event_names === null ) {

            $conf = (array) \OWA\Core\CoreAPI::loadConf(
                'beacon_compat.php', 'beacon.compat' );

            self::$event_names = isset( $conf['event_names'] )
                ? (array) $conf['event_names']
                : [];
        }

        return self::$event_names;
    }

    /**
     * The current name for an event type: renamed if an older tracker's
     * spelling, as given otherwise.
     *
     * @param  string $event_type
     * @return string
     */
    public static function eventName( $event_type ) {

        $event_type = (string) $event_type;
        $names      = self::eventNames();

        return isset( $names[ $event_type ] ) ? $names[ $event_type ] : $event_type;
    }

    /**
     * Every rename the index declares, whichever role it plays.
     *
     * A `wire` rename is the short name the current tracker sends and a
     * `legacy` one is what an older tracker sent -- a distinction about when an
     * entry may be DELETED, not about how it is applied. Both are the same
     * operation here.
     *
     * @return array<string, string> old name => current name
     */
    public static function renames() {

        if ( self::$renames === null ) {

            $conf = (array) \OWA\Core\CoreAPI::loadConf(
                'beacon_compat.php', 'beacon.compat' );

            self::$renames = [];

            foreach ( (array) ( isset( $conf['renames'] ) ? $conf['renames'] : [] )
                      as $entry ) {

                self::$renames[ $entry['from'] ] = $entry['to'];
            }
        }

        return self::$renames;
    }

    /**
     * Properties an OLDER beacon generation carries that the current format
     * does not declare.
     *
     * THIS IS NOT A v1 SHIM, and the distinction is the whole reason the
     * mechanism is generic: this class normalises any earlier beacon into the
     * CURRENT format, and the current format keeps moving. Browsers cache
     * trackers, so every change to the wire leaves a generation still sending
     * the old shape -- v1 to v2 today, v2.0 to v2.1 next. What is droppable is
     * an ENTRY, once no beacon carries it any more; the contribution point
     * stays.
     *
     * The entry is the v1 custom variable slots: v1 carried five numbered ones,
     * the current format carries named keys under `eps_` and `vps_`, and
     * params() reads a slot off an older beacon and writes it out as the same
     * named param a current one produces. Not in tracking_properties.json,
     * which states what the CURRENT format carries.
     *
     * Contributed to the MAPS rather than to the config, so the runtime sees
     * them -- the allowlist at log.php admits `cv1` because the regular map
     * has it -- while propertiesForEvent() reads the config and therefore does
     * NOT offer them. That asymmetry is the point: an older beacon may carry
     * one, and nothing in the current vocabulary may be written against one.
     *
     * @param  array<string, array<string, mixed>> $properties  the regular (client-settable) map: property => its definition
     * @return array<string, array<string, mixed>>
     */
    public static function contributeClientProperties( $properties ) {

        $properties = (array) $properties;

        for ( $i = 1; $i <= self::slotCount(); $i++ ) {

            if ( ! array_key_exists( 'cv' . $i, $properties ) ) {

                $properties[ 'cv' . $i ] = [
                    'required'      => false,
                    'data_type'     => 'string',
                    'default_value' => '',
                ];
            }
        }

        return $properties;
    }

    /**
     * And the halves the server splits each slot into.
     *
     * Same contribution point, the derived map rather than the regular one.
     *
     * @param  array<string, array<string, mixed>> $properties  the derived map: property => its definition
     * @return array<string, array<string, mixed>>
     */
    public static function contributeDerivedProperties( $properties ) {

        $properties = (array) $properties;

        for ( $i = 1; $i <= self::slotCount(); $i++ ) {

            foreach ( [ 'name', 'value' ] as $half ) {

                $key = 'cv' . $i . '_' . $half;

                if ( array_key_exists( $key, $properties ) ) {

                    continue;
                }

                /*
                 * NO STORAGE SENTINEL: the reporting layer renders absence as "(not set)" at
                 * read time.
                 */
                $properties[ $key ] = [
                    'set_by'    => 'client',
                    'from'      => [ $key ],
                    'events'    => [ \OWA\Module\Base\Classes\TrackingEventHelpers::EVERY_EVENT ],
                    'required'  => true,
                    'data_type' => 'string',
                    'callbacks' => [ 'OWA\\Module\\Base\\Classes\\TrackingEventHelpers::lowercaseString' ],
                ];
            }
        }

        return $properties;
    }

    /**
     * How many slots that generation carried, which is still a setting.
     *
     * @return int
     */
    private static function slotCount() {

        return (int) \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );
    }

    /**
     * The names an older beacon uses that this layer renames on the way in.
     *
     * THE ALLOWLIST HAS TO ADMIT THESE OR THE BRIDGE CANNOT FIRE. apply() runs
     * after the event is built, so a name it renames must survive the gate at
     * log.php first -- and most are not registered properties at all: they
     * exist only as the FROM side of a rename. Under an allowlist they have to
     * be named, and this is the only place that knows they are legitimate.
     *
     * Read from the same index apply() reads, so the two cannot disagree about
     * which names are bridged.
     *
     * @return string[]
     */
    public static function bridgedNames() {

        return array_keys( self::renames() );
    }

    /**
     * apply(), for an event read back off a queue.
     *
     * A tracking event queued by an older tracker, or before an upgrade,
     * carries its old name AND the dispatch key logEvent() built from it.
     * Both become current, so the drain routes it as logEvent() would route
     * it live. An internal (non-tracking) event is left alone.
     *
     * @param  object $event
     * @return int how many renames were applied
     */
    public static function applyToQueued( $event ) {

        if ( ! $event->isTrackingEvent() ) {

            return 0;
        }

        $applied = self::apply( $event );

        $event->setDispatchName(
            \OWA\Core\CoreAPI::trackingDispatchName( $event->getEventType() ) );

        return $applied;
    }

    /**
     * Put the current spelling on the event for anything sent under an old one.
     *
     * @param object $event
     * @return int how many renames were applied, for callers that want to log
     */
    public static function apply( $event ) {

        $applied = 0;

        // The event's own name first: nothing downstream knows an old spelling.
        $type    = (string) $event->getEventType();
        $current = self::eventName( $type );

        if ( $current !== $type ) {

            $event->setEventType( $current );
            $applied++;
        }

        foreach ( self::renames() as $from => $to ) {

            /*
             * PRESENCE IS READ OFF THE PROPERTY BAG, not off get().
             *
             * Event::get() answers `false` for a key that is not there -- so
             * asking it cannot distinguish absent from a stored false, which is
             * the very distinction this mechanism exists to make. Reading the
             * array directly is the only honest presence test, and getting this
             * wrong the first time made the layer skip every rename: the
             * canonical key looked present, as false, on every event.
             */
            $properties = $event->getProperties();

            // The current name wins whenever the beacon carries it, even as
            // false or 0. A tracker sending both is mid-upgrade, and the newer
            // spelling is the one it means.
            if ( array_key_exists( $to, $properties ) && $properties[ $to ] !== '' ) {

                continue;
            }

            if ( ! array_key_exists( $from, $properties ) || $properties[ $from ] === '' ) {

                continue;
            }

            $value = $properties[ $from ];

            $event->set( $to, $value );

            $applied++;
        }

        return $applied;
    }
}
