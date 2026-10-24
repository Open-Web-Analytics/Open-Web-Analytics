<?php

namespace OWA\Module\Base\Controller;

/**
 * Star or unstar a custom report, and go back to it, or to the roster when
 * the star was pressed there (returnTo=roster).
 *
 * ONE ACTION, NOT TWO. The star is a toggle in the interface, so it is a toggle
 * here: a separate favourite/unfavourite pair would need the caller to know the
 * current state to pick between them, and a caller that got it wrong would
 * silently do the opposite of what was clicked.
 *
 * NAMED FOR THE WRITE IT DOES. A nonce-guarded controller has to carry a write
 * verb in its name -- LoginRedirectNonceTest enforces it -- because a name
 * carrying "Report" without one reads as a screen that SHOWS a report, and a
 * report screen requiring a nonce loses its post-login redirect.
 *
 * REQUIRES ONLY view_reports, not edit_reports. A favourite is the reader's
 * note about where a report sits in their own list -- starring somebody else's
 * report changes nothing about it, and a reader who may look at a report but
 * never author one is exactly who this is for.
 */
class CustomReportMarkFavorite extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';
        $this->setRequiredCapability( 'view_reports' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'customReportId', $this->getParam( 'customReportId' ), 'required' );
    }

    function action() {

        $id     = (string) $this->getParam( 'customReportId' );
        $report = \OWA\Module\Base\Classes\CustomReports::load( $id );

        /*
         * The report has to exist, and that is the whole check.
         *
         * Not "and you must be allowed to see it": a custom report renders for
         * anyone with view_reports by design, so there is no narrower set to
         * test against. Refusing a star on a report the reader can open would
         * be inventing a permission that does not exist anywhere else.
         */
        if ( $report ) {

            \OWA\Module\Base\Classes\CustomReportFavorites::toggle(
                $id, (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' ) );
        }

        $propertyId = (string) $this->getParam( 'propertyId' );

        if ( $propertyId !== '' ) {

            $this->set( 'propertyId', $propertyId );
        }

        /*
         * Back to where the star was pressed, since the answer to "did that
         * work" is the star itself having changed: the roster, with its order
         * and filter, or the report.
         */
        if ( $this->getParam( 'returnTo' ) === 'roster' ) {

            foreach ( array( 'rosterSort', 'rosterDesc', 'rosterMine', 'rosterFavorites' ) as $key ) {

                $value = (string) $this->getParam( $key );

                if ( $value !== '' ) {

                    $this->set( $key, $value );
                }
            }

            $this->setRedirectAction( 'base.customReports' );

            return;
        }

        $this->set( 'reportId', Report::CUSTOM_PREFIX . $id );

        $this->setRedirectAction( 'base.report' );
    }

    function errorAction() {

        $this->setRedirectAction( 'base.customReports' );
    }
}
