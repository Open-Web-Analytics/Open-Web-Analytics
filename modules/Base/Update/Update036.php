<?php

namespace OWA\Module\Base\Update;

/**
 * Add the referring host to owa_event_raw and the visitor store.
 *
 * Parsed at ingest by V2Event::parseUrl(), beside host and target_host. The
 * pass classifies it; it used to parse it too, in SQL, which was both wrong and
 * expensive -- SUBSTRING_INDEX cannot tell a URL from a string that is not one,
 * and the expression was inlined once per classifier branch.
 *
 * owa_event_raw and the visitor store are ALTERed. owa_event is DROPPED AND
 * RECREATED, and the difference is not stylistic.
 *
 * MySQL 8 adds a column instantly by default, which leaves the table carrying
 * instant-column metadata in its row format. A staging table built by CREATE
 * TABLE ... LIKE has none, so the two stop being byte-compatible and
 * EXCHANGE PARTITION refuses them:
 *
 *   1731 Non matching attribute 'INSTANT COLUMN(s)' between partition and table
 *
 * The pass would then fail on every run, having published nothing, until
 * someone rebuilt the table. owa_event holds no record of its own -- every row
 * is derived from owa_event_raw -- so recreating it is cheap and leaves no
 * history behind to diverge from the entity.
 *
 * THE LIMIT OF THAT: it holds only while raw is retained. Once raw is pruned,
 * owa_event is the only copy of those periods and this has to become an ALTER
 * followed by a rebuild of the table (ALTER TABLE ... FORCE) to flatten the
 * instant columns out again.
 *
 * Rows written before this hold NULL in referer_host, which resolves to
 * `direct` in the pass -- the same answer those rows already gave, since
 * nothing could read a host out of them either. The migrator fills it for
 * history.
 */
class Update036 extends \OWA\Core\Update {

    var $schema_version = 36;

    var $is_cli_mode_required = false;

    /**
     * Column by entity.
     *
     * @return array entity name => column name
     */
    private function columns() {

        return array(
            'base.event_raw'           => 'referer_host',
            'base.visitor_acquisition' => 'acq_referer_host',
        );
    }

    function up( $force = false ) {

        foreach ( $this->columns() as $name => $column ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $this->addColumnIfMissing( $entity, $column ) === false ) {

                $this->e->notice( sprintf(
                    'Adding %s.%s failed', $entity->getTableName(), $column ) );

                return false;
            }
        }

        return $this->recreateEventTable();
    }

    /**
     * Drop owa_event and build it again from the entity.
     *
     * See the note above: an ALTER here leaves instant-column metadata that
     * EXCHANGE PARTITION refuses. Recreating also restores the partitioning,
     * which Db::createTable() reads from the entity.
     *
     * @return bool
     */
    private function recreateEventTable() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        if ( $entity->dropTable() === false || $entity->createTable() === false ) {

            $this->e->notice( sprintf(
                'Recreating %s failed', $entity->getTableName() ) );

            return false;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s was recreated and is empty. Run cmd=events-rebuild to refill it.',
            $entity->getTableName() ) );

        return true;
    }

    function down() {

        if ( $this->recreateEventTable() === false ) {

            return false;
        }

        foreach ( array_reverse( $this->columns(), true ) as $name => $column ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $this->dropColumnIfPresent( $entity, $column ) === false ) {

                $this->e->notice( sprintf(
                    'Dropping %s.%s failed', $entity->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
