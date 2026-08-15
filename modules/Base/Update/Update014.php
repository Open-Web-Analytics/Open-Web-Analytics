<?php
namespace OWA\Module\Base\Update;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Index visitor_id on the fact tables.
 *
 * The column is declared on FactTable and is filtered on -- a visitor-scoped
 * report reaches where('visitor_id', ...) in the REST controller -- but it never
 * had an index, unlike session_id and site_id alongside it. Every such request
 * scanned the largest tables in the schema:
 *
 *   SELECT ... FROM owa_request WHERE visitor_id = ?
 *   -> type=ALL  possible_keys=NULL  rows=617754
 *
 * FactTable now calls setIndex() on the column, which covers new installations.
 * This adds it to the ones already built.
 *
 * The fact tables come from the module's own entity registry, so the set stays
 * correct as entities are added, and the table names come from the entities
 * themselves rather than being spelled out here. addIndex() is idempotent as of
 * schema 13, so an entity that already has the index is left alone.
 */
class Update014 extends \OWA\Core\Update {

    var $schema_version = 14;

    function up($force = true) {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $s  = \OWA\Core\CoreAPI::serviceSingleton();

        $entities = $s->modules[ $this->module_name ]->getEntities();

        $added   = 0;
        $present = 0;
        $failed  = array();

        foreach ( $entities as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $this->module_name . '.' . $name );

            // Only the fact tables carry visitor_id. Asking the entity is what
            // keeps this correct as entities come and go.
            if ( ! $entity instanceof \OWA\Core\Entity\FactTable ) {

                continue;
            }

            // Built from the namespace and the registered name, as Update003
            // does, rather than read back off the entity.
            $table = $this->c->get( 'base', 'ns' ) . $name;

            if ( $db->indexExists( $table, 'visitor_id' ) ) {

                $present++;

                continue;
            }

            if ( $db->addIndex( $table, 'visitor_id' ) ) {

                $added++;

                \OWA\Core\CoreAPI::notice( sprintf( 'Indexed visitor_id on %s.', $table ) );

            } else {

                $failed[] = $table;
            }
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            'visitor_id: indexed %d table(s), %d already had one.', $added, $present
        ) );

        if ( $failed ) {

            // Report and carry on. A missing index is a slow query, not a
            // reason to fail the upgrade and strand the schema version.
            \OWA\Core\CoreAPI::notice( sprintf(
                'Could not index visitor_id on: %s. These can be indexed by hand.',
                implode( ', ', $failed )
            ) );
        }

        return true;
    }
}
