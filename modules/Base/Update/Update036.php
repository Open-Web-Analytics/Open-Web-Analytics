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
 * All three tables are ALTERed. owa_event is then REBUILT, and that second
 * step is not optional.
 *
 * MySQL 8 adds a column instantly by default, which leaves the table carrying
 * row-format metadata a freshly created staging table does not have. The two
 * stop being byte-compatible and EXCHANGE PARTITION refuses them:
 *
 *   1731 Non matching attribute 'INSTANT COLUMN(s)' between partition and table
 *
 * The pass then fails every run, having published nothing. ALTER TABLE ... FORCE
 * rewrites the table and clears it; REBUILD PARTITION does not, measured.
 *
 * The rebuild keeps every row. Dropping and recreating owa_event would also
 * work and would be cheaper to write, but it throws away every partition and
 * makes the next upgrade re-derive the whole history from raw -- which grows
 * with the table and is impossible at all once raw has been pruned.
 *
 * Rows written before this hold NULL in referer_host, which resolves to
 * `direct` in the pass -- the same answer those rows already gave, since
 * nothing could read a host out of them either. The migrator fills it for
 * history, and a rebuild fills it for anything still in raw.
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
            // owa_event carries every column owa_event_raw does, in the same
            // order, because EXCHANGE PARTITION compares the two tables.
            'base.event'               => 'referer_host',
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

        return $this->clearInstantColumns();
    }

    /**
     * Rewrite owa_event so it carries no instant-column metadata.
     *
     * See the note above. Idempotent: a table with nothing to clear is rebuilt
     * anyway and comes out the same, which is what makes this safe to re-run.
     *
     * @return bool
     */
    private function clearInstantColumns() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getTableName();

        if ( \OWA\Core\CoreAPI::dbSingleton()->rebuildTable( $table ) === false ) {

            $this->e->notice( sprintf(
                'Rebuilding %s failed. The denormalisation pass cannot swap a partition '
              . 'into it until ALTER TABLE %s FORCE succeeds.', $table, $table ) );

            return false;
        }

        return true;
    }

    function down() {

        foreach ( array_reverse( $this->columns(), true ) as $name => $column ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $this->dropColumnIfPresent( $entity, $column ) === false ) {

                $this->e->notice( sprintf(
                    'Dropping %s.%s failed', $entity->getTableName(), $column ) );

                return false;
            }
        }

        // Dropping a column is never instant, but the rebuild is cheap
        // insurance that the table is swappable whichever way it got here.
        return $this->clearInstantColumns();
    }
}

?>
