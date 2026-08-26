<?php

namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\NotificationManager;

/**
 * GET v1/notifications -- what this user has not dismissed.
 *
 * The badge in the header renders its COUNT server-side, because it is on every
 * page and a request per page load to learn a small number would be worse than
 * the query it replaces. The list behind the badge is fetched here, once, when
 * someone actually opens it.
 *
 * Audience comes from the SESSION. There is no user parameter to pass, so
 * asking for someone else's notifications is not a request that can be
 * expressed.
 */
class NotificationsRest extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);

        /*
         * Capability set AFTER parent::__construct(), matching ReportsRest --
         * the browser calls that route on every report page, so it is the
         * working example of a REST route reached with a session rather than
         * an API key. Setting it before the parent runs makes the constructor
         * take the admin-page path and answer with an HTML error document
         * instead of the REST view.
         *
         * `view_site_list` is the gate, and the choice is not arbitrary:
         *
         *   - Without SOME capability the route answers ANONYMOUS callers --
         *     200 with the list, 202 to a DELETE. The edge refuses
         *     unauthenticated API calls on this deployment, which is what hid
         *     that; the application must not depend on it.
         *   - NOT `install_schema`: the `everyone` role holds it, so requiring
         *     it gates nothing.
         *   - NOT `view_reports`: it is in capabilitiesThatRequireSiteAccess,
         *     so it is checked against a SITE. A notification is not about a
         *     site, this request carries no siteId, and the check fails with
         *     "No access to any site" for a perfectly entitled user.
         *
         * `view_site_list` is held by viewer, analyst and admin, is not held by
         * `everyone`, and is not site-scoped -- which is exactly "any signed-in
         * user".
         */
        $this->setRequiredCapability('view_site_list');
    }


    function action() {

        $userId = \OWA\Core\CoreAPI::getCurrentUser()->getUserData('user_id');

        /*
         * Everything undismissed, not a page of it.
         *
         * The badge is the LENGTH of this list, so a page size here becomes a
         * wrong badge: with more undismissed than the page holds the count
         * sticks at the page size, dismissing one merely reveals the next, and
         * the number never moves -- the button looks broken. Bounded by
         * MAX_ROWS, which is a backstop and not a page.
         */
        $rows = NotificationManager::undismissedFor( $userId, NotificationManager::MAX_ROWS );

        $out = array();

        foreach ( $rows as $row ) {

            /*
             * Named explicitly rather than handing back the row: the table
             * carries source/source_key, which are dedupe machinery and not
             * something an API should promise to keep.
             *
             * An EXCERPT, not the body. The panel shows a headline and a hint
             * the way any social notification list does, and release notes run
             * to screenfuls of markdown -- sending all of them so the client
             * can throw most away is a page-load cost for nothing. Anyone who
             * wants the whole thing follows the link.
             */
            $out[] = array(
                'id'           => $row['id'],
                // Read notifications STAY in the list; they just stop being
                // bold and stop counting towards the badge.
                'read'         => ! empty( $row['read'] ),
                'type'         => ( $row['type'] ?? '' ) ?: NotificationManager::TYPE_GENERAL,
                'title'        => $row['title'],
                // Read, not computed: the column holds it. Falls back to
                // deriving one so a row written before this column existed
                // still shows something rather than a bare headline.
                'excerpt'      => ( $row['excerpt'] ?? '' ) !== ''
                                    ? $row['excerpt']
                                    : NotificationManager::excerpt( $row['body'] ?? '' ),
                'url'          => $row['url'],
                'published_at' => (int) $row['published_at'],
            );
        }

        $this->set( 'notifications', $out );
    }

    function success() {

        http_response_code(200);
        $this->setView( 'base.notificationsRest' );
    }
}
