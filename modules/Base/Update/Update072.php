<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * page_depth, previous_page_path and next_page_path arrive on every cube: the
 * Page Depth, Previous Page Path and Next Page Path dimensions.
 *
 * Cube only. The build derives them from the session's page views, so raw has
 * nothing to carry. A fresh install creates the cube from the entity with the
 * columns already there, and this is then a no-op.
 *
 * Existing rows hold NULL until their partitions are rebuilt:
 *
 *   php cli.php cmd=cube-rebuild from=<first day> to=<today>
 *
 * NOT CLI-ONLY. Each cube is rebuilt in place (see the CubeColumn trait), which
 * is online.
 */
class Update072 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 72;

    var $is_cli_mode_required = false;

    /** In the entity's order, which is the order ADD COLUMN appends them in. */
    const COLUMNS = array( 'page_depth', 'previous_page_path', 'next_page_path' );

    /** One rebuild per cube for all three; see CubeColumn::addCubeColumns(). */
    function up( $force = false ) {

        return $this->addCubeColumns( self::COLUMNS );
    }

    /** The exact inverse: the columns go from every cube, with what they held. */
    function down() {

        return $this->dropCubeColumns( self::COLUMNS );
    }
}

?>
