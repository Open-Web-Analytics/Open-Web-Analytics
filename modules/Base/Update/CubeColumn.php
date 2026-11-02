<?php

namespace OWA\Module\Base\Update;

/**
 * Adding a column to owa_event_raw is also adding it to every cube.
 *
 * EVERY cube: there is one per Property (Classes\Cube\Cubes), so a release
 * that adds a column adds it N times. That is the cost the split was accepted
 * with, and having one place do the loop is what keeps it from being a note in
 * every future update.
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

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $existing = (array) $db->get_results( sprintf(
                "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

            $added = $existing
                || $db->addColumnRebuilding( $table, $column, $entity->getColumnDefinition( $column ) );

            // addColumnIfMissing(), not addColumn(): the latter answers false for
            // "already there" as well as for "could not", which is how an upgrade
            // stops dead on a database that is already correct.
            if ( ! $added
              && $this->addColumnIfMissing( $this->cubeEntity( $table ), $column ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.%s failed', $table, $column ) );

                return false;
            }
        }

        return $this->clearCubeInstantColumns();
    }

    /**
     * Drop a column from every cube.
     *
     * The inverse of addCubeColumn(), and the reason a down() cannot just walk
     * a list of entity names any more: there is no entity per cube, only the
     * one shape bound to each table in turn.
     *
     * Rebuilding, like the add. A plain DROP is instant on MySQL 8.0.29+ and
     * leaves a row version behind, which is 1731 at the next swap; that was
     * every down() here until clearCubeInstantColumns() had an answer to act
     * on. The plain drop stays as the fallback, with the rebuild after it.
     *
     * @param string $column
     * @return bool
     */
    protected function dropCubeColumn( $column ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $present = (array) $db->get_results( sprintf(
                "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

            if ( ! $present || $db->alterColumnsRebuilding( $table, array(), array( $column ) ) ) {

                continue;
            }

            if ( $this->dropColumnIfPresent( $this->cubeEntity( $table ), $column ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s.%s failed', $table, $column ) );

                return false;
            }
        }

        return $this->clearCubeInstantColumns();
    }

    /**
     * Add several columns to every cube, in ONE rebuild per cube.
     *
     * The rebuild is the cost, not the clause count (Db::alterColumnsRebuilding),
     * so three columns added one at a time cost three full copies of every cube.
     * Only the columns a cube lacks are added, so a second run adds nothing.
     * Where the server will not take the combined ALTER, each column goes the
     * way addCubeColumn() takes it.
     *
     * @param string[] $columns in the entity's order
     * @return bool
     */
    protected function addCubeColumns( array $columns ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $add = array();

            foreach ( $columns as $column ) {

                if ( ! $db->get_results( sprintf( "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) ) ) {

                    $add[ $column ] = $entity->getColumnDefinition( $column );
                }
            }

            if ( ! $add || $db->alterColumnsRebuilding( $table, $add ) ) {

                continue;
            }

            foreach ( array_keys( $add ) as $column ) {

                if ( $this->addColumnIfMissing( $this->cubeEntity( $table ), $column ) === false ) {

                    $this->e->notice( sprintf( 'Adding %s.%s failed', $table, $column ) );

                    return false;
                }
            }
        }

        return $this->clearCubeInstantColumns();
    }

    /**
     * Drop several columns from every cube, in ONE rebuild per cube: the
     * inverse of addCubeColumns().
     *
     * @param string[] $columns
     * @return bool
     */
    protected function dropCubeColumns( array $columns ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $drop = array();

            foreach ( $columns as $column ) {

                if ( $db->get_results( sprintf( "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) ) ) {

                    $drop[] = $column;
                }
            }

            if ( ! $drop || $db->alterColumnsRebuilding( $table, array(), $drop ) ) {

                continue;
            }

            foreach ( $drop as $column ) {

                if ( $this->dropColumnIfPresent( $this->cubeEntity( $table ), $column ) === false ) {

                    $this->e->notice( sprintf( 'Dropping %s.%s failed', $table, $column ) );

                    return false;
                }
            }
        }

        return $this->clearCubeInstantColumns();
    }

    /**
     * The cube shape, bound to one existing cube table.
     *
     * @param string $table
     * @return \OWA\Module\Base\Entity\Event
     */
    protected function cubeEntity( $table ) {

        $property_id = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdFor( $table );

        return $property_id === ''
            ? \OWA\Module\Base\Classes\Cube\Cubes::preSplitEntity()
            : \OWA\Module\Base\Classes\Cube\Cubes::entityFor( $property_id );
    }

    /**
     * Rebuild any cube that has instant-column history to clear.
     *
     * Asked rather than assumed, so the common path -- the column went in with
     * ALGORITHM=INPLACE and the table is already flat -- costs one query per
     * cube instead of a second full table copy.
     *
     * @return bool
     */
    protected function clearCubeInstantColumns() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            // null means the server does not report it. Rebuilding on a maybe would
            // spend a full table copy to answer a question nobody asked.
            if ( $db->hasInstantColumns( $table ) !== true ) {

                continue;
            }

            if ( $db->rebuildTable( $table ) === false ) {

                $this->e->notice( sprintf(
                    'Rebuilding %s failed. A cube build cannot swap a partition into it '
                  . 'until ALTER TABLE %s FORCE succeeds.', $table, $table ) );

                return false;
            }
        }

        return true;
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
    protected function restoreColumn( $column, $type ) {

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
    protected function dropColumns( array $columns ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        foreach ( $columns as $column ) {

            /*
             * BY NAME, not through the entity -- the same reason restoreColumn()
             * spells its types out.
             *
             * dropColumnIfPresent() and the methods above both ask the entity
             * for the column, and the release dropping it has removed its
             * declaration. Before
             * Entity::getColumn() refused an undeclared name this returned null
             * with a warning and the DDL happened to work anyway, so it passed
             * on a database where the column was already gone and failed on one
             * where it was not -- which is to say, on every real upgrade and no
             * fresh install. The schema upgrade cycle is the only job that runs
             * it.
             */
            foreach ( array_merge( \OWA\Module\Base\Classes\Cube\Cubes::allTables(),
                      array( $raw ) ) as $table ) {

                $existing = (array) $db->get_results( sprintf(
                    "SHOW COLUMNS FROM %s LIKE '%s'", $table, $column ) );

                if ( ! $existing ) {

                    continue;
                }

                // A cube is dropped from by a rebuild: a plain DROP is instant
                // on MySQL 8.0.29+ and the row version it leaves is 1731 at the
                // next swap. Raw is never swapped, so it takes the plain drop.
                if ( $table !== $raw
                  && $db->alterColumnsRebuilding( $table, array(), array( $column ) ) ) {

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
