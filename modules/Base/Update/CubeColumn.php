<?php

namespace OWA\Module\Base\Update;

/**
 * Adding a column to owa_event_raw is also adding it to the cube.
 *
 * The cube is raw's columns verbatim plus the ones a build derives -- the
 * entity inherits them -- so a raw column that reaches only raw leaves the two
 * tables disagreeing, and the build fails on the next run with "Unknown column
 * in field list". That has now happened twice, which is the argument for a
 * helper rather than a note.
 *
 * The two halves are not symmetrical, and that is the other reason this is
 * shared. Raw takes the column instantly, which costs nothing and is fine
 * because raw is never a swap target. The cube CANNOT: MySQL 8's instant
 * ADD COLUMN leaves row-format metadata that a CREATE TABLE ... LIKE staging
 * table can never have, and EXCHANGE PARTITION then refuses the pair with
 * error 1731, so every later build fails having published nothing.
 * ALGORITHM=INPLACE writes the column into every existing row instead -- a full
 * rebuild, online, once per release that adds one.
 */
trait CubeColumn {

    /**
     * Add a column to owa_event_raw and to the cube, each the way it needs.
     *
     * @param string $column
     * @return bool
     */
    protected function addRawColumn( $column ) {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->addColumnIfMissing( $raw, $column ) === false ) {

            $this->e->notice( sprintf(
                'Adding %s.%s failed', $raw->getTableName(), $column ) );

            return false;
        }

        return $this->addCubeColumn( $column );
    }

    /**
     * Add a column to the cube without making it unswappable.
     *
     * Falls back to an ordinary add plus a rebuild where the server will not
     * take ALGORITHM=INPLACE -- the same rebuild, one more statement, and an
     * upgrade that keeps going rather than stopping dead.
     *
     * @param string $column
     * @return bool
     */
    protected function addCubeColumn( $column ) {

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

        return $this->clearCubeInstantColumns();
    }

    /**
     * Rebuild the cube, but only if it has instant-column history to clear.
     *
     * Asked rather than assumed, so the common path -- the column went in with
     * ALGORITHM=INPLACE and the table is already flat -- costs one query
     * instead of a second full table copy.
     *
     * @return bool
     */
    protected function clearCubeInstantColumns() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getTableName();

        // null means the server does not report it. Rebuilding on a maybe would
        // spend a full table copy to answer a question nobody asked.
        if ( $db->hasInstantColumns( $table ) !== true ) {

            return true;
        }

        if ( $db->rebuildTable( $table ) === false ) {

            $this->e->notice( sprintf(
                'Rebuilding %s failed. A cube build cannot swap a partition into it '
              . 'until ALTER TABLE %s FORCE succeeds.', $table, $table ) );

            return false;
        }

        return true;
    }
}

?>
