<?php

namespace OWA\Module\Base\Update;

/**
 * Add event_seq: the event's position in its session, counted on the device.
 *
 * WHAT IT FIXES. `ts` is stamped at edge receipt and the cube's session window
 * sorted on it, so events ordered by ARRIVAL rather than by occurrence. A
 * beacon that lands late -- the unload beacon is the standing case -- sorted
 * after events that happened after it, which put is_exit on the wrong row and
 * mis-ordered any funnel spanning it.
 *
 * A counter rather than a client timestamp, because a device clock can be
 * wrong, skewed or set by hand while a counter is monotonic regardless. That is
 * the only property the sort needs.
 *
 * BOTH TABLES. The cube is raw's columns verbatim plus the derived ones, so a
 * column that reaches only raw leaves the two disagreeing and every later build
 * fails on "Unknown column in field list". The trait does each the way it needs
 * -- instant on raw, a rebuild on the cube.
 *
 * NULLABLE, and the sort coalesces it to 0. A tracker cached from before this
 * sends nothing, so every row of those sessions is NULL together and they keep
 * ordering by arrival exactly as they did. Nothing needs backfilling: the
 * position was never recorded and cannot be recovered from a stored row.
 *
 * A REBUILD IS WHAT APPLIES IT. The column only changes an answer once a build
 * has re-derived is_exit over rows that carry it, so an install wanting the
 * correction on existing data runs cmd=cube-rebuild. New data gets it on the
 * next scheduled build.
 *
 * NOT CLI-ONLY. The trait pins ALGORITHM=INPLACE, which is the same online
 * rebuild any release adding a cube column pays.
 */
class Update045 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 45;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return $this->addRawColumn( 'event_seq' );
    }

    function down() {

        if ( $this->dropCubeColumn( 'event_seq' ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, 'event_seq' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.event_seq failed', $raw->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
