<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_spam_block: beacons spam detection blocked, per day, Profile and rule
 * (Entity\SpamBlock, Classes\Spam\Blocks).
 *
 * Idempotent: a table already there is left alone. down() drops it, with the
 * counts it holds, and finds nothing to drop when run again.
 */
class Update083 extends \OWA\Core\Update {

    var $schema_version = 83;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.spam_block' );

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

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.spam_block' );

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
