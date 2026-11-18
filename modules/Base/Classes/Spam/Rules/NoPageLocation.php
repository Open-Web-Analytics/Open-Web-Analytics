<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

/**
 * A beacon of an event sent from a page carries no page location.
 *
 * The tracker puts the page's URL (p_l) on every event it sends.
 *
 * WHICH EVENTS. The registry declares page_location for every event (`*`), so
 * it cannot say which events come from a page. This fires for the first-class
 * events (config/tracking_events.php), which the tracker sends from a page, and
 * not for the materialized ones, which no beacon sends. A custom event name is
 * left alone: it may be sent by a server, where there is no page.
 */
class NoPageLocation extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'NO_PAGE_LOCATION';
    const SET    = 'page_origin';
    const POINTS = 5.0;

    public function test( $event, array $headers ) {

        if ( trim( \OWA\Module\Base\Classes\Spam\Received::pageLocation( $event ) ) !== '' ) {

            return false;
        }

        return self::carriesAPage( (string) $event->getEventType() );
    }

    /**
     * Whether an event of this name is sent from a page with its location.
     *
     * @param  string $event_type
     * @return bool
     */
    public static function carriesAPage( $event_type ) {

        $helpers = \OWA\Module\Base\Classes\TrackingEventHelpers::class;

        return in_array( $event_type, $helpers::eventNames(), true )
            && ! $helpers::isMaterialized( $event_type )
            && in_array( 'page_location', $helpers::propertiesForEvent( $event_type ), true );
    }
}
