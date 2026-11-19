<?php
namespace OWA\Module\Base\Update;

/**
 * owa_event_raw and every cube keyed on (yyyymmdd, id); raw's site_date index
 * widened to (site_id, yyyymmdd, created_at).
 *
 * KEY. An id is a hash of the event, so on (id, yyyymmdd) each insert, and
 * each row a build reads, is at a random page of a month-sized partition. On
 * (yyyymmdd, id) a day's rows are contiguous (Entity\EventRaw). The cube
 * entity inherits raw's key, and its staging table is copied from the live
 * cube, so each cube is re-keyed here too.
 *
 * SITE_DATE. With created_at in it, Cube\Builder::isCurrent()'s test for rows
 * that arrived after a build reads the index and no rows. Raw only: the cube
 * has no created_at.
 *
 * Each table is changed only when it has the old shape, and down() changes
 * only one with the new shape. A 1.x upgrade creates raw at Update034 and its
 * cubes from the entity, both already in the new shape, so this changes
 * nothing there.
 *
 * COST. A new primary key rebuilds the table: every row of every partition is
 * copied and every secondary index rebuilt, needing free space the size of
 * the table while it runs. Raw's two changes are one ALTER and one rebuild;
 * each cube is one more. InnoDB does it in place, with ingest continuing, but
 * the time is in proportion to the rows, which is why this is CLI-only.
 */
class Update094 extends \OWA\Core\Update {

    public $schema_version = 94;

    /** @var bool */
    public $is_cli_mode_required = true;

    /*
     * Spelled out rather than read from the entity: an update is permanent and
     * a shape is not (see Update063). Columns in key order, comma-separated.
     */
    const OLD_KEY       = 'id,yyyymmdd';
    const NEW_KEY       = 'yyyymmdd,id';
    const OLD_SITE_DATE = 'site_id,yyyymmdd';
    const NEW_SITE_DATE = 'site_id,yyyymmdd,created_at';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $raw = $this->rawTable();

        if ( $raw ) {

            $clauses = [];

            if ( $this->indexColumns( $raw, 'PRIMARY' ) === self::OLD_KEY ) {

                $clauses[] = 'DROP PRIMARY KEY, ADD PRIMARY KEY (yyyymmdd, id)';
            }

            if ( $this->indexColumns( $raw, 'site_date' ) === self::OLD_SITE_DATE ) {

                $clauses[] = 'DROP INDEX site_date, ADD INDEX site_date (site_id, yyyymmdd, created_at)';
            }

            if ( ! $this->alter( $raw, $clauses ) ) {

                return false;
            }
        }

        foreach ( $this->cubeTables() as $cube ) {

            if ( $this->indexColumns( $cube, 'PRIMARY' ) === self::OLD_KEY
                 && ! $this->alter( $cube, [ 'DROP PRIMARY KEY, ADD PRIMARY KEY (yyyymmdd, id)' ] ) ) {

                return false;
            }
        }

        return true;
    }

    /** The exact inverse: the cubes, then raw, back to (id, yyyymmdd) and site_date (site_id, yyyymmdd). */
    public function down() {

        foreach ( $this->cubeTables() as $cube ) {

            if ( $this->indexColumns( $cube, 'PRIMARY' ) === self::NEW_KEY
                 && ! $this->alter( $cube, [ 'DROP PRIMARY KEY, ADD PRIMARY KEY (id, yyyymmdd)' ] ) ) {

                return false;
            }
        }

        $raw = $this->rawTable();

        if ( ! $raw ) {

            return true;
        }

        $clauses = [];

        if ( $this->indexColumns( $raw, 'PRIMARY' ) === self::NEW_KEY ) {

            $clauses[] = 'DROP PRIMARY KEY, ADD PRIMARY KEY (id, yyyymmdd)';
        }

        if ( $this->indexColumns( $raw, 'site_date' ) === self::NEW_SITE_DATE ) {

            $clauses[] = 'DROP INDEX site_date, ADD INDEX site_date (site_id, yyyymmdd)';
        }

        return $this->alter( $raw, $clauses );
    }

    /**
     * Every cube table. Overridden by a test, to name scratch tables.
     *
     * @return list<string>
     */
    protected function cubeTables() {

        return array_values( \OWA\Module\Base\Classes\Cube\Cubes::allTables() );
    }

    /** @return string|null raw's table, or null where it does not exist */
    private function rawTable() {

        $table = $this->tableFor( 'base.event_raw' );

        return \OWA\Core\CoreAPI::dbSingleton()->tableExists( $table ) ? $table : null;
    }

    /**
     * An index's columns in key order, comma-separated; '' when there is no such index.
     *
     * @param  string $table
     * @param  string $index
     * @return string
     */
    private function indexColumns( $table, $index ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS'
          . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [ $table, $index ] );

        return is_array( $row ) ? (string) ( $row['cols'] ?? '' ) : '';
    }

    /**
     * One ALTER carrying every clause, so the table is rebuilt once.
     *
     * @param  string       $table
     * @param  list<string> $clauses
     * @return bool
     */
    private function alter( $table, array $clauses ) {

        if ( ! $clauses ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query(
                sprintf( 'ALTER TABLE %s %s', $table, implode( ', ', $clauses ) ) ) === false ) {

            $this->e->notice( sprintf( 'Re-keying %s failed.', $table ) );

            return false;
        }

        return true;
    }
}
