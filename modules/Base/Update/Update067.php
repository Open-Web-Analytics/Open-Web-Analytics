<?php
namespace OWA\Module\Base\Update;

/**
 * owa_job_queue: one-off admin jobs, run by the scheduler.
 */
class Update067 extends \OWA\Core\Update {

    public $schema_version = 67;

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

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

    public function down() {

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
