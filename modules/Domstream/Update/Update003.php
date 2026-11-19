<?php
namespace OWA\Module\Domstream\Update;

/**
 * owa_domstream_chunk and owa_domstream_payload keyed on (yyyymmdd, id).
 *
 * A chunk's id is a hash of its site, recording and seq, so on (id, yyyymmdd)
 * each write lands at a random page of a month-sized partition. On
 * (yyyymmdd, id) a day's chunks are contiguous, as Base's Update094 makes
 * owa_event_raw's rows.
 *
 * Each table is changed only when it has the old key, and down() changes only
 * one with the new key. A table that does not exist is left alone. A fresh
 * install, and an install at schema 1 that Update002 brings to 2, creates both
 * tables from the entities in the new shape, so this changes nothing there.
 *
 * COST. A new primary key rebuilds the table: every row of every partition is
 * copied, needing free space the size of the table while it runs. InnoDB does
 * it in place, with ingest continuing, but the time is in proportion to the
 * rows, which is why this is CLI-only.
 */
class Update003 extends \OWA\Core\Update {

    public $schema_version = 3;

    /** @var bool */
    public $is_cli_mode_required = true;

    const ENTITIES = [ 'domstream.domstream_chunk', 'domstream.domstream_payload' ];

    /*
     * Spelled out rather than read from the entity: an update is permanent and
     * a shape is not. Columns in key order, comma-separated.
     */
    const OLD_KEY = 'id,yyyymmdd';
    const NEW_KEY = 'yyyymmdd,id';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        foreach ( $this->existingTables() as $table ) {

            if ( $this->keyOf( $table ) === self::OLD_KEY
                 && ! $this->rekey( $table, 'DROP PRIMARY KEY, ADD PRIMARY KEY (yyyymmdd, id)' ) ) {

                return false;
            }
        }

        return true;
    }

    /** The exact inverse, in reverse order: back to (id, yyyymmdd). */
    public function down() {

        foreach ( array_reverse( $this->existingTables() ) as $table ) {

            if ( $this->keyOf( $table ) === self::NEW_KEY
                 && ! $this->rekey( $table, 'DROP PRIMARY KEY, ADD PRIMARY KEY (id, yyyymmdd)' ) ) {

                return false;
            }
        }

        return true;
    }

    /** @return list<string> the chunk table, then the payload table, where each exists */
    private function existingTables() {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $out = [];

        foreach ( self::ENTITIES as $name ) {

            $table = $this->tableFor( $name );

            if ( $db->tableExists( $table ) ) {

                $out[] = $table;
            }
        }

        return $out;
    }

    /**
     * The primary key's columns in key order, comma-separated; '' without one.
     *
     * @param  string $table
     * @return string
     */
    private function keyOf( $table ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS'
          . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'", [ $table ] );

        return is_array( $row ) ? (string) ( $row['cols'] ?? '' ) : '';
    }

    /**
     * @param  string $table
     * @param  string $clauses
     * @return bool
     */
    private function rekey( $table, $clauses ) {

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s %s', $table, $clauses ) ) === false ) {

            $this->e->notice( sprintf( 'Re-keying %s failed.', $table ) );

            return false;
        }

        return true;
    }
}
