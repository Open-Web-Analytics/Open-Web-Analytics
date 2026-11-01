<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * element_anchor, anchor_x and anchor_y arrive on owa_event_raw and every cube:
 * where on the clicked element a click landed, which the heatmap draws it by.
 *
 * Twelve bytes a row, all fixed width. Measured on MySQL 8.4 against an empty
 * cube of today's shape, the custom-dimension capacity stays at 36 with them.
 *
 * Clicks stored before this have no anchor and none can be derived: the element
 * was in the visitor's browser, not in the row. The heatmap draws those by
 * click_x and click_y when the click's window was as wide as the reader's.
 *
 * NOT CLI-ONLY. Raw takes the columns instantly; each cube is rebuilt in place
 * once, for all three (see the CubeColumn trait), which is online.
 */
class Update073 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 73;

    var $is_cli_mode_required = false;

    /** In the entity's order, which is the order ADD COLUMN appends them in. */
    const COLUMNS = array( 'element_anchor', 'anchor_x', 'anchor_y' );

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

    /** The exact inverse: the columns go from every cube and from raw, with what they held. */
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
