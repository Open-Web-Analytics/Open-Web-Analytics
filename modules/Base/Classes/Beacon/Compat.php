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
 * WHY ONE PLACE. The bridges between an older tracker's beacon and the current
 * one had accumulated across seven mechanisms, and the cost was not the count
 * -- it was that no two behaved alike and nothing could enumerate them. The
 * index (conf/beacon_compat.php) made the set visible; this applies it.
 *
 * PRESENCE, NOT TRUTHINESS, and that is the substantive change. The mechanism
 * this replaces fired when the canonical key was FALSY:
 *
 *     if ( ! $value && $value !== 0 && $value !== "0" )
 *
 * which cannot tell "absent" from "present and false". Fine for the counters
 * and strings it carried, with the zero cases carved out by hand -- and the
 * reason a BOOLEAN could never use it: a flag legitimately sent as false is
 * indistinguishable from one never sent, so the fallback fires on it and
 * asserts the opposite of what the tracker said. That is why the flag
 * fallbacks had to be open-coded instead, in three places, one of which
 * deliberately differs from the other two.
 *
 * Asking whether the key is THERE has no such hole, and it needs no carve-outs:
 * 0, "0", '' and false are all values a beacon may legitimately send.
 *
 * WHAT IT IS NOT. Not a contract and not a gate. It never decides whether a
 * beacon is acceptable -- that is the identity guard in
 * EventRawHandlers::row(), which knows nothing of versions or renames. This
 * only ever ADDS the current spelling of a value that is already present, and
 * never overwrites one the beacon already carries under its current name.
 */
class Compat {

    /** @var array|null the indexed renames, old => new */
    private static $renames = null;

    /**
     * Every rename the index declares, whichever role it plays.
     *
     * A `wire` rename is the short name the current tracker sends and a
     * `legacy` one is what an older tracker sent -- a distinction about when an
     * entry may be DELETED, not about how it is applied. Both are the same
     * operation here.
     *
     * @return array old name => current name
     */
    public static function renames() {

        if ( self::$renames === null ) {

            $conf = (array) \OWA\Core\CoreAPI::loadConf(
                'beacon_compat.php', 'beacon.compat' );

            self::$renames = array();

            foreach ( (array) ( isset( $conf['renames'] ) ? $conf['renames'] : array() )
                      as $entry ) {

                self::$renames[ $entry['from'] ] = $entry['to'];
            }
        }

        return self::$renames;
    }

    /**
     * Put the current spelling on the event for anything sent under an old one.
     *
     * @param object $event
     * @return int how many renames were applied, for callers that want to log
     */
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
     * Today's entry is the v1 custom variable slots: v1 carried five numbered
     * ones, the current format carries named keys under `ep_` and `up_`, and
     * params() still reads a slot off an older beacon and writes it out as the
     * same named param a current one produces.
     *
     * They were in tracking_properties.json, which is the statement of what
     * the CURRENT format carries -- so a compat property sitting there made a
     * measurement of what v2 reads report them as dead, because the slot names
     * are built at runtime and appear nowhere as literals.
     *
     * Contributed to the MAPS rather than to the config, so the runtime sees
     * them -- the allowlist at log.php admits `cv1` because the regular map
     * has it -- while propertiesForEvent() reads the config and therefore does
     * NOT offer them. That asymmetry is the point: an older beacon may carry
     * one, and nothing in the current vocabulary may be written against one.
     *
     * @param  array $properties  the regular (client-settable) map
     * @return array
     */
    public static function contributeClientProperties( $properties ) {

        $properties = (array) $properties;

        for ( $i = 1; $i <= self::slotCount(); $i++ ) {

            if ( ! array_key_exists( 'cv' . $i, $properties ) ) {

                $properties[ 'cv' . $i ] = array(
                    'required'      => false,
                    'data_type'     => 'string',
                    'default_value' => '',
                );
            }
        }

        return $properties;
    }

    /**
     * And the halves the server splits each slot into.
     *
     * Same contribution point, the derived map rather than the regular one.
     *
     * @param  array $properties  the derived map
     * @return array
     */
    public static function contributeDerivedProperties( $properties ) {

        $properties = (array) $properties;

        for ( $i = 1; $i <= self::slotCount(); $i++ ) {

            foreach ( array( 'name', 'value' ) as $half ) {

                $key = 'cv' . $i . '_' . $half;

                if ( array_key_exists( $key, $properties ) ) {

                    continue;
                }

                /*
                 * NO STORAGE SENTINEL. It used to declare '(not set)', which
                 * reached only v1's NOT NULL text columns -- a nullable column
                 * is opted out of the substitution, so no v2 column ever
                 * received it, and the reporting layer renders absence as that
                 * same label at read time.
                 */
                $properties[ $key ] = array(
                    'set_by'    => 'client',
                    'from'      => array( $key ),
                    'events'    => array( \OWA\Module\Base\Classes\TrackingEventHelpers::EVERY_EVENT ),
                    'required'  => true,
                    'data_type' => 'string',
                    'callbacks' => array( 'owa_trackingEventHelpers::lowercaseString' ),
                );
            }
        }

        return $properties;
    }

    /** How many slots that generation carried, which is still a setting. */
    private static function slotCount() {

        return (int) \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );
    }

    /**
     * The names an older beacon uses that this layer renames on the way in.
     *
     * THE ALLOWLIST HAS TO ADMIT THESE OR THE BRIDGE CANNOT FIRE. apply() runs
     * after the event is built, so a name it renames must survive the gate at
     * log.php first -- and three of the four are not registered properties at
     * all: `dsfs`, `dsps` and `email_address` exist only as the FROM side of a
     * rename. The old gate was a denylist and passed anything unregistered, so
     * they arrived by accident. Under an allowlist they have to be named, and
     * this is the only place that knows they are legitimate.
     *
     * Read from the same index apply() reads, so the two cannot disagree about
     * which names are bridged.
     *
     * @return string[]
     */
    public static function bridgedNames() {

        return array_keys( self::renames() );
    }

    public static function apply( $event ) {

        $applied = 0;

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

?>
