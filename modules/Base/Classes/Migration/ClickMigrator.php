<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's clicks (owa_click) into click events.
 *
 * Whether a click left the site is decided on the client now; v1 never
 * recorded it, so it is worked out here by the tracker's rule
 * (TrackingEventHelpers::isOutboundHost()): the site is its cookie domain and
 * every subdomain of it, not just the page's own host. Comparing hosts exactly
 * made a link from www.example.com to shop.example.com outbound in migrated
 * history and internal in everything tracked since.
 */
class ClickMigrator extends FactMigrator {

    const SOURCE = 'click';

    protected function events( array $r, array $refs ) {

        $event = $this->baseEvent( $r, $refs, 'click', array(
            'click_x'         => $r['click_x'] ?? null,
            'click_y'         => $r['click_y'] ?? null,
            'page_width'      => $r['page_width'] ?? null,
            'page_height'     => $r['page_height'] ?? null,
            'dom_element_id'  => $r['dom_element_id'] ?? null,
            'dom_element_tag' => $r['dom_element_tag'] ?? null,
            'target_url'      => $r['target_url'] ?? null,
        ) );

        $target = \OWA\Module\Base\Classes\TrackingEventHelpers::deriveTargetHost( null, $event );
        $host   = (string) $event->get( 'host' );

        $event->set( 'target_host', $target );
        $event->set( 'is_outbound', \OWA\Module\Base\Classes\TrackingEventHelpers::isOutboundHost(
            $target, $host, $this->cookieDomain( (string) $event->getSiteId() ) ) ? 1 : 0 );

        return array( $event );
    }

    /** @var array site id => its tracker_cookie_domain, read once per site */
    private $cookie_domains = array();

    /**
     * The cookie domain the site's tracker is given -- tracker_cookie_domain as
     * TrackerBundle resolves it for the Profile, empty for the default.
     *
     * @param  string $site_id
     * @return string
     */
    protected function cookieDomain( $site_id ) {

        if ( ! array_key_exists( $site_id, $this->cookie_domains ) ) {

            $this->cookie_domains[ $site_id ] =
                \OWA\Module\Base\Classes\TrackingEventHelpers::cookieDomain( $site_id );
        }

        return $this->cookie_domains[ $site_id ];
    }
}

?>
