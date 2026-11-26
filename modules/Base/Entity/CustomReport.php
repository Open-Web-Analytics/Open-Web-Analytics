<?php

namespace OWA\Module\Base\Entity;

/**
 * Custom Report Entity
 *
 * A report definition authored by a user, stored instead of shipped.
 *
 * The `definition` column holds exactly the same JSON a report in
 * modules/Base/reports/ holds, and it is rendered by the same
 * Core\ConfiguredReport. The renderer is fixed rather than named by the
 * definition, formatters are selected by NAME and never carried as code, and
 * excludeColumns is a list of names rather than a fragment of script -- which
 * is what makes a definition safe to take from a user. None of them may be
 * relaxed.
 *
 * OF ONE PROPERTY (`property_id`), as reporting is: it is listed, opened,
 * edited and drawn on that Property alone, and its names are checked against
 * that Property's dimensions (Classes\DimensionRegistry), custom ones
 * included. A report is drawn for every Profile of its Property; the Profile
 * dimension is how one breaks a Profile out. The shipped reports in
 * modules/Base/reports/ are the install's and belong to no Property.
 *
 * `user_id` is the CREATOR, and it is what the roster filters on -- a
 * non-admin sees their own reports listed. It does not gate VIEWING: a report
 * reached by its URL renders for anyone with view_reports on its Property,
 * which is what makes the URL shareable. The report can show nothing its
 * reader could not already query for themselves.
 */

class CustomReport extends \OWA\Core\Entity {

    public function __construct() {

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
         * The Property it belongs to: owa_property.id, set when it is made, to
         * the Property its author was on, and never changed. Nullable only for
         * Update081, which adds it and assigns every row made before it.
         */
        $property_id = new \OWA\Module\Base\Classes\DbColumn( 'property_id', OWA_DTD_BIGINT );
        $property_id->setIndex();
        $this->setProperty( $property_id );

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
         * and dimensions expresses, so a visualization has a controller of its
         * own.
         *
         * Two types on one table rather than two tables, because everything
         * around them is identical: access control, ownership, editable titles,
         * the roster, delete. Only how they are drawn differs.
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
         * NULL reads as not shared.
         */
        $is_shared = new \OWA\Module\Base\Classes\DbColumn( 'is_shared', OWA_DTD_TINYINT );
        $is_shared->setIndex();
        $this->setProperty( $is_shared );

        $definition = new \OWA\Module\Base\Classes\DbColumn( 'definition', OWA_DTD_BLOB );
        $this->setProperty( $definition );

        /*
         * The definition as 1.14 saved it, where Update061 rewrote it into
         * v2's names. NULL for every report written on v2. Kept so nothing a
         * person built is lost to the rewrite, and so down() can restore it.
         */
        $v1_definition = new \OWA\Module\Base\Classes\DbColumn( 'v1_definition', OWA_DTD_BLOB );
        $this->setProperty( $v1_definition );

        $creation_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'creation_timestamp', OWA_DTD_INT );
        $this->setProperty( $creation_timestamp );

        $last_updated_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'last_updated_timestamp', OWA_DTD_INT );
        $this->setProperty( $last_updated_timestamp );

        /*
         * A user's own version of a SHIPPED report (Classes\ReportOverrides):
         * the report id it replaces, for its author, on its Property. Empty
         * for every other row. Such a row is not on the roster; it is drawn
         * in the shipped report's place.
         *
         * Last, with replaces_hash, in the order Update097 appends them.
         */
        $replaces = new \OWA\Module\Base\Classes\DbColumn( 'replaces', OWA_DTD_VARCHAR255 );
        $this->setProperty( $replaces );

        /*
         * The SHA-256 of the shipped definition when the override was saved,
         * so the report can say when the default has changed since.
         */
        $replaces_hash = new \OWA\Module\Base\Classes\DbColumn( 'replaces_hash', OWA_DTD_VARCHAR255 );
        $this->setProperty( $replaces_hash );
    }

    /** What a report is. Falsy reads as a report. */
    const TYPE_REPORT        = 'report';
    const TYPE_VISUALIZATION = 'visualization';

    /**
     * Is this one listed to everybody?
     *
     * Falsy reads as private, which is what NULL means on every row that
     * predates the column.
     *
     * @return bool
     */
    public function isShared() {

        return (bool) $this->get( 'is_shared' );
    }
}
