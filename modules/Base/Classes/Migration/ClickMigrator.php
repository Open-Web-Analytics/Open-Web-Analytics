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
 * recorded it, so it is worked out here the way the tracker does: the
 * target's host against the page's.
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
        $event->set( 'is_outbound', $target && $host && strcasecmp( $target, $host ) !== 0 ? 1 : 0 );

        return array( $event );
    }
}

?>
