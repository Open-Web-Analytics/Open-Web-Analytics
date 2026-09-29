<?php
namespace OWA\Module\Domstream\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The v2 recording tables: owa_domstream_chunk and owa_domstream_payload.
 *
 * For an install where the module was already installed at schema 1. A fresh
 * install creates both from the entities and records schema 2, so this does
 * not run there.
 *
 * Nothing is migrated: recordings in v1's owa_domstream are not re-encoded and
 * go when that table is dropped with the rest of v1.
 *
 * Idempotent both ways: up() creates what is missing, down() drops what is
 * there.
 */
class Update002 extends \OWA\Core\Update {

    var $schema_version = 2;

    var $is_cli_mode_required = false;

    const ENTITIES = array( 'domstream.domstream_chunk', 'domstream.domstream_payload' );

    function up( $force = false ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( self::ENTITIES as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( $db->tableExists( $entity->getTableName() ) ) {

                continue;
            }

            if ( ! $entity->createTable() ) {

                $this->e->notice( sprintf( 'Creating %s failed', $entity->getTableName() ) );

                return false;
            }
        }

        return true;
    }

    /** The two tables go, with every recording in them. */
    function down() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( self::ENTITIES as $name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $name )->getTableName();

            if ( $db->tableExists( $table ) && ! $db->dropTable( $table ) ) {

                $this->e->notice( sprintf( 'Dropping %s failed', $table ) );

                return false;
            }
        }

        return true;
    }
}

?>
