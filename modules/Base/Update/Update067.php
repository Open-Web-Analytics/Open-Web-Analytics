<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_job_queue: one-off admin jobs, run by the scheduler (PLAN 2.30.5).
 */
class Update067 extends \OWA\Core\Update {

    var $schema_version = 67;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.job_queue' );

        if ( \OWA\Core\CoreAPI::dbSingleton()->tableExists( $entity->getTableName() ) ) {

            return true;
        }

        if ( $entity->createTable() === false ) {

            $this->e->notice( sprintf( 'Create table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }

    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.job_queue' );

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
