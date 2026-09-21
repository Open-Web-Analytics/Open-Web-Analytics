<?php

namespace OWA\Module\Base\Update;

/**
 * Add the referring host to owa_event_raw, the visitor store and owa_event.
 *
 * Parsed at ingest by V2Event::parseUrl(), beside host and target_host. The
 * pass classifies it; it used to parse it too, in SQL, which was both wrong and
 * expensive -- SUBSTRING_INDEX cannot tell a URL from a string that is not one,
 * and the expression was inlined once per classifier branch.
 *
 * owa_event_raw and the visitor store take the column instantly, which costs
 * nothing. owa_event cannot, and the difference is the partition swap.
 *
 * MySQL 8 adds a column instantly by default: the rows are not touched, and the
 * table records that they predate it. EXCHANGE PARTITION compares the two
 * tables' physical row format, and a staging table built by CREATE TABLE LIKE
 * is always flat -- it is created empty, so it has no old rows to version and
 * cannot be made to match. The swap is then refused:
 *
 *   1731 Non matching attribute 'INSTANT COLUMN(s)' between partition and table
 *
 * and the pass fails every run, having published nothing.
 *
 * So the column goes in with ALGORITHM=INPLACE, which writes it into every
 * existing row. That is a full table rebuild and it is unavoidable for a table
 * compared byte for byte -- but it is ONLINE, so reads and writes continue
 * throughout, and it costs once per release that adds a column rather than once
 * per run.
 *
 * Measured on 8.4.10, since none of it is guessable from the error:
 *
 *   instant ADD COLUMN            all 14 partitions to version 1, swap refused
 *   ALTER ... REBUILD PARTITION   still version 1, swap still refused
 *   ALTER ... FORCE               back to version 0, swap accepted
 *   ADD COLUMN, ALGORITHM=INPLACE never leaves version 0, swap accepted
 *
 * REBUILD PARTITION is the one that looks like it should work.
 *
 * Rows written before this hold NULL in referer_host, which resolves to
 * `direct` in the pass -- the same answer those rows already gave, since
 * nothing could read a host out of them either. The migrator fills it for
 * history, and a rebuild fills it for anything still in raw.
 */
class Update036 extends \OWA\Core\Update {

    var $schema_version = 36;

    var $is_cli_mode_required = false;

    /** The tables that can take the column instantly. */
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

        return $this->addSwappableColumn( 'referer_host' );
    }

    function down() {

        $tables = $this->columns();

        $tables['base.event'] = 'referer_host';

        foreach ( array_reverse( $tables, true ) as $name => $column ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $this->dropColumnIfPresent( $entity, $column ) === false ) {

                $this->e->notice( sprintf(
                    'Dropping %s.%s failed', $entity->getTableName(), $column ) );

                return false;
            }
        }

        return $this->clearInstantColumns();
    }

    /**
     * Add a column to owa_event without making it unswappable.
     *
     * Falls back to an ordinary add plus a rebuild where the server will not
     * take ALGORITHM=INPLACE -- the same rebuild, one more statement, and an
     * upgrade that keeps going rather than stopping dead.
     *
     * @param string $column
     * @return bool
     */
    private function addSwappableColumn( $column ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $table  = $entity->getTableName();

        $existing = (array) $db->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

        $added = $existing
            || $db->addColumnRebuilding( $table, $column, $entity->getColumnDefinition( $column ) );

        // addColumnIfMissing(), not addColumn(): the latter answers false for
        // "already there" as well as for "could not", which is how an upgrade
        // stops dead on a database that is already correct.
        if ( ! $added && $this->addColumnIfMissing( $entity, $column ) === false ) {

            $this->e->notice( sprintf( 'Adding %s.%s failed', $table, $column ) );

            return false;
        }

        return $this->clearInstantColumns();
    }

    /**
     * Rebuild owa_event, but only if it has instant-column history to clear.
     *
     * Asked rather than assumed, so the common path -- the column went in with
     * ALGORITHM=INPLACE and the table is already flat -- costs one query
     * instead of a second full rebuild.
     *
     * @return bool
     */
    private function clearInstantColumns() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getTableName();

        // null means the server does not report it. Rebuilding on a maybe would
        // spend a full table copy to answer a question nobody asked.
        if ( $db->hasInstantColumns( $table ) !== true ) {

            return true;
        }

        if ( $db->rebuildTable( $table ) === false ) {

            $this->e->notice( sprintf(
                'Rebuilding %s failed. The denormalisation pass cannot swap a partition '
              . 'into it until ALTER TABLE %s FORCE succeeds.', $table, $table ) );

            return false;
        }

        return true;
    }
}

?>
