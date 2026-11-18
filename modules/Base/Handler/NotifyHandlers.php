<?php
namespace OWA\Module\Base\Handler;

/**
 * Click Event Handler
 */
class NotifyHandlers extends \OWA\Core\Observer {

    /**
     * Notify Handler
     *
     * @access     public
     * @param     object $event
     * @return int an OWA_EHS_ handler status
     */
    public function notify( $event ) {
        
        if ( $event->getSiteId() ) {

            /*
             * Was tested in Module::_registerEventHandlers, which runs before
             * any event exists and so could only ever read the install-wide
             * value. Both settings are per-Profile now, so the decision has
             * moved to the first point that knows which Profile this is.
             */
            $siteId = $event->getSiteId();

            $announce = \OWA\Core\CoreAPI::getSetting(
                'base', 'announce_visitors', 'profile', $siteId );

            $address = \OWA\Core\CoreAPI::getSetting(
                'base', 'notice_email', 'profile', $siteId );

            if ( ! $announce || ! $address ) {

                \OWA\Core\CoreAPI::debug(
                    'New session notify skipped: announcements are off for site_id ' . $siteId );

                return OWA_EHS_EVENT_HANDLED;
            }

            $s = \OWA\Module\Base\Entity\Site::forSiteId( $event->getSiteId() );

            if ( $s->wasPersisted() ) {

                /*
                 * This runs inside ingest (EventRawHandlers::announce()), after
                 * the beacon's rows are committed: whatever the mail does, the
                 * beacon is handled. The mailer logs its own failures.
                 */
                try {

                    \OWA\Core\CoreAPI::performAction( 'base.notifyNewSession', [ 'site' => $s, 'event' => $event ] );

                } catch ( \Throwable $t ) {

                    \OWA\Core\CoreAPI::error( sprintf( 'New session announcement for site_id %s not sent: %s',
                        $siteId, $t->getMessage() ) );
                }

                return OWA_EHS_EVENT_HANDLED;

            } else {

                // No site entity exists for this event's site_id, so there is
                // no one to send the new-session announcement to. This is a
                // permanent condition (an unknown/unregistered site never
                // becomes registered by retrying), so treat it as handled --
                // returning FAILED would report a failure no retry can fix.
                \OWA\Core\CoreAPI::debug( 'New session notify handled with no action: no persisted site found for site_id: ' . $event->getSiteId() );

                return OWA_EHS_EVENT_HANDLED;
            }
            
        } else {
            
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}
