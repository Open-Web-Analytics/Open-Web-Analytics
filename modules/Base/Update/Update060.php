<?php
namespace OWA\Module\Base\Update;

/**
 * owa_visitor_acquisition: an ascending surrogate key, and visitor_id unique.
 *
 * Visitor ids are random now (Util.generateRandomGuid), and InnoDB orders a
 * table's rows by its primary key. Keyed on visitor_id, every new visitor
 * would land at a random page of a table that can outgrow the buffer pool. On
 * an AUTO_INCREMENT id new rows append, and the random inserts go to a unique
 * index on visitor_id whose entries are a fraction of a row's size.
 *
 * Every read and write already keys on visitor_id by name, and the unique
 * index keeps insert-if-absent a database guarantee.
 *
 * No released installation has this table -- it came with Update034, after
 * 1.14 -- so on an upgrade from 1.14 Update034 creates it in this shape from
 * the entity and this finds nothing to do.
 *
 * NOT CLI-ONLY: one ALTER, and the table is empty everywhere but a development
 * install.
 */
class Update060 extends \OWA\Core\Update {

    var $schema_version = 60;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $table = $this->table();

        if ( ! $table || $this->hasSurrogate( $table ) ) {

            return true;
        }

        return $this->alter( $table, sprintf(
            'ALTER TABLE %s DROP PRIMARY KEY, '
            . 'ADD COLUMN id BIGINT NOT NULL AUTO_INCREMENT FIRST, '
            . 'ADD PRIMARY KEY (id), '
            . 'ADD UNIQUE INDEX %s (visitor_id)',
            $table, \OWA\Module\Base\Entity\VisitorAcquisition::VISITOR_INDEX ) );
    }

    /** The exact inverse: visitor_id the primary key again, and nothing else. */
    function down() {

        $table = $this->table();

        if ( ! $table || ! $this->hasSurrogate( $table ) ) {

            return true;
        }

        // Dropping the column drops the primary key on it with it.
        return $this->alter( $table, sprintf(
            'ALTER TABLE %s DROP COLUMN id, '
            . 'DROP INDEX %s, '
            . 'ADD PRIMARY KEY (visitor_id)',
            $table, \OWA\Module\Base\Entity\VisitorAcquisition::VISITOR_INDEX ) );
    }

    /** @return string|null the table, or null where it does not exist */
    private function table() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();

        return \OWA\Core\CoreAPI::dbSingleton()->tableExists( $table ) ? $table : null;
    }

    private function hasSurrogate( $table ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf( "SHOW COLUMNS FROM %s LIKE 'id'", $table ) );
    }

    private function alter( $table, $sql ) {

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( $sql ) === false ) {

            $this->e->notice( sprintf( 'Re-keying %s failed.', $table ) );

            return false;
        }

        return true;
    }
}

?>
