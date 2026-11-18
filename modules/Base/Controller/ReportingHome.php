<?php

namespace OWA\Module\Base\Controller;

/**
 * Where reporting starts: the last Property you were looking at.
 *
 * The Property is remembered in a cookie, written by ReportController when a
 * report renders. Not a user column, because it is a per-browser convenience
 * rather than a property of the account -- two people sharing an account on two
 * machines should not fight over it, and it must not need a write to the user
 * table on every report view.
 */
class ReportingHome extends \OWA\Core\AdminController {

    /**
     * The cookie ReportController writes. Short, like auth's 'u' and 'p'.
     *
     * Not 'lp', which holds a Profile's site id on older installs: no Property
     * id equals one, so it would only ever be ignored.
     */
    const LAST_PROPERTY_COOKIE = 'lpr';

    public function __construct( $params ) {

        parent::__construct( $params );

        /*
         * view_site_list, not view_reports. view_reports is satisfied against a
         * PARTICULAR site, and this action runs before one has been chosen --
         * choosing it is the whole job. view_site_list is the capability that
         * means "any signed-in user".
         */
        $this->setRequiredCapability( 'view_site_list' );
    }

    public function action() {

        $allowed = [];

        foreach ( (array) $this->getSitesAllowedForCurrentUser() as $site ) {

            $propertyId = (string) $site->get( 'property_id' );

            if ( $propertyId !== '' && $propertyId !== '0' ) {

                $allowed[] = $propertyId;
            }
        }

        $propertyId = (string) \OWA\Core\CoreAPI::getStateParam( self::LAST_PROPERTY_COOKIE );

        /*
         * Checked against what this user may see, not just for presence. A
         * remembered Property can have been deleted, or its grant revoked, or
         * the cookie can belong to whoever used this browser last -- and
         * redirecting to a Property the viewer cannot open would answer a
         * capability failure instead of a report.
         */
        if ( $propertyId === '' || ! in_array( $propertyId, $allowed, true ) ) {

            $propertyId = $allowed ? $allowed[0] : '';
        }

        if ( $propertyId === '' ) {

            /*
             * No Profiles at all -- a fresh install before the first one is
             * created. There is no report to show, so send them where one is
             * made rather than to a dashboard of nothing.
             */
            $this->setRedirectAction( 'base.sitesProfile' );

            return;
        }

        $this->set( 'propertyId', $propertyId );
        $this->set( 'reportId', 'dashboard' );
        $this->setRedirectAction( 'base.report' );
    }
}
