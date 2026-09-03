<?php

namespace OWA\Module\Base\Update;

/**
 * Custom reports gain a type, and visualizations become one of them.
 *
 * A report CONFIGURES a query -- metrics against dimensions, drawn by one of
 * the widget types. A visualization COMPUTES: a funnel counts ordered stages
 * over the event stream, which no arrangement of metrics and dimensions
 * expresses. That is exactly why goal-funnel and domstreams kept controllers
 * when 62 of 64 reports became JSON.
 *
 * Both live on owa_custom_report because everything around them is identical --
 * ownership, access control, editable titles, the roster, delete. Only how they
 * are drawn differs, and the reporting nav lists them separately so nobody
 * expects a visualization to have a widget's controls.
 *
 * Nothing is backfilled. Every existing row is a report, which is what a NULL
 * report_type already means -- see CustomReport::reportType(), where falsy reads
 * as 'report'. The roster matches reports as "not visualization" for the same
 * reason: = 'report' would exclude every row written before this.
 */
class Update026 extends \OWA\Core\Update {

    var $schema_version = 26;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( array( 'report_type', 'visualization_type' ) as $column ) {

            /*
             * Skipped when it is already there, rather than treated as a
             * failure.
             *
             * addColumn() answers false both for "could not" and for "already
             * exists", and a migration that stops on the second never completes
             * -- so the schema version is never written, and every subsequent
             * request refuses with "OWA Updates required" while the database is
             * in fact correct. Measured here, on this install.
             */
            /*
             * Interpolated, not bound: SHOW COLUMNS takes no parameters, and
             * both values here are hardcoded -- the table from the entity and
             * the column from the literal list above. Nothing from a request
             * reaches this.
             */
            $existing = (array) $db->get_results( sprintf(
                "SHOW COLUMNS FROM %s LIKE '%s'",
                $entity->getTableName(),
                $column ) );

            if ( $existing ) {

                continue;
            }

            if ( $entity->addColumn( $column ) === false ) {

                $this->e->notice( "Adding $column to owa_custom_report failed" );

                return false;
            }
        }

        return true;
    }

    function down() {

        return true;
    }
}
