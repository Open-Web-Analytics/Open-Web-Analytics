<?php

namespace OWA\Module\Base\Update;

/**
 * Store the session anchors; drop the two columns nothing read.
 *
 * THE CONFUSION THIS ENDS. `sts` (session start) and `psts` (prior session
 * start) were on the beacon and reached NO column, while `prev_event_ts` was
 * written to a column nothing read. The value that was stored was not the one
 * anybody wanted.
 *
 * prev_event_ts was `last_req` restated -- seconds to microseconds, client
 * clock to server clock -- and it meant two different things depending on where
 * the row sat: on a session's first event the PREVIOUS session's end, on every
 * other event the previous event of this one. One column, two facts.
 *
 * psts answers the question that was actually wanted. The tracker's own comment
 * says so, and says why the seconds interval it replaced was wrong: "a
 * continuous seconds value gives one bucket per distinct second, so it was a
 * metric wearing a dimension's clothes. Days bucket; seconds do not."
 *
 * clock_offset_usec goes with prev_event_ts because correcting it was its only
 * job. Ordering is settled by event_seq now (Update045), which is a counter and
 * needs no clock at all -- so the offset's other implied use went too.
 *
 * NOTHING TO BACKFILL. Both new columns are anchors the client holds; a stored
 * row cannot be made to yield one it never carried. Rows written before this
 * keep NULL, which is the honest reading.
 *
 * COMPAT WITH STALE TRACKERS: both are already on the wire and have been, so a
 * tracker cached from before this release fills them from its first beacon. The
 * columns are nullable for the ones that predate the fields themselves.
 *
 * NOT CLI-ONLY. Adds two columns and drops two, the way any release does.
 */
class Update046 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 46;

    var $is_cli_mode_required = false;

    /** The anchors this adds. @return string[] */
    private function added() {

        return array( 'session_start_ts', 'prior_session_start_ts' );
    }

    /** The columns this removes. @return string[] */
    private function removed() {

        return array( 'prev_event_ts', 'clock_offset_usec' );
    }

    function up( $force = false ) {

        foreach ( $this->added() as $column ) {

            if ( $this->addRawColumn( $column ) === false ) {

                return false;
            }
        }

        return $this->dropColumns( $this->removed() );
    }

    /**
     * Put the two back, empty.
     *
     * The exact inverse, and no more. Their contents cannot come back -- the
     * readings were derived from a beacon value at ingest, and nothing stored
     * can reproduce them -- so a rollback lands on the schema it left, not on
     * the data. Reapplying and re-collecting is what fills them, which is the
     * same trade Update042 records for a registered dimension's column.
     */
    function down() {

        /*
         * THE TYPES ARE SPELLED OUT HERE, and they have to be.
         *
         * addColumnIfMissing() and the CubeColumn trait both ask the ENTITY for
         * a column's definition -- and the entity no longer declares these two,
         * because removing them is what up() did. Asking it yields "Undefined
         * array key" and a down() that cannot restore what it removed.
         *
         * That is general to any update that DROPS a column: its down() is the
         * one direction the entity cannot describe, so the definition has to
         * live in the update. Update039 spells its types out for the same
         * reason.
         */
        foreach ( array( 'prev_event_ts' => OWA_DTD_BIGINT,
                         'clock_offset_usec' => OWA_DTD_BIGINT ) as $column => $type ) {

            if ( ! $this->restoreColumn( $column, $type ) ) {

                $this->e->notice( sprintf( 'Restoring %s failed', $column ) );

                return false;
            }
        }

        return $this->dropColumns( $this->added() );
    }

    /**
     * Add a column back to raw and to every cube, by an EXPLICIT definition.
     *
     * Empty, and that is the contract: the values were derived from a beacon
     * at ingest and nothing stored can reproduce them. A rollback lands on the
     * schema it left, not on the data.
     *
     * @param string $column
     * @param string $type   an OWA_DTD_* value
     * @return bool
     */
    private function restoreColumn( $column, $type ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        foreach ( array_merge( array( $raw ),
                  \OWA\Module\Base\Classes\Cube\Cubes::allTables() ) as $table ) {

            $existing = (array) $db->get_results( sprintf(
                "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

            if ( $existing ) {

                continue;
            }

            /*
             * A cube is rebuilt rather than taking the column instantly: an
             * instant column leaves row-format metadata that makes EXCHANGE
             * PARTITION refuse the swap on the NEXT build. Raw is never a swap
             * target, so an ordinary add is right for it -- and harmless if the
             * rebuilding form is used instead, which is why one loop covers
             * both.
             */
            if ( ! $db->addColumnRebuilding( $table, $column, $type )
              && ! $db->query( sprintf( OWA_SQL_ADD_COLUMN, $table, $column, $type ) ) ) {

                return false;
            }
        }

        return $this->clearCubeInstantColumns();
    }

    /**
     * Drop a column from the cube and from raw, in that order.
     *
     * The cube first: it inherits raw's columns, so taking it off raw while the
     * cube still has it leaves the two disagreeing, and the next build fails on
     * a column the SELECT no longer produces.
     *
     * @param string[] $columns
     * @return bool
     */
    private function dropColumns( array $columns ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        foreach ( $columns as $column ) {

            /*
             * BY NAME, not through the entity -- the same reason restoreColumn()
             * spells its types out.
             *
             * dropColumnIfPresent() and the CubeColumn trait both ask the entity
             * for the column, and up() has just removed its declaration. Before
             * Entity::getColumn() refused an undeclared name this returned null
             * with a warning and the DDL happened to work anyway, so it passed
             * on a database where the column was already gone and failed on one
             * where it was not -- which is to say, on every real upgrade and no
             * fresh install. The schema upgrade cycle is the only job that runs
             * it.
             */
            foreach ( array_merge( array( $raw ),
                      \OWA\Module\Base\Classes\Cube\Cubes::allTables() ) as $table ) {

                $existing = (array) $db->get_results( sprintf(
                    "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

                if ( ! $existing ) {

                    continue;
                }

                if ( ! $db->query( sprintf( OWA_SQL_DROP_COLUMN, $table, $column ) ) ) {

                    $this->e->notice( sprintf( 'Dropping %s.%s failed', $table, $column ) );

                    return false;
                }
            }
        }

        return $this->clearCubeInstantColumns();
    }
}

?>
