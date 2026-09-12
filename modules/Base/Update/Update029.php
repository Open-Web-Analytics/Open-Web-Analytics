<?php

namespace OWA\Module\Base\Update;

/**
 * A custom report can be listed to everybody, or only to its author.
 *
 * WHAT THIS IS NOT: an access control. A custom report opened by its URL
 * already renders for anyone with view_reports -- deliberately, because that is
 * what makes the link shareable, and it is safe because a custom report can
 * show nothing its reader could not already query for themselves.
 *
 * What ownership governed was the ROSTER: CustomReports::roster() filtered on
 * user_id unless the viewer held edit_users, so a report could be perfectly
 * visible to a colleague you sent the link to while being absent from their
 * list. This column lets an author put it on everyone's list.
 *
 * Nothing is backfilled. Every existing row stays private, which is what a NULL
 * is_shared already means -- see CustomReport::isShared(), where falsy reads as
 * private. The roster matches shared rows as is_shared = 1 for the same reason
 * the report_type filter matches "not visualization": a comparison the other way
 * would exclude every row written before this.
 */
class Update029 extends \OWA\Core\Update {

    var $schema_version = 29;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        /*
         * Skipped when it is already there, rather than treated as a failure --
         * see Update::addColumnIfMissing() for why that is the ordinary case
         * and not a re-run guard.
         */
        if ( ! $this->addColumnIfMissing( $entity, 'is_shared' ) ) {

            $this->e->notice( 'Adding is_shared to owa_custom_report failed' );

            return false;
        }

        /*
         * The roster reads it on every listing, so it is indexed. addColumn()
         * has never created an index for an indexed column, which is why this
         * is a separate step rather than something the column definition gets
         * for free.
         */
        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->c->get( 'base', 'ns' ) . 'custom_report';

        if ( ! $db->indexExists( $table, 'is_shared' ) ) {

            if ( ! $db->addIndex( $table, 'is_shared' ) ) {

                $this->e->notice( "Indexing is_shared on $table failed" );

                return false;
            }
        }

        return true;
    }

    /**
     * Take the column and its index back off owa_custom_report.
     *
     * EVERY REPORT BECOMES PRIVATE AGAIN by doing this -- a row that said it
     * was shared loses the only thing saying so, and drops out of everyone's
     * roster but its author's. Stated rather than guarded against: a rollback
     * to a schema with no such column cannot keep the flag, and the alternative
     * is refusing to roll back at all.
     *
     * Nothing anyone could reach stops being reachable. The links still work,
     * because they never depended on this.
     *
     * Idempotent. The index is dropped by the name addIndex() gives it, and
     * only when that name is present, so an index added by hand under another
     * name is left alone.
     */
    function down() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $table  = $this->c->get( 'base', 'ns' ) . 'custom_report';

        foreach ( $db->listIndexes() as $row ) {

            if ( $row['t'] === $table && $row['i'] === 'idx_is_shared' ) {

                if ( ! $db->dropIndex( $table, 'idx_is_shared' ) ) {

                    $this->e->notice( "Dropping index idx_is_shared from $table failed" );

                    return false;
                }
            }
        }

        if ( ! $this->dropColumnIfPresent( $entity, 'is_shared' ) ) {

            $this->e->notice( 'Dropping is_shared from owa_custom_report failed' );

            return false;
        }

        return true;
    }
}
