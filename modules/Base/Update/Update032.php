<?php

namespace OWA\Module\Base\Update;

/**
 * Record how a visitor was acquired, on the visitor.
 *
 * owa_visitor already holds when the first session happened -- first_session_id
 * and its date parts -- but nothing about where it came from. Acquisition was
 * therefore only answerable by joining back to that session, which no report
 * does, so first-touch attribution did not exist as a reportable thing. The
 * `original` attribution mode approximates it from a sliding 60-day window,
 * which is a different question wearing the same name.
 *
 * Five literal columns, written once by VisitorHandlers when the visitor row is
 * created. Literals rather than dimension foreign keys because a report reaching
 * them goes owa_request -> owa_visitor already, and ResultSetManager joins
 * dimensions with OWA_SQL_JOIN (INNER) -- a second hop to owa_source_dim would
 * be a second way for a fact to drop out of a report entirely instead of
 * rendering "(not set)". The Visitor entity carries the longer version of that
 * argument.
 *
 * SCHEMA ONLY. Existing visitors keep NULL acquisition until somebody runs
 * cmd=backfill-visitor-acquisition, and that is deliberate:
 *
 *   - NULL here is not a broken state. It is the same absence the reporting
 *     layer already renders as "(not set)", so an installation that never
 *     backfills is consistent, just less complete. Contrast Update031, which
 *     HAD to run automatically because leaving it undone would have left one
 *     column holding two different spellings of "unknown".
 *
 *   - The backfill joins owa_visitor to owa_session and four dimension tables.
 *     On a shared database instance that is somebody else's problem if it runs
 *     unannounced inside an upgrade. An operator choosing the moment is the
 *     whole point of it being a command.
 */
class Update032 extends \OWA\Core\Update {

    var $schema_version = 32;

    var $is_cli_mode_required = false;

    const COLUMNS = array(
        'first_session_source',
        'first_session_medium',
        'first_session_campaign',
        'first_session_ad',
        'first_session_search_terms',
    );

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor' );

        foreach ( self::COLUMNS as $column ) {

            if ( ! $this->addColumnIfMissing( $entity, $column ) ) {

                $this->e->notice( sprintf( 'Adding owa_visitor.%s failed', $column ) );

                return false;
            }
        }

        return true;
    }

    /**
     * Drop the five columns.
     *
     * The exact inverse: up() adds these columns and nothing else -- no index,
     * no data, no other table -- so removing them returns the schema to where it
     * started. What is lost is the acquisition recorded since the upgrade, which
     * cannot be reconstructed for visitors whose first session has since aged
     * out of owa_session; that is the cost of the rollback and not a reason to
     * leave the columns behind.
     *
     * Idempotent via dropColumnIfPresent(), so a rollback that stopped half way
     * can be run again.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor' );

        foreach ( self::COLUMNS as $column ) {

            if ( ! $this->dropColumnIfPresent( $entity, $column ) ) {

                $this->e->notice( sprintf( 'Dropping owa_visitor.%s failed', $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
