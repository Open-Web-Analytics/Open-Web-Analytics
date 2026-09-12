<?php

namespace OWA\Module\Base\Update;

/**
 * owa_feed_request becomes a real fact table.
 *
 * It always was one -- a feed request is an event, filed under a yyyymmdd,
 * referencing dimension rows -- but its entity extended Entity rather than
 * FactTable. Three things select fact tables by class:
 *
 *   - the partition commands (PartitionsCli::factTables())
 *   - the dimension-id conversion (RederiveDimensionIdsCli)
 *   - the visitor_id index update (Update014)
 *
 * so this table was left out of all three. It was never partitioned, which
 * means a retention window never reached it and it grew without bound on any
 * installation that publishes feeds; and its document_id, ua_id, host_id and
 * os_id were never repointed when dimension ids were re-derived to 63 bits.
 *
 * Making the entity a FactTable fixes all three at once, because every one of
 * those selections is dynamic. What it cannot do by itself is add the columns
 * the parent declares: Entity::create() writes EVERY declared column, so the
 * first feed request logged against an unmigrated table would fail on an
 * unknown column. That is what this update is for.
 *
 * Partitioning is deliberately NOT done here. Converting a table rewrites it
 * with writes blocked, which is an administrator's maintenance window and not
 * something an upgrade should start -- the same reasoning that kept the other
 * fact tables out of a schema update. `cli.php cmd=partition-init` now sees
 * this table and will partition it, widening its primary key to
 * (id, yyyymmdd) on the way, exactly as it does for the others.
 */
class Update028 extends \OWA\Core\Update {

    var $schema_version = 28;

    var $is_cli_mode_required = false;

    /**
     * The columns FactTable declares that owa_feed_request did not have.
     *
     * Listed rather than derived from the entity. A derived list would change
     * silently with the parent class, and an update has to keep doing the same
     * thing to the same tables forever -- including the columns it drops in
     * down(), which must be exactly the ones it added and no others.
     *
     * Not included: the columns the two classes share. Several of those
     * disagree on type -- ua_id and os_id are VARCHAR255 here and BIGINT in
     * FactTable -- and the entity keeps its own. Changing them is a data
     * migration, not this.
     */
    private function columns() {

        return array(
            'ad_id',
            'campaign_id',
            'days_since_first_session',
            'days_since_prior_session',
            'is_new_visitor',
            'is_repeat_visitor',
            'language',
            'location_id',
            'medium',
            'num_prior_sessions',
            'referer_id',
            'referring_search_term_id',
            'source_id',
            'user_name',
            'cv1_name', 'cv1_value',
            'cv2_name', 'cv2_value',
            'cv3_name', 'cv3_value',
            'cv4_name', 'cv4_value',
            'cv5_name', 'cv5_value',
        );
    }

    /**
     * The columns FactTable indexes that owa_feed_request was not indexing.
     *
     * Kept beside columns() and for the same reason: down() has to undo
     * exactly what up() did, which means both have to read one list.
     */
    private function indexes() {

        return array( 'visitor_id', 'session_id', 'site_id' );
    }

    function up( $force = false ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.feed_request' );

        foreach ( $this->columns() as $column ) {

            /*
             * Skipped when it is already there, rather than treated as a
             * failure -- see Update::addColumnIfMissing().
             */
            if ( ! $this->addColumnIfMissing( $entity, $column ) ) {

                $this->e->notice( "Adding $column to owa_feed_request failed" );

                return false;
            }
        }

        /*
         * The indexes FactTable declares that this table never got.
         *
         * visitor_id is what Update014 would have added had this been a fact
         * table when it ran -- done here rather than asking anyone to re-apply
         * 014 with --force, since an installation already past 14 never
         * revisits it.
         *
         * session_id and site_id were lost a different way: the entity used to
         * re-declare them identically to the parent EXCEPT for setIndex(), so
         * the override dropped the index. owa_request carries both.
         *
         * addIndex() already returns early when the index is there, so this is
         * re-runnable; the explicit check is only so the notice is not printed
         * on a second pass.
         */
        $table = $this->c->get( 'base', 'ns' ) . 'feed_request';

        foreach ( $this->indexes() as $column ) {

            if ( $db->indexExists( $table, $column ) ) {

                continue;
            }

            if ( ! $db->addIndex( $table, $column ) ) {

                $this->e->notice( "Indexing $column on $table failed" );

                return false;
            }

            \OWA\Core\CoreAPI::notice( sprintf( 'Indexed %s on %s.', $column, $table ) );
        }

        return true;
    }

    /**
     * Take the inherited columns back off owa_feed_request.
     *
     * Idempotent, and it drops only the columns up() added -- the table's own
     * 28 columns are untouched, so a rollback leaves feed tracking working
     * exactly as it did before.
     *
     * The indexes go too. An earlier draft kept them on the grounds that an
     * extra index is harmless, but that makes down() something other than the
     * inverse of up(): re-applying would then find them present, skip them,
     * and the two runs would not have done the same thing. Dropping them is
     * what lets this be applied and rolled back repeatedly.
     *
     * Partitioning, if it has been applied by then, IS left alone: this update
     * did not create it, and partition-reorganize and partition-drop are the
     * commands that own it. The primary key partitionTable() widened is left
     * alone for the same reason.
     */
    function down() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.feed_request' );
        $table  = $this->c->get( 'base', 'ns' ) . 'feed_request';

        /*
         * dropIndex() takes the INDEX NAME, not the column -- addIndex() names
         * what it creates idx_<column>. Dropping only indexes carrying that
         * name is also what keeps this from removing one that was already
         * present under another name, which up() would have skipped rather
         * than created. Same approach as Update014's down().
         */
        $ours = array();

        foreach ( $db->listIndexes() as $row ) {

            if ( $row['t'] === $table ) {

                $ours[ $row['i'] ] = true;
            }
        }

        foreach ( $this->indexes() as $column ) {

            $index_name = 'idx_' . $column;

            if ( ! isset( $ours[ $index_name ] ) ) {

                continue;
            }

            if ( ! $db->dropIndex( $table, $index_name ) ) {

                $this->e->notice( "Dropping index $index_name from $table failed" );

                return false;
            }
        }

        foreach ( array_reverse( $this->columns() ) as $column ) {

            if ( ! $this->dropColumnIfPresent( $entity, $column ) ) {

                $this->e->notice( "Dropping $column from owa_feed_request failed" );

                return false;
            }
        }

        return true;
    }
}
