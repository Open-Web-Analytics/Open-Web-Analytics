<?php

namespace OWA\Module\Base\Update;

/**
 * A reader can star a custom report or visualization.
 *
 * A table rather than a column, because a favourite belongs to the READER and
 * the report belongs to its author: a column on owa_custom_report would make
 * one person's star everybody's. Same shape as notification_state, which is
 * per-user state about a shared row for the same reason.
 *
 * Nothing to backfill -- nobody has starred anything yet, and an empty table is
 * exactly "no favourites", which is the state every reader is already in.
 */
class Update030 extends \OWA\Core\Update {

    var $schema_version = 30;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );

        /*
         * createTable() issues CREATE TABLE IF NOT EXISTS, so a second run is
         * a no-op rather than a failure -- unlike addColumn(), which is why
         * columns need the addColumnIfMissing() helper and tables do not.
         */
        if ( ! $entity->createTable() ) {

            $this->e->notice( 'Creating owa_custom_report_favorite failed' );

            return false;
        }

        return true;
    }

    /**
     * Drop the table.
     *
     * EVERY STAR IS LOST by doing this. Nothing else is: a favourite decides
     * where a report sits in one person's list and nothing about the report,
     * so rolling back leaves every report exactly as it was and every list in
     * its default order.
     *
     * Idempotent -- dropTable() is IF EXISTS.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );

        if ( ! $entity->dropTable() ) {

            $this->e->notice( 'Dropping owa_custom_report_favorite failed' );

            return false;
        }

        return true;
    }
}
