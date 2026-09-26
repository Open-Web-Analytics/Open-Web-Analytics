<?php

namespace OWA\Module\Base\Update;

/**
 * Add new_vs_returning to every cube.
 *
 * A cube column, not a raw one: it is a READING of prior_sessions, and a
 * reading belongs where a rebuild can re-apply it. The observation is already
 * on the raw row and stays there -- this update adds nothing to owa_event_raw
 * and takes nothing away.
 *
 * WHY THE LABEL AND NOT A FLAG is in Classes\Cube\NewVsReturningStep. The short
 * version: the reporting engine GROUPs BY a column and the dimension registry
 * has no slot for value labels, so a stored code has no way to become two named
 * buckets.
 *
 * EXISTING ROWS READ AS `(not set)` UNTIL A REBUILD. Measured on MySQL 8 under
 * STRICT_ALL_TABLES, ADD COLUMN ... NOT NULL with no default backfills '' on a
 * populated table rather than refusing -- sql_mode governs what DML may store,
 * not what an ALTER writes. So the column is present and empty until
 * cmd=cube-rebuild runs, exactly as Update038's referer_query stayed NULL until
 * one did. Nothing is lost by that: every row of a cube came from a build.
 *
 * NOT CLI-ONLY. The trait pins ALGORITHM=INPLACE, which is a full rebuild of
 * each cube -- online, and the same one a release adding any cube column pays.
 */
class Update044 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 44;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return $this->addCubeColumn( 'new_vs_returning' );
    }

    /**
     * Drop it.
     *
     * The exact inverse, and no more. Nothing else moved: the column was
     * derived from prior_sessions, which this never touched, so a rollback
     * loses a reading that re-applying Update044 and rebuilding brings straight
     * back.
     *
     * The reports rolling back with it name `isRepeatVisitor` again, and that
     * dimension is registered by the module rather than by a schema version --
     * so a checkout old enough to need this down() is old enough to have it.
     */
    function down() {

        return $this->dropCubeColumn( 'new_vs_returning' );
    }
}

?>
