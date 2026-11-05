<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * spam_score and spam_rules arrive on owa_event_raw: the beacon's spam score
 * and the rules behind it (Classes\Spam\Score).
 *
 * RAW ONLY, so the CubeColumn trait is not used: the cube entity drops both
 * (Entity\Event), and no cube changes. Cube\Dimensions measures the custom
 * dimensions' room against a Property's cube, so these move nothing there.
 *
 * Rows stored before this hold NULL in both, which is how a row that was never
 * scored differs from one that scored 0.0.
 *
 * Idempotent: a column already there is left alone. down() drops both, with
 * what they hold, and finds nothing to drop when run again.
 *
 * NOT CLI-ONLY. Two nullable columns appended to raw.
 */
class Update082 extends \OWA\Core\Update {

    var $schema_version = 82;

    var $is_cli_mode_required = false;

    /** In the entity's order, which is the order ADD COLUMN appends them in. */
    const COLUMNS = array( 'spam_score', 'spam_rules' );

    function up( $force = false ) {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( self::COLUMNS as $column ) {

            if ( $this->addColumnIfMissing( $raw, $column ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.%s failed', $raw->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }

    /** The exact inverse: the columns go, with what they held. */
    function down() {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( array_reverse( self::COLUMNS ) as $column ) {

            if ( $this->dropColumnIfPresent( $raw, $column ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s.%s failed', $raw->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
