<?php
namespace OWA\Module\Base\Update;

/**
 * replaces and replaces_hash arrive on owa_custom_report: a row that is a
 * user's own version of a shipped report names the report it replaces, and
 * the hash of the shipped definition it was made from
 * (Classes\ReportOverrides).
 *
 * Rows stored before this are custom reports and replace nothing, which the
 * empty default says.
 *
 * Idempotent: a column already there is left alone. down() deletes the rows
 * that replace a report -- without the column they would turn into custom
 * reports on the roster, named after the reports they replaced -- then drops
 * both columns, and finds nothing to do when run again.
 *
 * NOT CLI-ONLY. Two columns appended to a table of tens of rows.
 */
class Update097 extends \OWA\Core\Update {

    public $schema_version = 97;

    /** In the entity's order, which is the order ADD COLUMN appends them in. */
    const COLUMNS = [ 'replaces', 'replaces_hash' ];

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        foreach ( self::COLUMNS as $column ) {

            if ( $this->addColumnIfMissing( $table, $column ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.%s failed', $table->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }

    /** The inverse: the overrides go, then the columns. */
    public function down() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $db    = \OWA\Core\CoreAPI::dbSingleton();

        if ( in_array( 'replaces', $db->listColumns( $table->getTableName() ), true ) ) {

            $db->query( sprintf( "DELETE FROM %s WHERE replaces IS NOT NULL AND replaces <> ''", $table->getTableName() ) );
        }

        foreach ( array_reverse( self::COLUMNS ) as $column ) {

            if ( $this->dropColumnIfPresent( $table, $column ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s.%s failed', $table->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}
