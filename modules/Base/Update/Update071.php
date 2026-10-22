<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * profile_name arrives on every cube: the Profile Name dimension.
 *
 * Cube only. The build copies it from the Profile row (owa_site.name), so raw
 * has nothing to carry. A fresh install creates the cube from the entity with
 * the column already there, and this is then a no-op.
 *
 * Existing rows hold NULL until their partitions are rebuilt:
 *
 *   php cli.php cmd=cube-rebuild from=<first day> to=<today>
 *
 * NOT CLI-ONLY. Each cube is rebuilt in place (see the CubeColumn trait), which
 * is online.
 */
class Update071 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 71;

    var $is_cli_mode_required = false;

    const COLUMN = 'profile_name';

    function up( $force = false ) {

        return $this->addCubeColumn( self::COLUMN );
    }

    /** The exact inverse: the column goes from every cube, with what it held. */
    function down() {

        return $this->dropCubeColumn( self::COLUMN );
    }
}

?>
