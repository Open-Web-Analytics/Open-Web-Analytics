<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * screen_resolution arrives on raw and every cube.
 *
 * The tracker sends the device's screen as WIDTHxHEIGHT on every event, and
 * the screenResolution dimension reads it.
 *
 * AN UPDATE, NOT A SCHEMA EDIT, because owa_event_raw is on master (Update034,
 * #1119). A fresh install creates both tables from the entity with the column
 * already there, and this is then a no-op.
 *
 * NOT CLI-ONLY. Raw takes the column instantly; each cube is rebuilt in place
 * (see the CubeColumn trait), which is online.
 */
class Update058 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 58;

    var $is_cli_mode_required = false;

    const COLUMN = 'screen_resolution';

    function up( $force = false ) {

        // Raw and every cube, each the way it needs -- see the CubeColumn trait.
        return $this->addRawColumn( self::COLUMN );
    }

    /**
     * The column goes from every cube and from raw, with what it held.
     *
     * Dropped rather than kept because a rollback puts back a registry that
     * does not declare it and a row builder that does not fill it.
     * Idempotent: a table already without it is left alone.
     */
    function down() {

        if ( $this->dropCubeColumn( self::COLUMN ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, self::COLUMN ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.%s failed', $raw->getTableName(), self::COLUMN ) );

            return false;
        }

        return true;
    }
}

?>
