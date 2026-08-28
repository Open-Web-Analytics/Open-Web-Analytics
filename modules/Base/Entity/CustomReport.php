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
        $definition = new \OWA\Module\Base\Classes\DbColumn( 'definition', OWA_DTD_BLOB );
        $this->setProperty( $definition );

        $creation_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'creation_timestamp', OWA_DTD_INT );
        $this->setProperty( $creation_timestamp );

        $last_updated_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'last_updated_timestamp', OWA_DTD_INT );
        $this->setProperty( $last_updated_timestamp );
    }
}
