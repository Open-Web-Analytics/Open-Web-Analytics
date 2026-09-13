<?php

namespace OWA\Module\Base\Entity;

/**
 * Custom Report Entity
 *
 * A report definition authored by a user, stored instead of shipped.
 *
 * The `definition` column holds exactly the same JSON a report in
 * modules/Base/reports/ holds, and it is rendered by the same
 * Core\ConfiguredReport. That is not a coincidence -- the format was built for
 * it. The renderer is fixed rather than named by the definition, formatters are
 * selected by NAME and never carried as code, and excludeColumns is a list of
 * names rather than a fragment of script. Each of those was a deliberate
 * narrowing so that one day a definition could come from a user; this is that
 * day, and none of them may be relaxed.
 *
 * NO site_id, deliberately. A custom report is site-agnostic like every other
 * report: the site filter in the report chrome chooses at view time, so one
 * report works across every site its reader can see. Recording a site here
 * would make the filter either decorative or a contradiction.
 *
 * `user_id` is the CREATOR, and it is what the roster filters on -- a
 * non-admin sees their own reports listed. It does not gate VIEWING: a report
 * reached by its URL renders for anyone with view_reports on the site being
 * looked at, which is what makes the URL shareable. The report can show nothing
 * its reader could not already query for themselves.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @since       owa 1.8.0
 */

class CustomReport extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('custom_report');

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        // What the roster shows, and what the report renders as its title.
        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        // The creator. Matches owa_user.user_id, which is the id every other
        // per-user table in this schema uses -- notification_state included.
        $user_id = new \OWA\Module\Base\Classes\DbColumn( 'user_id', OWA_DTD_VARCHAR255 );
        $this->setProperty( $user_id );

        /*
         * The definition, as JSON.
         *
         * BLOB rather than VARCHAR: ten widgets, each with its own metrics,
         * dimensions, constraints and sort, runs past 64KB less often than it
         * runs past 255 bytes, and a definition silently truncated at a column
         * boundary is invalid JSON that reports as a broken report.
         */
        /*
         * 'report' or 'visualization'. Falsy reads as 'report'.
         *
         * A report CONFIGURES a query: metrics against dimensions, drawn by one
         * of the widget types. A visualization COMPUTES -- a funnel counts
         * ordered stages over the event stream, which no arrangement of metrics
         * and dimensions expresses. That distinction is why goal-funnel and
         * domstreams kept controllers when 62 of 64 reports became JSON.
         *
         * Two types on one table rather than two tables, because everything
         * around them is identical: access control, ownership, editable titles,
         * the roster, delete. Only how they are drawn differs.
         *
         * They are NOT shown together, though -- the reporting nav gives
         * visualizations their own group. A list mixing "Pages by source" with
         * "Checkout funnel" invites the reader to expect the same knobs on
         * both, and they do not have them.
         */
        $report_type = new \OWA\Module\Base\Classes\DbColumn( 'report_type', OWA_DTD_VARCHAR255 );
        $report_type->setIndex();
        $this->setProperty( $report_type );

        /*
         * WHICH visualization, for the ones that are. Empty on a report.
         *
         * Resolves to a controller: a visualization brings its own data path,
         * so the type names the code that computes it. 'funnel' is the only one
         * today, and the vocabulary is a map rather than a switch so the second
         * one needs no dispatcher change.
         */
        $visualization_type = new \OWA\Module\Base\Classes\DbColumn( 'visualization_type', OWA_DTD_VARCHAR255 );
        $this->setProperty( $visualization_type );

        /*
         * Whether this one is listed to everybody, or only to the person who
         * made it.
         *
         * DISCOVERABILITY, not access. A custom report opened by its URL
         * already renders for anyone with view_reports -- that is what makes
         * the link shareable, and it is safe because a custom report can show
         * nothing its reader could not query for themselves. What ownership
         * governs is the ROSTER, so this flag governs the roster too.
         *
         * NULL on every row written before this column existed, which reads as
         * not shared -- the state those rows were already in. Nothing is
         * backfilled, for the same reason report_type is not.
         */
        $is_shared = new \OWA\Module\Base\Classes\DbColumn( 'is_shared', OWA_DTD_TINYINT );
        $is_shared->setIndex();
        $this->setProperty( $is_shared );

        $definition = new \OWA\Module\Base\Classes\DbColumn( 'definition', OWA_DTD_BLOB );
        $this->setProperty( $definition );

        $creation_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'creation_timestamp', OWA_DTD_INT );
        $this->setProperty( $creation_timestamp );

        $last_updated_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'last_updated_timestamp', OWA_DTD_INT );
        $this->setProperty( $last_updated_timestamp );
    }

    /** What a report is. Falsy reads as a report. */
    const TYPE_REPORT        = 'report';
    const TYPE_VISUALIZATION = 'visualization';

    /** @return string */
    public function reportType() {

        return $this->get( 'report_type' ) === self::TYPE_VISUALIZATION
            ? self::TYPE_VISUALIZATION : self::TYPE_REPORT;
    }

    /** @return bool */
    public function isVisualization() {

        return $this->reportType() === self::TYPE_VISUALIZATION;
    }

    /**
     * Is this one listed to everybody?
     *
     * Falsy reads as private, which is what NULL means on every row that
     * predates the column -- the same shape as reportType() above.
     *
     * @return bool
     */
    public function isShared() {

        return (bool) $this->get( 'is_shared' );
    }
}
