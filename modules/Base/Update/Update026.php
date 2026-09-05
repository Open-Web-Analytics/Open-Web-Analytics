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

        foreach ( array( 'report_type', 'visualization_type' ) as $column ) {

            /*
             * Skipped when it is already there, rather than treated as a
             * failure -- see Update::addColumnIfMissing() for why that is the
             * ordinary case and not a re-run guard.
             */
            if ( ! $this->addColumnIfMissing( $entity, $column ) ) {

                $this->e->notice( "Adding $column to owa_custom_report failed" );

                return false;
            }
        }

        return true;
    }

    /**
     * Take the type columns back off owa_custom_report.
     *
     * Idempotent. VISUALIZATIONS BECOME REPORTS by doing this -- a row whose
     * report_type said "visualization" loses the only thing distinguishing it,
     * and the roster will list it as an ordinary custom report whose definition
     * holds steps no widget renderer understands.
     *
     * Stated rather than guarded against: a rollback to a schema that has no
     * visualizations cannot keep them, and the alternative is refusing to roll
     * back at all.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        foreach ( array( 'visualization_type', 'report_type' ) as $column ) {

            if ( ! $this->dropColumnIfPresent( $entity, $column ) ) {

                $this->e->notice( "Dropping $column from owa_custom_report failed" );

                return false;
            }
        }

        return true;
    }
}
