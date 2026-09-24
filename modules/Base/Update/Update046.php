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

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( $this->removed() as $column ) {

            if ( $this->addColumnIfMissing( $raw, $column ) === false
              || $this->addCubeColumn( $column ) === false ) {

                $this->e->notice( sprintf( 'Restoring %s failed', $column ) );

                return false;
            }
        }

        return $this->dropColumns( $this->added() );
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

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( $columns as $column ) {

            if ( $this->dropCubeColumn( $column ) === false
              || $this->dropColumnIfPresent( $raw, $column ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s failed', $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
