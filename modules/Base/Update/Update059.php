<?php
namespace OWA\Module\Base\Update;

/**
 * Create owa_migration_progress, where the v1 migration records how far it has
 * got and what it refused (PLAN.html 2.21, 2.22), and owa_migration_tally and
 * owa_migration_day_visitor, what each batch wrote, which reconciliation counts
 * against.
 *
 * NOT CLI-ONLY. It creates small empty tables; the migration that fills them
 * is its own blocking command.
 */
class Update059 extends \OWA\Core\Update {

    var $schema_version = 59;

    var $is_cli_mode_required = false;

    const ENTITIES = array( 'base.migration_progress', 'base.migration_tally', 'base.migration_day_visitor' );

    function up( $force = false ) {

        foreach ( self::ENTITIES as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( \OWA\Core\CoreAPI::dbSingleton()->tableExists( $entity->getTableName() ) ) {

                continue;
            }

            if ( $entity->createTable() === false ) {

                $this->e->notice( sprintf( 'Create table %s failed', $entity->getTableName() ) );

                return false;
            }
        }

        return true;
    }

    /** Drop them: the exact inverse. */
    function down() {

        foreach ( self::ENTITIES as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( ! \OWA\Core\CoreAPI::dbSingleton()->tableExists( $entity->getTableName() ) ) {

                continue;
            }

            if ( $entity->dropTable() === false ) {

                $this->e->notice( sprintf( 'Drop table %s failed', $entity->getTableName() ) );

                return false;
            }
        }

        return true;
    }
}

?>
