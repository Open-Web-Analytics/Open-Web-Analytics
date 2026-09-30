<?php

namespace OWA\Module\Base\Update;

/**
 * Add channel and acq_channel to every cube.
 *
 * Google Analytics' default channel group, for the session and for the visit
 * that acquired the user (Classes\Cube\ChannelStep). Cube columns, not raw
 * ones: a channel is a READING of the source, medium and campaign, and a
 * reading belongs where a rebuild can re-apply it.
 *
 * THE SAME RELEASE CHANGES WHAT source, medium AND campaign HOLD, to GA's
 * values: (direct) / (none) for a direct visit, organic and referral rather
 * than organic-search and social-network, and a campaign placeholder for an
 * untagged visit. A partition takes the new values when it is next built, so
 * an installation that already has cubes rebuilds them to read one vocabulary
 * across its history:
 *
 *   php cli.php cmd=cube-rebuild from=<first day> to=<today>
 *
 * An installation upgrading from 1.x has no cubes yet; its first build is the
 * whole of its history.
 *
 * EXISTING ROWS HOLD '' UNTIL THAT REBUILD, as with Update044: ADD COLUMN ...
 * NOT NULL backfills '' on a populated table rather than refusing.
 *
 * NOT CLI-ONLY. The trait rebuilds each cube in place, online.
 */
class Update065 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 65;

    var $is_cli_mode_required = false;

    const COLUMNS = array( 'channel', 'acq_channel' );

    function up( $force = false ) {

        foreach ( self::COLUMNS as $column ) {

            if ( $this->addCubeColumn( $column ) === false ) {

                return false;
            }
        }

        return true;
    }

    /** The exact inverse: both columns go, and nothing else moved. */
    function down() {

        foreach ( array_reverse( self::COLUMNS ) as $column ) {

            if ( $this->dropCubeColumn( $column ) === false ) {

                return false;
            }
        }

        return true;
    }
}

?>
