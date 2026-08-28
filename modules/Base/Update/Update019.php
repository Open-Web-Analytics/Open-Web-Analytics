<?php

namespace OWA\Module\Base\Update;

/**
 * Adds owa_custom_report.
 *
 * One row per user-authored report definition. The definition column holds the
 * same JSON a shipped report holds, so a custom report renders through the same
 * Core\ConfiguredReport as every other configured report -- see
 * Entity\CustomReport for why the format can safely accept user input.
 *
 * One small table with no data migration, so this does not require CLI mode --
 * unlike the updates that rewrite fact tables.
 *
 * createTable() is CREATE TABLE IF NOT EXISTS, so up() is idempotent and
 * converges with a fresh install, which creates the table through
 * Core\Module::install()'s entity loop rather than through here. down() drops
 * it, and dropping a table that is already gone is not an error, so a rollback
 * re-run is a no-op rather than a failure.
 */
class Update019 extends \OWA\Core\Update {

    var $schema_version = 19;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        if ( $entity->createTable() === false ) {

            $this->e->notice( 'Create table custom_report failed' );

            return false;
        }

        return true;
    }

    function down() {

        /*
         * Dropping this table destroys every custom report on the
         * installation, and there is nowhere else they exist -- a shipped
         * report has a file to fall back on and these do not.
         *
         * Left as a real drop rather than made safe, because a rollback that
         * silently kept the table would leave the schema version and the
         * schema disagreeing, which is worse. The rows are the operator's to
         * back up before rolling back a schema version.
         */
        \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' )->dropTable();

        return true;
    }
}
