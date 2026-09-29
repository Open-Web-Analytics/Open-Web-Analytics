<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * created_at arrives on raw, and raw only.
 *
 * When a row reached raw, in microseconds, stamped by EventRaw::create() and
 * by the migrator. A routine cube build compares it with a partition's
 * built_at and skips a partition nothing has reached since it settled
 * (Cube\Builder::isCurrent()), which is what lets rebuild-cube run every few
 * minutes without rewriting unchanged days.
 *
 * NOT ON THE CUBES. It is ingest provenance that no report reads, and adding a
 * column to a cube is a full rebuild of every cube (PLAN 2.7.3). The cube
 * entity drops it, so a fresh install's cubes are created without it too.
 *
 * AN UPDATE, NOT A SCHEMA EDIT, because owa_event_raw is on master (Update034).
 * A fresh install creates raw from the entity with the column already there,
 * and this is then a no-op. NOT CLI-ONLY: raw takes a column instantly.
 *
 * Existing rows are left NULL. A partition whose newest created_at is NULL is
 * never skipped, so nothing old can be wrongly left unbuilt.
 */
class Update063 extends \OWA\Core\Update {

    var $schema_version = 63;

    var $is_cli_mode_required = false;

    const COLUMN = 'created_at';

    /*
     * Spelled out rather than read from the entity: an update is permanent and
     * a shape is not, and a later update removing the column would otherwise
     * break every upgrade path that passes through this one (see Update053).
     */
    const DEFINITION = 'BIGINT NULL';

    function up( $force = false ) {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->addColumnIfMissing( $raw, self::COLUMN, self::DEFINITION ) === false ) {

            $this->e->notice( sprintf( 'Adding %s.%s failed', $raw->getTableName(), self::COLUMN ) );

            return false;
        }

        return true;
    }

    /** The exact inverse: the column goes, with what it held. */
    function down() {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, self::COLUMN ) === false ) {

            $this->e->notice( sprintf( 'Dropping %s.%s failed', $raw->getTableName(), self::COLUMN ) );

            return false;
        }

        return true;
    }
}

?>
