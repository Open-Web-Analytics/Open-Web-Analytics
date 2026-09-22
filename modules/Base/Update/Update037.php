<?php

namespace OWA\Module\Base\Update;

/**
 * Carry prev_event_ts on the beacon instead of deriving it in a build.
 *
 * The column moves from owa_event's derived set into owa_event_raw, so it needs
 * adding to raw and nothing doing to owa_event -- which already has a column of
 * that name from Update 035, now inherited from raw rather than declared.
 *
 * Why it moved: it was the only reason a build ran a second window function,
 * partitioned by visitor where the other partitions by session, so the two
 * could not share a sort. Measured at a million rows, that one window cost 128
 * of 195 seconds.
 *
 * The tracker already sends last_req and already maintains it for its own
 * session decision, so 1.5.3's rule covers it: an anchor the client keeps is
 * carried. The objection that put it in a build was clock provenance, and
 * clock_offset_usec answers it -- a client-clock last_req corrects to server
 * time by adding the offset.
 *
 * Instant, and free: raw is never a swap target, so it takes a column the way
 * any ordinary table does.
 *
 * Rows written before this hold NULL, which is what a build wrote for a
 * visitor's first event in the window anyway.
 */
class Update037 extends \OWA\Core\Update {

    var $schema_version = 37;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->addColumnIfMissing( $entity, 'prev_event_ts' ) === false ) {

            $this->e->notice( sprintf(
                'Adding %s.prev_event_ts failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }

    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $entity, 'prev_event_ts' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.prev_event_ts failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
