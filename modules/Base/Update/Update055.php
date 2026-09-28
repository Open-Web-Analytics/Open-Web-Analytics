<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * event_type widens from VARCHAR(24) to VARCHAR(64), on raw and every cube.
 *
 * A site may name a custom event with up to 40 characters
 * (TrackingEventHelpers::CUSTOM_NAME_PATTERN), and the column held 24. The
 * entity layer trims an over-long value to its column, so a 25-40 character
 * name was stored cut while the row id was derived from the whole name: two
 * events sharing their first 24 characters reported as one, and a goal event
 * triggered by the full name never matched. The column is not truncatable any
 * more (Entity\EventRaw), so an over-long name is refused rather than altered.
 *
 * AN UPDATE, NOT A SCHEMA EDIT, because owa_event_raw is on master: Update034
 * shipped there in #1119, and an install deployed from master has the narrow
 * column. A fresh install creates the table from the entity at the new width,
 * and this is then a no-op.
 *
 * NOT CLI-ONLY. Widening a VARCHAR is in place, and rows already stored keep
 * whatever they were written with -- a name cut before this stays cut.
 */
class Update055 extends \OWA\Core\Update {

    var $schema_version = 55;

    var $is_cli_mode_required = false;

    const COLUMN = 'event_type';

    function up( $force = false ) {

        return $this->resize( OWA_DTD_VARCHAR64 );
    }

    /**
     * Back to VARCHAR(24).
     *
     * The exact inverse, and able to run because values longer than 24 are cut
     * to 24 first -- which is what the entity layer stored for such a name before
     * this update. Refusing instead would leave strict mode rejecting the MODIFY
     * on any install that recorded one long name.
     */
    function down() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->tables() as $table ) {

            $db->query( sprintf(
                'UPDATE %1$s SET %2$s = LEFT( %2$s, 24 ) WHERE CHAR_LENGTH( %2$s ) > 24',
                $table, self::COLUMN ) );
        }

        return $this->resize( OWA_DTD_VARCHAR24 );
    }

    /** Raw and every cube, whichever of them exist. */
    private function tables() {

        $tables = array(
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() );

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            $tables[] = $table;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        return array_values( array_filter( array_unique( $tables ),
            function ( $table ) use ( $db ) { return $db->tableExists( $table ); } ) );
    }

    private function resize( $type ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->tables() as $table ) {

            /*
             * THE WIDTH ONLY. A fresh install creates event_type from the entity,
             * which does not mark it NOT NULL; declaring it here would leave an
             * upgraded install and a fresh one with different columns, which the
             * upgrade cycle refuses.
             */
            if ( $db->modifyColumn( $table, self::COLUMN, $type ) === false ) {

                $this->e->notice( sprintf(
                    'Resizing %s.%s to %s failed', $table, self::COLUMN, $type ) );

                return false;
            }
        }

        return true;
    }
}

?>
