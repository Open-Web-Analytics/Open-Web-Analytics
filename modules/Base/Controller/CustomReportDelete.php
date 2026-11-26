<?php
namespace OWA\Module\Base\Controller;

/**
 * Deletes one custom report.
 *
 * Nonce-required. A delete reached by following a link is the case nonces exist
 * for, and this one destroys the only copy -- a custom report has no file to
 * fall back on the way a shipped report does.
 */
class CustomReportDelete extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_reports' );
        $this->setNonceRequired();

        /*
         * The Property the report is deleted on: the one the request names, else
         * the report's own (CustomReports::requestProperty()).
         */
        $propertyId = \OWA\Module\Base\Classes\CustomReports::requestProperty(
            (string) $this->getParam( 'customReportId' ), $this->getParam( 'propertyId' ), $this->getCurrentSiteId() );

        if ( $propertyId !== '' ) {

            $this->setParam( 'propertyId', $propertyId );
        }
    }

    public function validate() {

        $this->addValidation( 'customReportId', $this->getParam( 'customReportId' ), 'required' );
    }

    public function action() {

        $id = (string) $this->getParam( 'customReportId' );

        $propertyId = (string) $this->getParam( 'propertyId' );

        // One of another Property is not found, and is answered as one below.
        $report = \OWA\Module\Base\Classes\CustomReports::loadOf( $id, $propertyId );

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        /*
         * Authorised against the stored ROW. The id in the request only proves
         * which report was named; this proves the requester may destroy it.
         */
        $may = \OWA\Module\Base\Classes\CustomReports::mayEdit(
            $report,
            (string) $user->getUserData( 'user_id' ),
            (bool) $user->isCapable( 'edit_users' )
        );

        /*
         * RESET: the reader's own version of a shipped report goes, and they
         * are back on the shipped one (ReportOverrides).
         */
        $replaces = $report ? (string) ( $report['replaces'] ?? '' ) : '';

        if ( $may && $replaces !== '' ) {

            \OWA\Module\Base\Classes\CustomReports::delete( $id );

            $this->setStatusCode( 2517 );
            $this->set( 'reportId', $replaces );

            if ( $propertyId !== '' ) {

                $this->set( 'propertyId', $propertyId );
            }

            $this->setRedirectAction( 'base.report' );

            return;
        }

        if ( $may ) {

            \OWA\Module\Base\Classes\CustomReports::delete( $id );

            $this->setStatusCode( 2512 );

        } else {

            /*
             * A report somebody else owns, one of another Property, or one
             * that is already gone, are answered the same way: back to the roster, nothing destroyed.
             * Distinguishing them would tell a caller whether an id they are
             * not allowed to touch exists.
             */
            $this->setStatusCode( 2513 );
        }

        // Back to the roster of the same Property, which lists its reports.
        if ( $propertyId !== '' ) {

            $this->set( 'propertyId', $propertyId );
        }

        $this->setRedirectAction( 'base.customReports' );
    }

    public function errorAction() {

        $this->setRedirectAction( 'base.customReports' );
    }
}
