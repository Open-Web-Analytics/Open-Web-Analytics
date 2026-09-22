<?php

namespace OWA\Module\Base\Update;

/**
 * The visitor store gains `properties`, for user-scoped custom dimensions.
 *
 * A JSON column holding each user property and when it was set:
 *
 *   {"plan": {"v": "enterprise", "ts": 1790000000000000}}
 *
 * Chosen over a key-value visitor store on measurement (2.26.5): at 100k
 * visitors a key store is five times the rows, and pivoting it back makes a
 * build's join 2.2x slower -- 1,097ms to 2,382ms. This reuses the join acq_*
 * already makes.
 *
 * Empty until a site registers a user-scoped dimension and a tracker sets one,
 * so this adds storage and changes no behaviour.
 *
 * NOT CLI-ONLY, and safe to run on a live installation: the visitor store is
 * not a swap target, so it takes a column the way any ordinary table does and
 * needs none of the instant-column care owa_event does.
 */
class Update040 extends \OWA\Core\Update {

    var $schema_version = 40;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );

        if ( $this->addColumnIfMissing( $entity, 'properties' ) === false ) {

            $this->e->notice( sprintf(
                'Adding %s.properties failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }

    /**
     * Drop it.
     *
     * The exact inverse. What it holds is collected by a feature that goes away
     * with this update, and the acquisition columns beside it are untouched.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );

        if ( $this->dropColumnIfPresent( $entity, 'properties' ) === false ) {

            $this->e->notice( sprintf(
                'Dropping %s.properties failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
