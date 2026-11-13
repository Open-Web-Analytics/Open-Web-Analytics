<?php

namespace OWA\Module\Base\Update;

/**
 * Nothing, now: this gave raw and every cube a remote_host column, and
 * Update074 takes it away again.
 *
 * remote_host was the visitor's reverse-DNS host. No dimension read it, and
 * Update074 removed it from raw and the cubes. Adding it here was a full rebuild
 * of the raw table (the column add rebuilds it in place) on an installation
 * upgrading from 1.x, where raw by then holds every migrated row, only for 074
 * to remove it.
 *
 * An installation that already ran this has the column until 074, which
 * removes it if it is there. Rolled back past 074, the column comes back
 * empty and stays: an unread nullable column, which is cheaper than the rebuild
 * this skips.
 */
class Update051 extends \OWA\Core\Update {

    var $schema_version = 51;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return true;
    }

    function down() {

        return true;
    }
}

?>
