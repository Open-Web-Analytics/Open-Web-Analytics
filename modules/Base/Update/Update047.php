<?php

namespace OWA\Module\Base\Update;

/**
 * Add beacon_version: which tracker generation wrote the row.
 *
 * WHAT IT IS FOR. The bridges between an old beacon and the current one --
 * indexed in conf/beacon_compat.php -- can only be deleted on evidence that
 * nothing is still sending the old shape. Without this column that evidence
 * does not exist, and the index grows forever because no deletion can ever be
 * justified. With it the question is a query.
 *
 * It is NOT a contract and nothing consults it at ingest. Whether one beacon
 * can become a row is the identity guard in EventRawHandlers::row(), which
 * knows nothing of versions.
 *
 * WHY OWA NEEDS IT MORE THAN GA DOES. GA serves gtag.js itself with a
 * deliberately short cache TTL, so it can reason about its stale population
 * from a policy it controls. OWA hands a static file to the customer's own
 * origin -- currently with no Cache-Control at all, so browsers apply a
 * heuristic that grows with the file's age -- and then has no idea how long an
 * old tracker lives. Counting rows is the only way to know.
 *
 * NULL IS GENERATION 0, and nullable is load-bearing: every tracker cached on
 * the day this ships sends nothing. Nothing to backfill -- a stored row cannot
 * be made to say which tracker wrote it.
 *
 * NOT CLI-ONLY. One column on raw and the cube, as any release adding one.
 */
class Update047 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 47;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        return $this->addRawColumn( 'beacon_version' );
    }

    function down() {

        if ( $this->dropCubeColumn( 'beacon_version' ) === false ) {

            return false;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        if ( $this->dropColumnIfPresent( $raw, 'beacon_version' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.beacon_version failed', $raw->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
