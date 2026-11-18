<?php
namespace OWA\Module\Base\Update;

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

    public $schema_version = 71;

    const COLUMN = 'profile_name';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        return $this->addCubeColumn( self::COLUMN );
    }

    /** The exact inverse: the column goes from every cube, with what it held. */
    public function down() {

        return $this->dropCubeColumn( self::COLUMN );
    }
}
