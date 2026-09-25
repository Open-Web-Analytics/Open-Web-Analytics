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
