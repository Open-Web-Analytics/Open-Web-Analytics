<?php

namespace OWA\Module\Base\Update;

/**
 * Drop six owa_visitor columns that nothing writes and nothing reads.
 *
 * The table is one of the few that survives into v2, so what it carries is
 * worth being deliberate about. Today most of it is unreachable: of the columns
 * that predate this release only `id` and `user_email` are readable by any
 * registered dimension, and no metric is registered against base.visitor at
 * all. The rest is written and never looked at again.
 *
 * These six are the subset that can go without consequence:
 *
 *   last_session_id, last_session_year, last_session_month, last_session_day,
 *   last_session_dayofyear
 *       Fully dead. Searching the whole repository for those names returns ten
 *       lines, all of them this entity's own property declarations. No handler
 *       writes them, no report reads them, no update mentions them. On a live
 *       install 190,428 of 190,594 rows hold zero or empty and 79 hold NULL --
 *       three spellings of nothing across five columns. Something wrote them
 *       long ago; nothing has since.
 *
 *   first_session_dayofyear
 *       Write-only. VisitorHandlers set it at visitor creation and nothing ever
 *       read it back. Derivable from first_session_timestamp, which stays.
 *
 * WHAT IS DELIBERATELY LEFT, AND WHY IT IS NOT AN OVERSIGHT
 * The obvious further candidates are pinned by historical updates, which name
 * the columns directly:
 *
 *   first_session_year / _month / _day / _yyyymmdd / _timestamp   Update005
 *   num_prior_sessions                                Update005, 007, 028
 *   user_name                                              Update007, 028
 *
 * Entity::addColumn() builds its ALTER from the entity's declared property, so
 * removing a declaration makes the corresponding addColumn() in an old update
 * return false -- and those updates return false in turn, which halts the whole
 * chain. Update005 additionally computes first_session_yyyymmdd with a SQL
 * expression over first_session_year/_month/_day, which simply fails if those
 * columns were never created.
 *
 * So a column can be dropped only once no update names it. Freeing the rest
 * means making those updates tolerant of their own columns being absent, which
 * is a change to the upgrade path and belongs in its own release rather than
 * riding along with a cleanup.
 */
class Update033 extends \OWA\Core\Update {

    var $schema_version = 33;

    var $is_cli_mode_required = false;

    /**
     * The retired columns and the type each one had, carried by this update.
     *
     * NOT read from the entity, which is the point. An entity describes the
     * CURRENT schema; an update moves between two of them, and the older of the
     * two is exactly what the entity has stopped describing. An update that
     * drops a column and leaves its rollback to look the definition up in the
     * entity can never reverse itself -- there is nothing left to look up.
     *
     * So the definitions live here, pinned to the schema version this update
     * leaves behind. That is what makes down() an exact inverse rather than a
     * note explaining why there isn't one.
     *
     * Built in a method rather than a class constant because the OWA_DTD_*
     * values are runtime defines: a class constant referencing them is
     * evaluated when the class is loaded, which is not guaranteed to be after
     * the environment is set up.
     *
     * @return array column => data type
     */
    private function retiredColumns() {

        return array(
            'last_session_id'         => OWA_DTD_BIGINT,
            'last_session_year'       => OWA_DTD_INT,
            'last_session_month'      => OWA_DTD_VARCHAR255,
            'last_session_day'        => OWA_DTD_INT,
            'last_session_dayofyear'  => OWA_DTD_INT,
            'first_session_dayofyear' => OWA_DTD_INT,
        );
    }

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor' );

        foreach ( array_keys( $this->retiredColumns() ) as $column ) {

            if ( ! $this->dropColumnIfPresent( $entity, $column ) ) {

                $this->e->notice( sprintf( 'Dropping owa_visitor.%s failed', $column ) );

                return false;
            }
        }

        return true;
    }

    /**
     * Put the six columns back, exactly as they were.
     *
     * Reversible because the definitions are carried above rather than read
     * from the entity. A rollback is a release being reverted -- the code goes
     * back too, and the older entity declares these columns again -- so the
     * schema has to be able to follow it. Leaving them dropped would strand
     * that older code against a table missing columns it declares.
     *
     * The definition is built through DbColumn so the type mapping stays in the
     * one place that owns it, rather than this update hand-writing SQL that
     * would then have to track the dialect layer.
     *
     * Idempotent: a column already present is left alone, so a rollback that
     * stopped part way can be run again.
     */
    function down() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor' );
        $table  = $entity->getTableName();

        foreach ( $this->retiredColumns() as $column => $type ) {

            $existing = (array) $db->get_results( sprintf(
                "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

            if ( $existing ) {

                continue;
            }

            $definition = new \OWA\Module\Base\Classes\DbColumn( $column, $type );

            if ( $db->addColumn( $table, $column, $definition->getDefinition() ) === false ) {

                $this->e->notice( sprintf( 'Restoring owa_visitor.%s failed', $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
