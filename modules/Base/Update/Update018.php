<?php

namespace OWA\Module\Base\Update;

/**
 * Adds owa_notification and owa_notification_state.
 *
 * owa_notification holds one row per thing worth telling operators about --
 * today a GitHub release, previously fetched synchronously on every dashboard
 * render and never stored at all.
 *
 * owa_notification_state holds one row per (notification, user), carrying when
 * they READ it and when they DISMISSED it. Two independent facts: reading
 * clears the badge and unbolds the headline, dismissing removes it from the
 * list.
 *
 * Two small tables, no locks worth worrying about, so this does not require CLI
 * mode -- unlike the updates that rewrite fact tables.
 *
 * The DDL is CREATE TABLE IF NOT EXISTS, so up() is idempotent and converges
 * with a fresh install, which creates both through Core\Module::install()'s
 * entity loop rather than through here. down() drops them, and dropping a table
 * that is already gone is not an error either -- so a rollback re-run is a
 * no-op rather than a failure.
 *
 * It creates the tables and NOT their contents: the fetch job is what writes
 * notifications, and it is idempotent on (source, source_key).
 */
class Update018 extends \OWA\Core\Update {

    var $schema_version = 18;

    var $is_cli_mode_required = false;

    /** The tables this update owns, in creation order. */
    const TABLES = array( 'notification', 'notification_state' );

    function up( $force = false ) {

        foreach ( self::TABLES as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.' . $name );

            if ( $entity->createTable() === false ) {

                $this->e->notice( sprintf( 'Create table %s failed', $name ) );

                return false;
            }
        }

        return true;
    }

    function down() {

        // Reverse order: nothing enforces the reference in SQL, but dropping
        // the dependent table first is what the order would have to be if
        // anything ever did.
        foreach ( array_reverse( self::TABLES ) as $name ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.' . $name )->dropTable();
        }

        return true;
    }
}
