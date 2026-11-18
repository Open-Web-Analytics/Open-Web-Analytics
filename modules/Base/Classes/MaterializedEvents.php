<?php
namespace OWA\Module\Base\Classes;

/**
 * The callbacks that materialize session_start and first_visit.
 *
 * Each is a listener on Ingest::TRACKING_EVENTS_PRE_SAVE. It is handed the set of
 * events one beacon is about to be saved as, the incoming event first, and
 * returns the set with its event appended when the incoming event carries the
 * flag for it. The set is then saved in one transaction.
 *
 * FROM THE INCOMING EVENT ONLY. The flag is request scoped: it rides exactly the
 * one beacon that created the session or minted the visitor, whatever event that
 * was. Reading only element 0 is also what keeps a materialized event from
 * materializing another.
 *
 * DECIDED FROM THE SET ALONE. A write that fails is retried from the queue with
 * the incoming event, and the filter runs again. The same set has to come out,
 * with the same derived ids, or the retry's idempotence check reads the wrong
 * row.
 */
class MaterializedEvents {

    /**
     * Raise session_start when the incoming event started the session.
     *
     * @param  mixed $events  the set, a list of events; a filter's value, so
     *                        anything else is handed back as it came
     * @return mixed
     */
    public static function sessionStart( $events ) {

        return self::raise( $events, 'is_new_session_start', V2Event::MARKER_SESSION_START );
    }

    /**
     * Raise first_visit when the incoming event minted the visitor.
     *
     * @param  mixed $events  as sessionStart() takes it
     * @return mixed
     */
    public static function firstVisit( $events ) {

        return self::raise( $events, 'is_new_visitor_created', V2Event::MARKER_FIRST_VISIT );
    }

    /**
     * @param  mixed  $events  incoming event first; anything but a non-empty array is handed back
     * @param  string $flag    the request-scoped property that raises it
     * @param  string $name    the materialized event name
     * @return mixed
     */
    private static function raise( $events, $flag, $name ) {

        if ( ! is_array( $events ) || ! $events ) {

            return $events;
        }

        $carrier = reset( $events );

        if ( ! is_object( $carrier ) || ! $carrier->get( $flag ) ) {

            return $events;
        }

        $events[] = TrackingEventHelpers::materialize( $carrier, $name );

        return $events;
    }
}
