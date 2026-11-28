<?php

namespace OWA\Module\Base\Controller;

/**
 * Save an Observation Profile's tracking tag settings.
 *
 * Every fieldset in the `tracking_tag` group, at Profile scope: Base's, and
 * each active module's. Each field's Override switch decides what is stored,
 * through SettingsForm::saveScoped(), and the Profile's bundle is published
 * at once.
 */
class SitesEditTagSettings extends \OWA\Core\AdminController {

    // edit_sites, and access to the Property of the Profile the request names.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_sites' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'siteId', $this->getParam( 'siteId' ), 'required' );

        $this->addValidation( 'siteId', $this->getParam( 'siteId' ), 'entityExists', [
            'entity'   => 'base.site',
            'column'   => 'site_id',
            'errorMsg' => $this->getMsg( 3208 ),
        ] );

        // Each setting the screen would store, checked against its declaration.
        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ) as $set ) {

            foreach ( \OWA\Module\Base\Classes\SettingsForm::scopedProblems(
                          $set, 'profile', (string) $this->getParam( 'siteId' ),
                          $this->getParam( 'config' ), $this->getParam( 'override' ) ) as $name => $problem ) {

                $this->addValidationError( $name, $problem );
            }
        }
    }

    public function action() {

        $site_id = (string) $this->getParam( 'siteId' );
        $saved   = true;

        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ) as $set ) {

            $saved = \OWA\Module\Base\Classes\SettingsForm::saveScoped(
                $set, 'profile', $site_id,
                (array) $this->getParam( 'config' ), (array) $this->getParam( 'override' ) ) && $saved;
        }

        if ( $saved ) {

            $this->setStatusCode( 3201 );
        }

        // Queued: the scheduler republishes the bundle within a minute.
        \OWA\Module\Base\Classes\TrackerBundle::publishNow( $site_id );

        $this->set( 'siteId', $site_id );
        $this->setRedirectAction( 'base.sitesInvocation' );
    }

    public function errorAction() {

        $this->set( 'siteId', (string) $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.sitesInvocation' );
        $this->set( 'error_msg', implode( ' ', (array) $this->getValidationErrorMsgs() ) );
    }
}
