<?php

namespace OWA\Module\Base\Controller;

/**
 * Where reporting starts: the last Profile you were looking at.
 *
 * This replaces base.sites, which was a flat roster of every tracked site with
 * a thumbnail, trend metrics and five management links per row. Its navigation
 * job belongs to the site control now, and its five links belong to the tier
 * nav -- it was the last screen still offering the old flat route to them.
 *
 * Landing straight on a report is the honest version of what it was for: you
 * came here to look at something, and the roster was a step in the way.
 *
 * The Profile is remembered in a cookie, written by ReportController when a
 * report renders. Not a user column, because it is a per-browser convenience
 * rather than a property of the account -- two people sharing an account on two
 * machines should not fight over it, and it must not need a write to the user
 * table on every report view.
 */
class ReportingHome extends \OWA\Core\Controller {

    /** The cookie ReportController writes. Short, like auth's 'u' and 'p'. */
    const LAST_PROFILE_COOKIE = 'lp';

    function __construct( $params ) {

        parent::__construct( $params );

        /*
         * view_site_list, not view_reports. view_reports is satisfied against a
         * PARTICULAR site, and this action runs before one has been chosen --
         * choosing it is the whole job. view_site_list is the capability that
         * means "any signed-in user".
         */
        $this->setRequiredCapability( 'view_site_list' );
    }

    function action() {

        $allowed = (array) $this->getSitesAllowedForCurrentUser();

        $siteId = \OWA\Core\CoreAPI::getStateParam( self::LAST_PROFILE_COOKIE );

        /*
         * Checked against what this user may see, not just for presence. A
         * remembered Profile can have been deleted, or its grant revoked, or
         * the cookie can belong to whoever used this browser last -- and
         * redirecting to a Profile the viewer cannot open would answer a
         * capability failure instead of a report.
         */
        if ( ! $siteId || ! array_key_exists( $siteId, $allowed ) ) {

            $siteId = $this->resolveCurrentSiteId();
        }

        if ( ! $siteId ) {

            /*
             * No Profiles at all -- a fresh install before the first one is
             * created. There is no report to show, so send them where one is
             * made rather than to a dashboard of nothing.
             */
            $this->setRedirectAction( 'base.sitesProfile' );

            return;
        }

        $this->set( 'siteId', $siteId );
        $this->set( 'reportId', 'dashboard' );
        $this->setRedirectAction( 'base.report' );
    }
}

?>
