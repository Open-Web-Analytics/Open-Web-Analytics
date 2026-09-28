<?php
namespace OWA\Module\Base\Update;

/**
 * Create owa_migration_progress, where the v1 migration records how far it has
 * got and what it refused (PLAN.html 2.21, 2.22).
 *
 * NOT CLI-ONLY. It creates one small empty table; the migration that fills it
 * is its own blocking command.
 */
class Update059 extends \OWA\Core\Update {

    var $schema_version = 59;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );

        if ( \OWA\Core\CoreAPI::dbSingleton()->tableExists( $entity->getTableName() ) ) {

            return true;
        }

        if ( $entity->createTable() === false ) {

            $this->e->notice( sprintf( 'Create table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }

    /** Drop it: the exact inverse. */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );

        if ( ! \OWA\Core\CoreAPI::dbSingleton()->tableExists( $entity->getTableName() ) ) {

            return true;
        }

        if ( $entity->dropTable() === false ) {

            $this->e->notice( sprintf( 'Drop table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
