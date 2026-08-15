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
 * The column has always been declared on FactTable and has always been filtered
 * on -- a visitor-scoped report reaches where('visitor_id', ...) in the REST
 * controller -- but it never had an index, unlike session_id and site_id
 * alongside it. Every such request was a full scan of the largest tables in the
 * schema; on one installation that is 617k rows for owa_request and 279k for
 * owa_session, per request, with no index available at all:
 *
 *   SELECT ... FROM owa_request WHERE visitor_id = ?
 *   -> type=ALL  possible_keys=NULL  rows=617754
 *
 * FactTable now calls setIndex() on the column, which covers new installations.
 * This adds it to the ones already built.
 *
 * The tables are discovered from the schema rather than listed here, so a fact
 * table contributed by a module is covered too. addIndex() is idempotent as of
 * schema 13, so a table that already has the index is left alone.
 */
class Update014 extends \OWA\Core\Update {

    var $schema_version = 14;

    function up($force = true) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $tables = $db->getTablesWithColumn( 'visitor_id' );

        if ( ! $tables ) {

            \OWA\Core\CoreAPI::notice( 'No tables carry a visitor_id column.' );

            return true;
        }

        $added   = 0;
        $present = 0;
        $failed  = array();

        foreach ( $tables as $table ) {

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
