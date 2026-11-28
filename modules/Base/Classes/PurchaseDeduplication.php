<?php
namespace OWA\Module\Base\Classes;

/**
 * A purchase is stored once per transaction id.
 *
 * A receipt page reloaded, or reached again from history, sends its purchase
 * again -- with a new arrival time, so a new row id, so the idempotence check on
 * the id does not see it, and it would be stored twice.
 *
 * A callback on Ingest::TRACKING_EVENTS_PRE_SAVE at priority 50: after the
 * materializers, so a session_start or first_visit the beacon carried is kept
 * and only the purchase goes; before goal marking, so a dropped purchase is not
 * marked.
 *
 * PER SITE, and only for a transaction id that is not empty. An empty id would
 * make every id-less purchase a duplicate of the first.
 *
 * Two copies arriving at the same instant can both pass the lookup; the window
 * is one request's length.
 */
class PurchaseDeduplication {

    /**
     * @param  mixed $events the set, incoming event first; a filter's value, so
     *                       anything but a non-empty array is handed back as it came
     * @return mixed
     */
    public static function drop( $events ) {

        if ( ! is_array( $events ) || ! $events ) {

            return $events;
        }

        $removed = false;

        foreach ( $events as $key => $event ) {

            if ( is_object( $event ) && self::isStoredAlready( $event ) ) {

                \OWA\Core\CoreAPI::debug( sprintf(
                    'v2 ingest: purchase %s is already stored for this site; not storing it again.',
                    $event->get( 'ct_order_id' ) ) );

                unset( $events[ $key ] );
                $removed = true;
            }
        }

        // Untouched unless something went: a value this did not change is passed
        // on as it came.
        return $removed ? array_values( $events ) : $events;
    }

    /**
     * @param  \OWA\Module\Base\Classes\Event $event
     * @return bool
     */
    private static function isStoredAlready( $event ) {

        if ( (string) $event->getEventType() !== 'purchase' ) {

            return false;
        }

        $transaction = trim( (string) $event->get( 'ct_order_id' ) );
        $site        = (string) $event->getSiteId();

        if ( $transaction === '' || $site === '' ) {

            return false;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        // Case-sensitive: AB12 and ab12 are two orders, where the column's collation is case-blind.
        $found = $db->get_results( sprintf(
            "SELECT id FROM %s WHERE site_id = ? AND %s AND event_type = 'purchase' LIMIT 1",
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName(),
            sprintf( OWA_SQL_EQUALS_CASE_SENSITIVE, 'transaction_id', '?' ) ), [ $site, $transaction ] );

        return (bool) $found;
    }
}
