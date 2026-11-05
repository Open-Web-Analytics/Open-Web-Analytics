<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * value arrives on owa_event_raw and every cube: what a goal event is worth, in
 * minor units of its currency (Entity\EventRaw, Classes\GoalMarking). The Goal
 * Value metric sums it.
 *
 * Eight bytes a row, NULL on every row but a goal event.
 *
 * Nothing is backfilled. The goal events stored before this carry no value,
 * and a definition's value today is not necessarily what it was when they were
 * stored.
 *
 * NOT CLI-ONLY. Raw takes the column instantly; each cube is rebuilt in place
 * once (see the CubeColumn trait), which is online.
 */
class Update077 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 77;

    var $is_cli_mode_required = false;

    const COLUMNS = array( 'value' );

    function up( $force = false ) {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( self::COLUMNS as $column ) {

            if ( $this->addColumnIfMissing( $raw, $column ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.%s failed', $raw->getTableName(), $column ) );

                return false;
            }
        }

        return $this->addCubeColumns( self::COLUMNS );
    }

    /** The exact inverse: the column goes from every cube and from raw, with what it held. */
    function down() {

        if ( $this->dropCubeColumns( self::COLUMNS ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( self::COLUMNS as $column ) {

            if ( $this->dropColumnIfPresent( $raw, $column ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s.%s failed', $raw->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
