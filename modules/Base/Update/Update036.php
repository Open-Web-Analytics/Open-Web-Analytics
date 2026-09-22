<?php

namespace OWA\Module\Base\Update;

/**
 * Add the referring host to owa_event_raw, the visitor store and owa_event.
 *
 * Parsed at ingest by V2Event::parseUrl(), beside host and target_host. The
 * build classifies it; it used to parse it too, in SQL, which was both wrong and
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
 * and a build fails every run, having published nothing.
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
 * `direct` in a build -- the same answer those rows already gave, since
 * nothing could read a host out of them either. The migrator fills it for
 * history, and a rebuild fills it for anything still in raw.
 */
class Update036 extends \OWA\Core\Update {

    use CubeColumn;

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

        return $this->addCubeColumn( 'referer_host' );
    }

    function down() {

        if ( $this->dropCubeColumn( 'referer_host' ) === false ) {

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
