<?php
namespace OWA\Module\Base\Update;

/**
 * owa_login_failure: failed sign-ins per user name and per client address
 * (Entity\LoginFailure, Classes\LoginThrottle).
 *
 * Idempotent: a table already there is left alone. down() drops it, with the
 * failures it holds, and finds nothing to drop when run again.
 *
 * NOT CLI-ONLY. An empty table.
 */
class Update099 extends \OWA\Core\Update {

    public $schema_version = 99;

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.login_failure' );

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

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.login_failure' );

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
