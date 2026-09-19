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

    const COLUMNS = array(
        'last_session_id',
        'last_session_year',
        'last_session_month',
        'last_session_day',
        'last_session_dayofyear',
        'first_session_dayofyear',
    );

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor' );

        foreach ( self::COLUMNS as $column ) {

            if ( ! $this->dropColumnIfPresent( $entity, $column ) ) {

                $this->e->notice( sprintf( 'Dropping owa_visitor.%s failed', $column ) );

                return false;
            }
        }

        return true;
    }

    /**
     * Deliberately not reversible, and for the same mechanism up() documents.
     *
     * Restoring a column means an ALTER built from the entity's declared
     * property, and this change removes those declarations -- so there is no
     * definition left to rebuild them from. Re-adding them by hand-written DDL
     * would put back columns the entity does not know about, which is a worse
     * state than either side of this update: they would exist in the table,
     * be invisible to every entity operation, and reappear in no fresh install.
     *
     * Nothing is lost by that. The five last_session_* columns held zero or
     * empty in 190,428 of 190,594 rows on the install this was measured
     * against, with 79 NULL and the rest ancient; no code has written them in
     * years. first_session_dayofyear is recomputable from
     * first_session_timestamp, which this update does not touch.
     *
     * So the inverse is impossible to express honestly and would restore
     * nothing if it were. Same position as Update031, and for a related
     * reason: an inverse that invents state is worse than no inverse.
     */
    function down() {

        return true;
    }
}

?>
