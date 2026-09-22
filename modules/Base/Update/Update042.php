<?php

namespace OWA\Module\Base\Update;

/**
 * Create owa_custom_dimension: the registry of promoted keys.
 *
 * A site can already set any event or user property it likes and ingest stores
 * them all, as JSON. What has been missing is the step that makes one
 * QUERYABLE: registering it, which adds a real column to that Property's cube
 * for a build to fill.
 *
 * The table is empty, and its contents are one installation's choices rather
 * than a release's schema -- so registering a dimension after this does NOT
 * move required_schema_version, and there is no Update class per dimension.
 * What a release owns is this table; what an operator owns is its rows and the
 * columns they imply.
 *
 * NOT CLI-ONLY. It creates one small table and changes no behaviour.
 */
class Update042 extends \OWA\Core\Update {

    var $schema_version = 42;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' );

        if ( $entity->createTable() === false ) {

            $this->e->notice( sprintf( 'Create table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }

    /**
     * Drop it.
     *
     * The exact inverse of creating it, and no more: the cd_ columns a
     * registration added to a cube are deliberately left where they are. They
     * are inert without this table -- a build fills the columns the registry
     * names and ignores the rest -- and dropping them would mean a schema
     * rollback silently discarding collected data that re-registering and
     * rebuilding would otherwise bring straight back.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' );

        if ( $entity->dropTable() === false ) {

            $this->e->notice( sprintf( 'Drop table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
