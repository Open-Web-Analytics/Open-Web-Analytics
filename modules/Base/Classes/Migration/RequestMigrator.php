<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's page views (owa_request) into page_view events.
 *
 * The entry request of a session carries the new-session flag, and the
 * new-visitor flag where v1 recorded a new visitor, so ingest's own
 * materialisers raise session_start and first_visit exactly as for a live
 * beacon (PLAN.html 2.21).
 */
class RequestMigrator extends FactMigrator {

    const SOURCE = 'request';

    protected function events( array $r, array $refs ) {

        $is_entry = ! empty( $r['is_entry_page'] );

        $event = $this->baseEvent( $r, $refs, 'page_view', array(
            'is_new_session_start'   => $is_entry,
            'is_new_visitor_created' => $is_entry && ! empty( $r['is_new_visitor'] ),
        ) );

        $events = \OWA\Module\Base\Classes\MaterializedEvents::sessionStart( array( $event ) );

        return \OWA\Module\Base\Classes\MaterializedEvents::firstVisit( $events );
    }
}

?>
